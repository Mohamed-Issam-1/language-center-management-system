<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\BranchStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BranchManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $user,
        array $attributes
    ): Branch {
        Gate::forUser($user)
            ->authorize(
                'create',
                Branch::class
            );

        $centerId = $this->authorizedCenterId(
            $user
        );

        $data = Arr::only(
            $attributes,
            [
                'name',
                'code',
                'phone',
                'email',
                'address',
                'working_hours',
                'status',
            ]
        );

        /*
         * center_id is always derived from the authenticated
         * tenant context and never trusted from request input.
         */
        $data['center_id'] = $centerId;

        return DB::transaction(
            function () use (
                $user,
                $data
            ): Branch {
                $branch = Branch::query()
                    ->create(
                        $data
                    );

                $this->audit->record(
                    actor: $user,
                    actionType: 'branch.created',
                    subject: $branch,
                    afterValues: $this->branchAuditValues(
                        $branch
                    )
                );

                return $branch->refresh();
            },
            3
        );
    }

    public function update(
        User $user,
        Branch $branch,
        array $attributes
    ): Branch {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $branch,
                $attributes,
                $centerId
            ): Branch {
                /*
                 * Re-read and lock the persisted Branch.
                 *
                 * Authorization, tenant validation, and Audit
                 * history must not depend on stale or locally
                 * modified Eloquent state.
                 */
                $branch = $this->lockBranchInCurrentTenant(
                    $branch,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'update',
                        $branch
                    );

                $beforeValues =
                    $this->branchAuditValues(
                        $branch
                    );

                /*
                 * center_id and status cannot be changed through general
                 * branch-information updates.
                 *
                 * Status changes go through the explicit lifecycle
                 * operations below.
                 */
                $branch->fill(
                    Arr::only(
                        $attributes,
                        [
                            'name',
                            'code',
                            'phone',
                            'email',
                            'address',
                            'working_hours',
                        ]
                    )
                );

                /*
                 * No persisted business state changed.
                 * Do not create duplicate Audit history.
                 */
                if (! $branch->isDirty()) {
                    return $branch;
                }

                $branch->save();
                $branch->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'branch.updated',
                    subject: $branch,
                    beforeValues: $beforeValues,
                    afterValues: $this->branchAuditValues(
                        $branch
                    )
                );

                return $branch;
            },
            3
        );
    }

    public function activate(
        User $user,
        Branch $branch
    ): Branch {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $branch,
                $centerId
            ): Branch {
                $branch = $this->lockBranchInCurrentTenant(
                    $branch,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'activate',
                        $branch
                    );

                /*
                 * Lifecycle requests are idempotent.
                 * An already-active Branch does not produce
                 * duplicate Audit history.
                 */
                if (
                    $branch->status
                    === BranchStatus::Active
                ) {
                    return $branch;
                }

                $beforeStatus =
                    $branch->status;

                $branch->update([
                    'status' => BranchStatus::Active,
                ]);

                $branch->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'branch.activated',
                    subject: $branch,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' => $branch->status,
                    ]
                );

                return $branch;
            },
            3
        );
    }

    public function deactivate(
        User $user,
        Branch $branch
    ): Branch {
        $centerId = $this->authorizedCenterId(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $branch,
                $centerId
            ): Branch {
                $branch = $this->lockBranchInCurrentTenant(
                    $branch,
                    $centerId
                );

                Gate::forUser($user)
                    ->authorize(
                        'deactivate',
                        $branch
                    );

                /*
                 * Lifecycle requests are idempotent.
                 * An already-deactivated Branch does not produce
                 * duplicate Audit history.
                 */
                if (
                    $branch->status
                    === BranchStatus::Deactivated
                ) {
                    return $branch;
                }

                $beforeStatus =
                    $branch->status;

                $branch->update([
                    'status' => BranchStatus::Deactivated,
                ]);

                $branch->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'branch.deactivated',
                    subject: $branch,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' => $branch->status,
                    ]
                );

                return $branch;
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
         * Load the real persisted record without depending on
         * tenant-scoped state carried by the supplied Model.
         *
         * Tenant ownership is explicitly verified immediately
         * after the row is locked.
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

    /**
     * @return array<string, mixed>
     */
    private function branchAuditValues(
        Branch $branch
    ): array {
        return [
            'center_id' => $branch->center_id,
            'name' => $branch->name,
            'code' => $branch->code,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'address' => $branch->address,
            'working_hours' => $branch->working_hours,
            'status' => $branch->status,
        ];
    }
}
