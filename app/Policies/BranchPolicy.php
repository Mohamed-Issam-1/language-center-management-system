<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Support\Enums\SystemPermission;

class BranchPolicy
{
    public function create(
        User $user
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageBranches
        )
            && $user->center_id !== null;
    }

    public function update(
        User $user,
        Branch $branch
    ): bool {
        return $this->canManage(
            $user,
            $branch
        );
    }

    public function activate(
        User $user,
        Branch $branch
    ): bool {
        return $this->canManage(
            $user,
            $branch
        );
    }

    public function deactivate(
        User $user,
        Branch $branch
    ): bool {
        return $this->canManage(
            $user,
            $branch
        );
    }

    private function canManage(
        User $user,
        Branch $branch
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageBranches
        )
            && $user->center_id !== null
            && $user->center_id === $branch->center_id;
    }
}
