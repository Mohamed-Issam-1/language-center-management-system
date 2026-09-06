<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use LogicException;

class BranchContext
{
    private bool $established = false;

    private bool $centerWide = false;

    private ?Branch $branch = null;

    public function establishCenterWideScope(): void
    {
        $this->established = true;
        $this->centerWide = true;
        $this->branch = null;
    }

    public function establishBranchScope(
        Branch $branch
    ): void {
        $this->established = true;
        $this->centerWide = false;
        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->established = false;
        $this->centerWide = false;
        $this->branch = null;
    }

    public function isEstablished(): bool
    {
        return $this->established;
    }

    public function isCenterWide(): bool
    {
        return $this->established
            && $this->centerWide;
    }

    public function isBranchScoped(): bool
    {
        return $this->established
            && ! $this->centerWide
            && $this->branch !== null;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function requireBranch(): Branch
    {
        if (! $this->isBranchScoped()) {
            throw new LogicException(
                'The current request does not have an assigned Branch scope.'
            );
        }

        return $this->branch;
    }
}
