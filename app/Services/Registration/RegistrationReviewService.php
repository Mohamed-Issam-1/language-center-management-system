<?php

namespace App\Services\Registration;

use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

class RegistrationReviewService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function selectRole(
        User $actor,
        RegistrationRequest $request,
        SystemRole $targetRole
    ): RegistrationRequest {
        return DB::transaction(
            function () use (
                $actor,
                $request,
                $targetRole
            ): RegistrationRequest {
                $actor = $this->lockPersistedActor(
                    $actor
                );

                $request = $this->lockPersistedRequest(
                    $request
                );

                $this->ensurePending(
                    $request
                );

                $currentRole =
                    $this->selectedSystemRole(
                        $request
                    );

                /*
                 * If another reviewer has already classified this
                 * request, the current actor must also be allowed
                 * to manage that existing classification.
                 *
                 * This prevents a Center Owner from taking over a
                 * Center Owner request classified by the Platform
                 * Owner, and prevents a Platform Owner from taking
                 * over Staff/Student requests.
                 */
                if ($currentRole !== null) {
                    $this->authorizeRoleManagement(
                        $actor,
                        $request,
                        $currentRole
                    );
                }

                $this->authorizeRoleManagement(
                    $actor,
                    $request,
                    $targetRole
                );

                if ($currentRole === $targetRole) {
                    return $request->refresh();
                }

                $roleRecord =
                    $this->roleRecord(
                        $targetRole
                    );

                $beforeValues =
                    $this->auditValues(
                        $request
                    );

                /*
                 * Role changes always clear the selected Branch.
                 *
                 * Even when both the previous and new roles require
                 * a Branch, the reviewer must explicitly reconfirm
                 * the Branch for the new role.
                 */
                $request->forceFill([
                    'selected_role_id' =>
                    $roleRecord->id,

                    'selected_branch_id' =>
                    null,
                ])->save();

                $request->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'registration_request.role_selected',
                    subject: $request,
                    beforeValues: $beforeValues,
                    afterValues: $this->auditValues(
                        $request
                    )
                );

                return $request;
            },
            3
        );
    }

    public function selectBranch(
        User $actor,
        RegistrationRequest $request,
        Branch $branch
    ): RegistrationRequest {
        return DB::transaction(
            function () use (
                $actor,
                $request,
                $branch
            ): RegistrationRequest {
                $actor =
                    $this->lockPersistedActor(
                        $actor
                    );

                $request =
                    $this->lockPersistedRequest(
                        $request
                    );

                $this->ensurePending(
                    $request
                );

                $selectedRole =
                    $this->selectedSystemRole(
                        $request
                    );

                if ($selectedRole === null) {
                    throw new DomainException(
                        'A System Role must be selected before selecting a Branch.'
                    );
                }

                $this->authorizeRoleManagement(
                    $actor,
                    $request,
                    $selectedRole
                );

                if (
                    ! $this->roleRequiresBranch(
                        $selectedRole
                    )
                ) {
                    throw new DomainException(
                        sprintf(
                            '%s registration does not use a Branch assignment.',
                            $selectedRole->label()
                        )
                    );
                }

                $branch =
                    $this->lockPersistedBranch(
                        $branch,
                        $request->center_id
                    );

                if (
                    $branch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Registration Request may select only an active Branch.'
                    );
                }

                if (
                    $actor->systemRole()
                    === SystemRole::BranchManager
                ) {
                    $this->ensureBranchManagerOwnsBranch(
                        $actor,
                        $request,
                        $branch->id
                    );
                }

                if (
                    $request->selected_branch_id
                    === $branch->id
                ) {
                    return $request->refresh();
                }

                $beforeValues =
                    $this->auditValues(
                        $request
                    );

                $request->forceFill([
                    'selected_branch_id' =>
                    $branch->id,
                ])->save();

                $request->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'registration_request.branch_selected',
                    subject: $request,
                    beforeValues: $beforeValues,
                    afterValues: $this->auditValues(
                        $request
                    )
                );

                return $request;
            },
            3
        );
    }

    public function reject(
        User $actor,
        RegistrationRequest $request,
        string $reason
    ): RegistrationRequest {
        return DB::transaction(
            function () use (
                $actor,
                $request,
                $reason
            ): RegistrationRequest {
                $actor =
                    $this->lockPersistedActor(
                        $actor
                    );

                $request =
                    $this->lockPersistedRequest(
                        $request
                    );

                $this->ensurePending(
                    $request
                );

                $reason = trim(
                    $reason
                );

                if ($reason === '') {
                    throw new DomainException(
                        'A rejection reason is required.'
                    );
                }

                $selectedRole =
                    $this->selectedSystemRole(
                        $request
                    );

                if ($selectedRole === null) {
                    /*
                     * Before a Role is selected, only the Center
                     * Owner may reject the request during identity
                     * validation.
                     */
                    $this->authorizeUnclassifiedRejection(
                        $actor,
                        $request
                    );
                } else {
                    $this->authorizeRoleManagement(
                        $actor,
                        $request,
                        $selectedRole
                    );

                    /*
                     * A Branch Manager must never reject a Student
                     * request until that request is explicitly
                     * scoped to the Manager's own active Branch.
                     */
                    if (
                        $actor->systemRole()
                        === SystemRole::BranchManager
                    ) {
                        if (
                            $request->selected_branch_id
                            === null
                        ) {
                            throw new AuthorizationException(
                                'A Branch Manager may reject only Student requests assigned to the Manager\'s active Branch.'
                            );
                        }

                        $this->ensureBranchManagerOwnsBranch(
                            $actor,
                            $request,
                            $request->selected_branch_id
                        );
                    }
                }

                $beforeValues =
                    $this->auditValues(
                        $request
                    );

                $request->forceFill([
                    'status' =>
                    RegistrationRequestStatus::Rejected,

                    'reviewed_by_user_id' =>
                    $actor->id,

                    'reviewed_at' =>
                    now(),

                    'rejection_reason' =>
                    $reason,

                    'pending_marker' =>
                    null,
                ])->save();

                $request->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'registration_request.rejected',
                    subject: $request,
                    beforeValues: $beforeValues,
                    afterValues: $this->auditValues(
                        $request
                    )
                );

                return $request;
            },
            3
        );
    }

    private function authorizeRoleManagement(
        User $actor,
        RegistrationRequest $request,
        SystemRole $targetRole
    ): void {
        $actorRole =
            $actor->systemRole();

        if ($actorRole === null) {
            throw new AuthorizationException(
                'The authenticated account does not have a valid System Role.'
            );
        }

        if (
            $targetRole
            === SystemRole::PlatformOwner
        ) {
            throw new AuthorizationException(
                'Platform Owner is not a valid target for a Center Registration Request.'
            );
        }

        if (
            $actorRole
            === SystemRole::PlatformOwner
        ) {
            $this->ensurePlatformScope(
                $actor
            );

            Gate::forUser($actor)
                ->authorize(
                    SystemPermission::ManageCenterOwnerAccounts
                        ->value
                );

            if (
                $targetRole
                !== SystemRole::CenterOwner
            ) {
                throw new AuthorizationException(
                    'Platform Owner may review only Center Owner registration requests.'
                );
            }

            return;
        }

        $this->ensureCenterScope(
            $actor,
            $request
        );

        if (
            $actorRole
            === SystemRole::CenterOwner
        ) {
            if (
                $targetRole
                === SystemRole::Student
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStudentAccounts
                            ->value
                    );

                return;
            }

            if (
                in_array(
                    $targetRole,
                    [
                        SystemRole::BranchManager,
                        SystemRole::FinanceEmployee,
                        SystemRole::Teacher,
                    ],
                    true
                )
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStaffAccounts
                            ->value
                    );

                return;
            }

            throw new AuthorizationException(
                'Center Owner cannot review a registration request for this System Role.'
            );
        }

        if (
            $actorRole
            === SystemRole::BranchManager
        ) {
            Gate::forUser($actor)
                ->authorize(
                    SystemPermission::ManageStudentAccounts
                        ->value
                );

            if (
                $targetRole
                !== SystemRole::Student
            ) {
                throw new AuthorizationException(
                    'Branch Manager may review only Student registration requests.'
                );
            }

            $this->ensureBranchManagerOwnsBranch(
                $actor,
                $request
            );

            return;
        }

        throw new AuthorizationException(
            'The authenticated account cannot review Registration Requests.'
        );
    }

    private function authorizeUnclassifiedRejection(
        User $actor,
        RegistrationRequest $request
    ): void {
        $this->ensureCenterScope(
            $actor,
            $request
        );

        if (
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            throw new AuthorizationException(
                'Only the Center Owner may reject an unclassified Registration Request.'
            );
        }

        /*
         * The Center Owner must possess both account-management
         * capabilities because no target Role has been selected yet.
         */
        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ManageStaffAccounts
                    ->value
            );

        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ManageStudentAccounts
                    ->value
            );
    }

    private function ensurePlatformScope(
        User $actor
    ): void {
        if (
            ! $this->tenant->isEstablished()
            || ! $this->tenant->isPlatformScoped()
        ) {
            throw new AuthorizationException(
                'Platform Owner Registration review requires platform tenant scope.'
            );
        }

        if (
            $actor->center_id !== null
            || $actor->person_id !== null
        ) {
            throw new AuthorizationException(
                'Invalid Platform Owner account scope.'
            );
        }
    }

    private function ensureCenterScope(
        User $actor,
        RegistrationRequest $request
    ): void {
        if (
            ! $this->tenant->isEstablished()
            || ! $this->tenant->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Center Registration review requires center tenant scope.'
            );
        }

        $centerId =
            $this->tenant->centerId();

        if ($centerId === null) {
            throw new LogicException(
                'The established Center tenant context does not contain a Center identifier.'
            );
        }

        if (
            $actor->center_id !== $centerId
            || $actor->person_id === null
            || $request->center_id !== $centerId
        ) {
            throw new AuthorizationException(
                'Authenticated account, Registration Request, and tenant Center do not match.'
            );
        }
    }

    private function ensureBranchManagerOwnsBranch(
        User $actor,
        RegistrationRequest $request,
        ?int $requiredBranchId = null
    ): void {
        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'Branch-scoped Registration review requires a Branch Manager account.'
            );
        }

        $assignment =
            $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $request->center_id
            )
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw new AuthorizationException(
                'The Branch Manager does not have an active Branch assignment.'
            );
        }

        if (
            $requiredBranchId !== null
            && $assignment->branch_id
            !== $requiredBranchId
        ) {
            throw new AuthorizationException(
                'The Registration Request is outside the Branch Manager\'s active Branch.'
            );
        }
    }

    private function ensurePending(
        RegistrationRequest $request
    ): void {
        if (
            $request->status
            !== RegistrationRequestStatus::Pending
        ) {
            throw new DomainException(
                'Only Pending Registration Requests may be reviewed.'
            );
        }
    }

    private function roleRequiresBranch(
        SystemRole $role
    ): bool {
        return in_array(
            $role,
            [
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Student,
            ],
            true
        );
    }

    private function lockPersistedActor(
        User $actor
    ): User {
        $actorId =
            $this->numericModelId(
                $actor,
                'review actor'
            );

        $persisted =
            User::withoutGlobalScopes()
            ->with('role')
            ->whereKey($actorId)
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new InvalidArgumentException(
                'The Registration review actor no longer exists.'
            );
        }

        if (
            $persisted->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active User Account may review Registration Requests.'
            );
        }

        return $persisted;
    }

    private function lockPersistedRequest(
        RegistrationRequest $request
    ): RegistrationRequest {
        $requestId =
            $this->numericModelId(
                $request,
                'Registration Request'
            );

        $persisted =
            RegistrationRequest::withoutGlobalScopes()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new InvalidArgumentException(
                'The Registration Request no longer exists.'
            );
        }

        return $persisted;
    }

    private function lockPersistedBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        $branchId =
            $this->numericModelId(
                $branch,
                'Branch'
            );

        $persisted =
            Branch::withoutGlobalScopes()
            ->whereKey($branchId)
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The selected Branch does not belong to the Registration Request Center.'
            );
        }

        return $persisted;
    }

    private function roleRecord(
        SystemRole $role
    ): Role {
        $record =
            Role::query()
            ->where(
                'code',
                $role->value
            )
            ->first();

        if ($record === null) {
            throw new LogicException(
                sprintf(
                    'The fixed System Role "%s" has not been seeded.',
                    $role->value
                )
            );
        }

        return $record;
    }

    private function selectedSystemRole(
        RegistrationRequest $request
    ): ?SystemRole {
        if (
            $request->selected_role_id
            === null
        ) {
            return null;
        }

        $role =
            Role::query()
            ->find(
                $request->selected_role_id
            );

        if ($role === null) {
            throw new LogicException(
                'The Registration Request references a missing System Role.'
            );
        }

        $systemRole =
            SystemRole::tryFrom(
                $role->code
            );

        if ($systemRole === null) {
            throw new LogicException(
                'The Registration Request references an unknown System Role.'
            );
        }

        return $systemRole;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(
        RegistrationRequest $request
    ): array {
        return [
            'status' =>
            $request->status->value,

            'selected_role' =>
            $this->selectedSystemRole(
                $request
            )?->value,

            'selected_branch_id' =>
            $request->selected_branch_id,

            'reviewed_by_user_id' =>
            $request->reviewed_by_user_id,

            'reviewed_at' =>
            $request->reviewed_at,

            'rejection_reason' =>
            $request->rejection_reason,

            'pending_marker' =>
            $request->pending_marker,
        ];
    }

    private function numericModelId(
        \Illuminate\Database\Eloquent\Model $model,
        string $label
    ): int {
        if (
            ! $model->exists
            || $model->getKey() === null
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must be persisted.',
                    $label
                )
            );
        }

        $key = $model->getKey();

        if (
            ! is_int($key)
            && ! (
                is_string($key)
                && ctype_digit($key)
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must use a numeric identifier.',
                    $label
                )
            );
        }

        return (int) $key;
    }
}
