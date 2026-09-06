<?php

namespace App\Services\Classrooms;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClassroomManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $user,
        Branch $branch,
        array $attributes
    ): Classroom {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $branch,
                $centerId,
                $attributes
            ): Classroom {
                /*
                 * Re-read and lock the persisted Branch so creation
                 * cannot depend on stale or locally modified model
                 * state and cannot race with Branch deactivation.
                 */
                $lockedBranch = $this->lockBranchInCurrentTenant(
                    $branch,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'create',
                        [
                            Classroom::class,
                            $lockedBranch,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $user,
                    $centerId,
                    $lockedBranch->id
                );

                /*
                 * A deactivated Branch must not receive new
                 * operational records.
                 */
                if (
                    $lockedBranch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A classroom cannot be created in a deactivated branch.'
                    );
                }

                $data = Arr::only(
                    $attributes,
                    [
                        'name',
                        'code',
                        'capacity',
                        'location',
                        'availability_status',
                        'status',
                    ]
                );

                /*
                 * Tenant and Branch ownership are derived from the
                 * authorized persisted Branch and never trusted
                 * from request input.
                 */
                $data['center_id'] = $centerId;
                $data['branch_id'] = $lockedBranch->id;

                $classroom = Classroom::query()
                    ->create(
                        $data
                    );

                $this->audit->record(
                    actor: $user,
                    actionType: 'classroom.created',
                    subject: $classroom,
                    afterValues: $this->classroomAuditValues(
                        $classroom
                    )
                );

                return $classroom->refresh();
            },
            3
        );
    }

    public function update(
        User $user,
        Classroom $classroom,
        array $attributes
    ): Classroom {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $classroom,
                $attributes,
                $centerId
            ): Classroom {
                $classroom = $this->lockClassroomInCurrentScope(
                    $user,
                    $classroom,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'update',
                        $classroom
                    );

                $beforeValues =
                    $this->classroomAuditValues(
                        $classroom
                    );

                /*
                 * General information updates cannot move the Classroom
                 * to another Center or Branch and cannot bypass the
                 * explicit lifecycle/availability operations.
                 */
                $classroom->fill(
                    Arr::only(
                        $attributes,
                        [
                            'name',
                            'code',
                            'capacity',
                            'location',
                        ]
                    )
                );

                /*
                 * No business state changed, therefore there is
                 * no new event to add to Audit history.
                 */
                if (! $classroom->isDirty()) {
                    return $classroom;
                }

                $classroom->save();
                $classroom->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'classroom.updated',
                    subject: $classroom,
                    beforeValues: $beforeValues,
                    afterValues: $this->classroomAuditValues(
                        $classroom
                    )
                );

                return $classroom;
            },
            3
        );
    }

    public function setAvailability(
        User $user,
        Classroom $classroom,
        ClassroomAvailabilityStatus $availability
    ): Classroom {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $classroom,
                $availability,
                $centerId
            ): Classroom {
                $classroom = $this->lockClassroomInCurrentScope(
                    $user,
                    $classroom,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'update',
                        $classroom
                    );

                /*
                 * Repeating the same availability state is
                 * idempotent and produces no duplicate Audit Record.
                 */
                if (
                    $classroom->availability_status
                    === $availability
                ) {
                    return $classroom;
                }

                $beforeAvailability =
                    $classroom->availability_status;

                $classroom->update([
                    'availability_status' =>
                    $availability,
                ]);

                $classroom->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'classroom.availability_changed',
                    subject: $classroom,
                    beforeValues: [
                        'availability_status' =>
                        $beforeAvailability,
                    ],
                    afterValues: [
                        'availability_status' =>
                        $classroom
                            ->availability_status,
                    ]
                );

                return $classroom;
            },
            3
        );
    }

    public function activate(
        User $user,
        Classroom $classroom
    ): Classroom {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $classroom,
                $centerId
            ): Classroom {
                $classroom = $this->lockClassroomInCurrentScope(
                    $user,
                    $classroom,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'activate',
                        $classroom
                    );

                /*
                 * Lifecycle requests are idempotent.
                 */
                if (
                    $classroom->status
                    === ClassroomStatus::Active
                ) {
                    return $classroom;
                }

                $beforeStatus =
                    $classroom->status;

                $classroom->update([
                    'status' =>
                    ClassroomStatus::Active,
                ]);

                $classroom->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'classroom.activated',
                    subject: $classroom,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' =>
                        $classroom->status,
                    ]
                );

                return $classroom;
            },
            3
        );
    }

    public function deactivate(
        User $user,
        Classroom $classroom
    ): Classroom {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $classroom,
                $centerId
            ): Classroom {
                $classroom = $this->lockClassroomInCurrentScope(
                    $user,
                    $classroom,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'deactivate',
                        $classroom
                    );

                /*
                 * Lifecycle requests are idempotent.
                 */
                if (
                    $classroom->status
                    === ClassroomStatus::Deactivated
                ) {
                    return $classroom;
                }

                $beforeStatus =
                    $classroom->status;

                $classroom->update([
                    'status' =>
                    ClassroomStatus::Deactivated,
                ]);

                $classroom->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'classroom.deactivated',
                    subject: $classroom,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' =>
                        $classroom->status,
                    ]
                );

                return $classroom;
            },
            3
        );
    }

    private function authorizedCenterId(
        User $user
    ): int {
        $center = $this->tenant
            ->requireCenter();

        if ($user->center_id !== $center->id) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function lockBranchInCurrentTenant(
        Branch $branch,
        int $centerId
    ): Branch {
        /*
         * Bypass operational global scopes only for the explicit
         * persisted-row lookup. Tenant ownership is checked
         * immediately after the row is locked.
         */
        $persistedBranch = Branch::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $branch->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedBranch->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The branch is outside the authorized center scope.'
            );
        }

        return $persistedBranch;
    }

    private function lockClassroomInCurrentScope(
        User $user,
        Classroom $classroom,
        int $centerId
    ): Classroom {
        /*
         * Re-read the actual persisted Classroom before
         * authorization and mutation.
         *
         * This prevents stale or locally modified Eloquent state
         * from controlling Center or Branch authorization.
         */
        $persistedClassroom =
            Classroom::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $classroom->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedClassroom->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The classroom is outside the authorized center scope.'
            );
        }

        /*
         * Existing Classrooms remain maintainable when their
         * parent Branch is later deactivated.
         *
         * Therefore only operational Branch scope is checked here;
         * active Branch status is intentionally not required.
         */
        $this->ensureOperationalBranchScope(
            $user,
            $centerId,
            $persistedClassroom->branch_id
        );

        return $persistedClassroom;
    }

    private function ensureOperationalBranchScope(
        User $user,
        int $centerId,
        int $branchId
    ): void {
        if (! $this->branchContext->isEstablished()) {
            throw new AuthorizationException(
                'Branch operational context has not been established.'
            );
        }

        if (
            $user->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (! $this->branchContext->isCenterWide()) {
                throw new AuthorizationException(
                    'Center Owner classroom operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $user->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage classrooms.'
            );
        }

        if (! $this->branchContext->isBranchScoped()) {
            throw new AuthorizationException(
                'Branch Manager classroom operations require an assigned Branch context.'
            );
        }

        $contextBranch =
            $this->branchContext->branch();

        if (
            $contextBranch === null
            || $contextBranch->center_id !== $centerId
            || $contextBranch->id !== $branchId
        ) {
            throw new AuthorizationException(
                'The classroom operation is outside the assigned Branch scope.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function classroomAuditValues(
        Classroom $classroom
    ): array {
        return [
            'center_id' =>
            $classroom->center_id,

            'branch_id' =>
            $classroom->branch_id,

            'name' =>
            $classroom->name,

            'code' =>
            $classroom->code,

            'capacity' =>
            $classroom->capacity,

            'location' =>
            $classroom->location,

            'availability_status' =>
            $classroom->availability_status,

            'status' =>
            $classroom->status,
        ];
    }
}
