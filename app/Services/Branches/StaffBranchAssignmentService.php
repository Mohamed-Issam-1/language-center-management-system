<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StaffBranchAssignmentService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    public function assignBranchManager(
        User $actor,
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        $centerId = $this->authorizeActor(
            $actor
        );

        return DB::transaction(
            function () use (
                $manager,
                $branch,
                $centerId
            ): BranchManagerAssignment {
                $manager = $this->lockRoleAccount(
                    $manager,
                    $centerId,
                    SystemRole::BranchManager
                );

                $branch = $this->lockActiveBranch(
                    $branch,
                    $centerId
                );

                $currentUserAssignment =
                    BranchManagerAssignment::query()
                    ->where(
                        'user_id',
                        $manager->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                $currentBranchAssignment =
                    BranchManagerAssignment::query()
                    ->where(
                        'branch_id',
                        $branch->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                /*
                 * The requested manager is already assigned
                 * to this Branch.
                 *
                 * Treat the request as idempotent.
                 */
                if (
                    $currentBranchAssignment !== null
                    && $currentBranchAssignment->user_id
                    === $manager->id
                ) {
                    return $currentBranchAssignment;
                }

                /*
                 * A Branch may have only one active
                 * Branch Manager.
                 */
                if ($currentBranchAssignment !== null) {
                    throw new DomainException(
                        'The branch already has an active Branch Manager.'
                    );
                }

                /*
                * Assignment is not an implicit reassignment operation.
                *
                * A Branch Manager that already has an active Branch
                * assignment must be explicitly ended before the account
                * can be assigned to another Branch.
                */
                if ($currentUserAssignment !== null) {
                    throw new DomainException(
                        'The Branch Manager is already assigned to another branch.'
                    );
                }

                return BranchManagerAssignment::query()
                    ->create([
                        'center_id' => $centerId,
                        'user_id' => $manager->id,
                        'branch_id' => $branch->id,
                        'started_at' => now(),
                        'ended_at' => null,
                        'active_marker' => 1,
                    ]);
            },
            3
        );
    }

    public function replaceBranchManager(
        User $actor,
        Branch $branch,
        User $newManager
    ): BranchManagerAssignment {
        $centerId = $this->authorizeActor(
            $actor
        );

        return DB::transaction(
            function () use (
                $branch,
                $newManager,
                $centerId
            ): BranchManagerAssignment {
                /*
                 * Lock the new Manager account first.
                 * This serializes concurrent attempts to assign
                 * the same account to different Branches.
                 */
                $newManager = $this->lockRoleAccount(
                    $newManager,
                    $centerId,
                    SystemRole::BranchManager
                );

                /*
                 * Lock the Branch so concurrent replacement
                 * operations for the same Branch are serialized.
                 */
                $branch = $this->lockActiveBranch(
                    $branch,
                    $centerId
                );

                $currentBranchAssignment =
                    BranchManagerAssignment::query()
                    ->where(
                        'branch_id',
                        $branch->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if (
                    $currentBranchAssignment !== null
                    && $currentBranchAssignment->user_id
                    === $newManager->id
                ) {
                    return $currentBranchAssignment;
                }

                $newManagerAssignment =
                    BranchManagerAssignment::query()
                    ->where(
                        'user_id',
                        $newManager->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                /*
                * Replacement must not silently take a Manager
                * away from another Branch.
                *
                * The Manager's current assignment must be
                * explicitly ended before the account can be
                * assigned as the replacement for this Branch.
                */
                if ($newManagerAssignment !== null) {
                    throw new DomainException(
                        'The new Branch Manager is already assigned to another branch.'
                    );
                }

                if ($currentBranchAssignment !== null) {
                    $this->endManagerAssignment(
                        $currentBranchAssignment
                    );
                }

                return BranchManagerAssignment::query()
                    ->create([
                        'center_id' => $centerId,
                        'user_id' => $newManager->id,
                        'branch_id' => $branch->id,
                        'started_at' => now(),
                        'ended_at' => null,
                        'active_marker' => 1,
                    ]);
            },
            3
        );
    }

    public function endBranchManagerAssignment(
        User $actor,
        User $manager
    ): ?BranchManagerAssignment {
        $centerId = $this->authorizeActor(
            $actor
        );

        return DB::transaction(
            function () use (
                $manager,
                $centerId
            ): ?BranchManagerAssignment {
                $manager = $this->lockRoleAccount(
                    $manager,
                    $centerId,
                    SystemRole::BranchManager,
                    false
                );

                $assignment =
                    BranchManagerAssignment::query()
                    ->where(
                        'user_id',
                        $manager->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($assignment === null) {
                    return null;
                }

                $this->endManagerAssignment(
                    $assignment
                );

                return $assignment->refresh();
            },
            3
        );
    }

    public function assignFinanceEmployee(
        User $actor,
        User $financeEmployee,
        Branch $branch
    ): FinanceEmployeeAssignment {
        $centerId = $this->authorizeActor(
            $actor
        );

        return DB::transaction(
            function () use (
                $financeEmployee,
                $branch,
                $centerId
            ): FinanceEmployeeAssignment {
                $financeEmployee =
                    $this->lockRoleAccount(
                        $financeEmployee,
                        $centerId,
                        SystemRole::FinanceEmployee
                    );

                $branch = $this->lockActiveBranch(
                    $branch,
                    $centerId
                );

                $currentAssignment =
                    FinanceEmployeeAssignment::query()
                    ->where(
                        'user_id',
                        $financeEmployee->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                /*
                 * Already assigned to this Branch.
                 */
                if (
                    $currentAssignment !== null
                    && $currentAssignment->branch_id
                    === $branch->id
                ) {
                    return $currentAssignment;
                }

                /*
                * Assignment is not an implicit reassignment operation.
                *
                * A Finance Employee with an active Branch assignment
                * must have that assignment explicitly ended before a
                * different Branch can be assigned.
                */
                if ($currentAssignment !== null) {
                    throw new DomainException(
                        'The Finance Employee is already assigned to another branch.'
                    );
                }

                return FinanceEmployeeAssignment::query()
                    ->create([
                        'center_id' => $centerId,
                        'user_id' => $financeEmployee->id,
                        'branch_id' => $branch->id,
                        'started_at' => now(),
                        'ended_at' => null,
                        'active_marker' => 1,
                    ]);
            },
            3
        );
    }

    public function endFinanceEmployeeAssignment(
        User $actor,
        User $financeEmployee
    ): ?FinanceEmployeeAssignment {
        $centerId = $this->authorizeActor(
            $actor
        );

        return DB::transaction(
            function () use (
                $financeEmployee,
                $centerId
            ): ?FinanceEmployeeAssignment {
                $financeEmployee =
                    $this->lockRoleAccount(
                        $financeEmployee,
                        $centerId,
                        SystemRole::FinanceEmployee,
                        false
                    );

                $assignment =
                    FinanceEmployeeAssignment::query()
                    ->where(
                        'user_id',
                        $financeEmployee->id
                    )
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($assignment === null) {
                    return null;
                }

                $this->endFinanceAssignment(
                    $assignment
                );

                return $assignment->refresh();
            },
            3
        );
    }

    private function authorizeActor(
        User $actor
    ): int {
        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ManageStaffAccounts->value
            );

        /*
         * The SRS specifically assigns staff-management
         * responsibility to Center Owner.
         */
        if (
            ! $actor->hasSystemRole(
                SystemRole::CenterOwner
            )
        ) {
            throw new AuthorizationException(
                'Only a Center Owner may manage staff branch assignments.'
            );
        }

        $center = $this->tenant
            ->requireCenter();

        if ($actor->center_id !== $center->id) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function lockRoleAccount(
        User $user,
        int $centerId,
        SystemRole $expectedRole,
        bool $requireAssignable = true
    ): User {
        $lockedUser = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedUser->center_id !== $centerId) {
            throw new AuthorizationException(
                'The staff account is outside the authorized center scope.'
            );
        }

        if (
            ! $lockedUser->hasSystemRole(
                $expectedRole
            )
        ) {
            throw new DomainException(
                sprintf(
                    'The account must have the %s role.',
                    $expectedRole->label()
                )
            );
        }

        /*
         * Pending accounts may receive their operational
         * assignment before activation.
         *
         * Deactivated accounts must not receive a new active
         * operational assignment.
         */
        if (
            $requireAssignable
            && $lockedUser->status
            === AccountStatus::Deactivated
        ) {
            throw new DomainException(
                'A deactivated account cannot receive a new branch assignment.'
            );
        }

        return $lockedUser;
    }

    private function lockActiveBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        $lockedBranch = Branch::query()
            ->whereKey($branch->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedBranch->center_id !== $centerId) {
            throw new AuthorizationException(
                'The branch is outside the authorized center scope.'
            );
        }

        if (
            $lockedBranch->status
            !== BranchStatus::Active
        ) {
            throw new DomainException(
                'New operational assignments cannot be created for a deactivated branch.'
            );
        }

        return $lockedBranch;
    }

    private function endManagerAssignment(
        BranchManagerAssignment $assignment
    ): void {
        $assignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);
    }

    private function endFinanceAssignment(
        FinanceEmployeeAssignment $assignment
    ): void {
        $assignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);
    }
}
