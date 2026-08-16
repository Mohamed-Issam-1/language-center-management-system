<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\User;
use App\Support\Enums\BranchStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class BranchManagementService
{
    public function __construct(
        private readonly TenantContext $tenant
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

        return Branch::query()->create(
            $data
        );
    }

    public function update(
        User $user,
        Branch $branch,
        array $attributes
    ): Branch {
        Gate::forUser($user)
            ->authorize(
                'update',
                $branch
            );

        $this->ensureBranchIsInCurrentTenant(
            $user,
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

        $branch->save();

        return $branch->refresh();
    }

    public function activate(
        User $user,
        Branch $branch
    ): Branch {
        Gate::forUser($user)
            ->authorize(
                'activate',
                $branch
            );

        $this->ensureBranchIsInCurrentTenant(
            $user,
            $branch
        );

        $branch->update([
            'status' => BranchStatus::Active,
        ]);

        return $branch->refresh();
    }

    public function deactivate(
        User $user,
        Branch $branch
    ): Branch {
        Gate::forUser($user)
            ->authorize(
                'deactivate',
                $branch
            );

        $this->ensureBranchIsInCurrentTenant(
            $user,
            $branch
        );

        $branch->update([
            'status' => BranchStatus::Deactivated,
        ]);

        return $branch->refresh();
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
        User $user,
        Branch $branch
    ): void {
        $centerId = $this->authorizedCenterId(
            $user
        );

        if ($branch->center_id !== $centerId) {
            throw new AuthorizationException(
                'The branch is outside the authorized center scope.'
            );
        }
    }
}
