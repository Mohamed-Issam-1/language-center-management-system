<?php

namespace App\Support\Traits;

use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait HasBranchScope
{
    public function scopeForCurrentBranch(
        Builder $query
    ): Builder {
        return $this->scopeForBranchContext(
            $query,
            app(TenantContext::class),
            app(BranchContext::class)
        );
    }

    public function scopeForBranchContext(
        Builder $query,
        TenantContext $tenant,
        BranchContext $branchContext
    ): Builder {
        if (! $tenant->isEstablished()) {
            throw new LogicException(
                'Tenant context must be established before querying branch-scoped records.'
            );
        }

        if (! $tenant->isCenterScoped()) {
            throw new AuthorizationException(
                'Branch-scoped records require a center-scoped tenant.'
            );
        }

        if (! $branchContext->isEstablished()) {
            throw new LogicException(
                'Branch context must be established before querying branch-scoped records.'
            );
        }

        $centerId = $tenant->centerId();

        if ($centerId === null) {
            throw new LogicException(
                'The established tenant context does not contain a Center identifier.'
            );
        }

        /*
         * Every branch-owned query is always Center-scoped first.
         *
         * Branch scope is an additional restriction, never a
         * replacement for tenant isolation.
         */
        $query->where(
            $this->qualifyColumn('center_id'),
            $centerId
        );

        if ($branchContext->isCenterWide()) {
            return $query;
        }

        if (! $branchContext->isBranchScoped()) {
            throw new AuthorizationException(
                'The current operational context does not permit Branch-scoped access.'
            );
        }

        $branch = $branchContext->branch();

        if ($branch === null) {
            throw new LogicException(
                'The established Branch context does not contain a Branch.'
            );
        }

        /*
         * Defense in depth. EstablishBranchContext already checks
         * this relationship, but scoped queries must not rely on a
         * potentially inconsistent operational context.
         */
        if ($branch->center_id !== $centerId) {
            throw new AuthorizationException(
                'The Branch context does not belong to the current Center tenant.'
            );
        }

        return $query->where(
            $this->qualifyColumn('branch_id'),
            $branch->id
        );
    }
}
