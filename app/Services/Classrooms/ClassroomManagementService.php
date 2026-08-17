<?php

namespace App\Services\Classrooms;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\User;
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
        private readonly BranchContext $branchContext
    ) {}

    public function create(
        User $user,
        Branch $branch,
        array $attributes
    ): Classroom {
        Gate::forUser($user)
            ->authorize(
                'create',
                [
                    Classroom::class,
                    $branch,
                ]
            );

        $centerId = $this->authorizedCenterId(
            $user
        );

        $this->ensureBranchIsInCurrentTenant(
            $branch,
            $centerId
        );

        $this->ensureOperationalBranchScope(
            $user,
            $centerId,
            $branch->id
        );

        return DB::transaction(
            function () use (
                $branch,
                $centerId,
                $attributes
            ): Classroom {
                /*
                 * Re-read and lock the Branch so creation cannot rely
                 * on stale in-memory status and cannot race with Branch
                 * deactivation.
                 */
                $lockedBranch = Branch::query()
                    ->whereKey($branch->id)
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->lockForUpdate()
                    ->first();

                if ($lockedBranch === null) {
                    throw new AuthorizationException(
                        'The branch is outside the authorized center scope.'
                    );
                }

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
                 * authorized target Branch and never trusted from input.
                 */
                $data['center_id'] = $centerId;
                $data['branch_id'] = $lockedBranch->id;

                return Classroom::query()
                    ->create($data);
            },
            3
        );
    }

    public function update(
        User $user,
        Classroom $classroom,
        array $attributes
    ): Classroom {
        Gate::forUser($user)
            ->authorize(
                'update',
                $classroom
            );

        $this->ensureClassroomIsInCurrentScope(
            $user,
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

        $classroom->save();

        return $classroom->refresh();
    }

    public function setAvailability(
        User $user,
        Classroom $classroom,
        ClassroomAvailabilityStatus $availability
    ): Classroom {
        Gate::forUser($user)
            ->authorize(
                'update',
                $classroom
            );

        $this->ensureClassroomIsInCurrentScope(
            $user,
            $classroom
        );

        $classroom->update([
            'availability_status' => $availability,
        ]);

        return $classroom->refresh();
    }

    public function activate(
        User $user,
        Classroom $classroom
    ): Classroom {
        Gate::forUser($user)
            ->authorize(
                'activate',
                $classroom
            );

        $this->ensureClassroomIsInCurrentScope(
            $user,
            $classroom
        );

        $classroom->update([
            'status' => ClassroomStatus::Active,
        ]);

        return $classroom->refresh();
    }

    public function deactivate(
        User $user,
        Classroom $classroom
    ): Classroom {
        Gate::forUser($user)
            ->authorize(
                'deactivate',
                $classroom
            );

        $this->ensureClassroomIsInCurrentScope(
            $user,
            $classroom
        );

        $classroom->update([
            'status' => ClassroomStatus::Deactivated,
        ]);

        return $classroom->refresh();
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

    private function ensureBranchIsInCurrentTenant(
        Branch $branch,
        int $centerId
    ): void {
        if ($branch->center_id !== $centerId) {
            throw new AuthorizationException(
                'The branch is outside the authorized center scope.'
            );
        }
    }

    private function ensureClassroomIsInCurrentScope(
        User $user,
        Classroom $classroom
    ): void {
        $centerId = $this->authorizedCenterId(
            $user
        );

        if ($classroom->center_id !== $centerId) {
            throw new AuthorizationException(
                'The classroom is outside the authorized center scope.'
            );
        }

        $this->ensureOperationalBranchScope(
            $user,
            $centerId,
            $classroom->branch_id
        );
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
}
