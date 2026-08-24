<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\CourseClass;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class CourseClassPolicy
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
        CourseClass $courseClass
    ): bool {
        return $this->canManageBranch(
            $user,
            $courseClass->center_id,
            $courseClass->branch_id
        );
    }

    public function transition(
        User $user,
        CourseClass $courseClass
    ): bool {
        return $this->canManageBranch(
            $user,
            $courseClass->center_id,
            $courseClass->branch_id
        );
    }

    private function canManageBranch(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageClasses
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
