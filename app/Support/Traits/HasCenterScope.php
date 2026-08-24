<?php

namespace App\Support\Traits;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait HasCenterScope
{
    public function scopeForCurrentTenant(
        Builder $query
    ): Builder {
        return $this->scopeForTenant(
            $query,
            app(TenantContext::class)
        );
    }

    public function scopeForTenant(
        Builder $query,
        TenantContext $tenant
    ): Builder {
        if (! $tenant->isEstablished()) {
            throw new LogicException(
                'Tenant context must be established before querying center-scoped records.'
            );
        }

        if (! $tenant->isCenterScoped()) {
            throw new AuthorizationException(
                'This operation requires a center-scoped account.'
            );
        }

        $centerId = $tenant->centerId();

        if ($centerId === null) {
            throw new LogicException(
                'The established center tenant context does not contain a center identifier.'
            );
        }

        return $query->where(
            $this->qualifyColumn('center_id'),
            $centerId
        );
    }
}
