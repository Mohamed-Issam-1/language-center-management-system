<?php

namespace App\Support\Tenancy;

use App\Models\Center;
use LogicException;

class TenantContext
{
    private bool $established = false;

    private bool $platformScoped = false;

    private ?Center $center = null;

    public function establishPlatformScope(): void
    {
        $this->established = true;
        $this->platformScoped = true;
        $this->center = null;
    }

    public function establishCenterScope(
        Center $center
    ): void {
        $this->established = true;
        $this->platformScoped = false;
        $this->center = $center;
    }

    public function clear(): void
    {
        $this->established = false;
        $this->platformScoped = false;
        $this->center = null;
    }

    public function isEstablished(): bool
    {
        return $this->established;
    }

    public function isPlatformScoped(): bool
    {
        return $this->established
            && $this->platformScoped;
    }

    public function isCenterScoped(): bool
    {
        return $this->established
            && ! $this->platformScoped
            && $this->center !== null;
    }

    public function center(): ?Center
    {
        return $this->center;
    }

    public function centerId(): ?int
    {
        return $this->center?->id;
    }

    public function requireCenter(): Center
    {
        if (! $this->isCenterScoped()) {
            throw new LogicException(
                'The current request does not have a center-scoped tenant context.'
            );
        }

        return $this->center;
    }
}
