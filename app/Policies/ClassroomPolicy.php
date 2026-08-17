<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class ClassroomPolicy
{
    public function create(
        User $user,
        Branch $branch
    ): bool {
        return $this->canManageBranch(
            $user,
            $branch->center_id,
            $branch->id
        );
    }

    public function update(
        User $user,
        Classroom $classroom
    ): bool {
        return $this->canManageBranch(
            $user,
            $classroom->center_id,
            $classroom->branch_id
        );
    }

    public function activate(
        User $user,
        Classroom $classroom
    ): bool {
        return $this->canManageBranch(
            $user,
            $classroom->center_id,
            $classroom->branch_id
        );
    }

    public function deactivate(
        User $user,
        Classroom $classroom
    ): bool {
        return $this->canManageBranch(
            $user,
            $classroom->center_id,
            $classroom->branch_id
        );
    }

    private function canManageBranch(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageClassrooms
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id !== $centerId
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            SystemRole::BranchManager =>
            $user->activeBranchManagerAssignment()
                ->where(
                    'center_id',
                    $centerId
                )
                ->where(
                    'branch_id',
                    $branchId
                )
                ->exists(),

            default => false,
        };
    }
}
