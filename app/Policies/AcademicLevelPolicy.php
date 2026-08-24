<?php

namespace App\Policies;

use App\Models\AcademicLevel;
use App\Models\User;
use App\Support\Enums\SystemPermission;

class AcademicLevelPolicy
{
    public function create(
        User $user
    ): bool {
        return $this->canManageCenter(
            $user
        );
    }

    public function update(
        User $user,
        AcademicLevel $academicLevel
    ): bool {
        return $this->canManage(
            $user,
            $academicLevel
        );
    }

    public function archive(
        User $user,
        AcademicLevel $academicLevel
    ): bool {
        return $this->canManage(
            $user,
            $academicLevel
        );
    }

    public function restore(
        User $user,
        AcademicLevel $academicLevel
    ): bool {
        return $this->canManage(
            $user,
            $academicLevel
        );
    }

    private function canManage(
        User $user,
        AcademicLevel $academicLevel
    ): bool {
        return $this->canManageCenter(
            $user
        )
            && $user->center_id
            === $academicLevel->center_id;
    }

    private function canManageCenter(
        User $user
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageAcademicStructure
        )
            && $user->center_id !== null;
    }
}
