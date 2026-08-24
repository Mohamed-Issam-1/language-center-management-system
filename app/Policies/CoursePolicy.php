<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Support\Enums\SystemPermission;

class CoursePolicy
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
        Course $course
    ): bool {
        return $this->canManage(
            $user,
            $course
        );
    }

    public function archive(
        User $user,
        Course $course
    ): bool {
        return $this->canManage(
            $user,
            $course
        );
    }

    public function restore(
        User $user,
        Course $course
    ): bool {
        return $this->canManage(
            $user,
            $course
        );
    }

    private function canManage(
        User $user,
        Course $course
    ): bool {
        return $this->canManageCenter(
            $user
        )
            && $user->center_id
            === $course->center_id;
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
