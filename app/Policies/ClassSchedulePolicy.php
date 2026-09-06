<?php

namespace App\Policies;

use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class ClassSchedulePolicy
{
    public function create(
        User $user,
        CourseClass $courseClass
    ): bool {
        return $this->canManageClass(
            $user,
            $courseClass
        );
    }

    public function view(
        User $user,
        ClassSchedule $schedule
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewSchedules
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $schedule->center_id
        ) {
            return false;
        }

        $courseClass =
            $this->courseClassForSchedule(
                $schedule
            );

        if ($courseClass === null) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            SystemRole::BranchManager =>
            $this->isAssignedBranchManager(
                $user,
                $courseClass
            ),

            SystemRole::Teacher =>
            $this->isAssignedTeacher(
                $user,
                $schedule->teacher_id,
                $schedule->center_id
            ),

            default => false,
        };
    }

    public function update(
        User $user,
        ClassSchedule $schedule
    ): bool {
        return $this->canManageSchedule(
            $user,
            $schedule
        );
    }

    public function reschedule(
        User $user,
        ClassSchedule $schedule
    ): bool {
        return $this->canManageSchedule(
            $user,
            $schedule
        );
    }

    public function cancel(
        User $user,
        ClassSchedule $schedule
    ): bool {
        return $this->canManageSchedule(
            $user,
            $schedule
        );
    }

    private function canManageSchedule(
        User $user,
        ClassSchedule $schedule
    ): bool {
        if (
            $schedule->center_id === null
            || $user->center_id
            !== $schedule->center_id
        ) {
            return false;
        }

        $courseClass =
            $this->courseClassForSchedule(
                $schedule
            );

        if ($courseClass === null) {
            return false;
        }

        return $this->canManageClass(
            $user,
            $courseClass
        );
    }

    private function canManageClass(
        User $user,
        CourseClass $courseClass
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageSchedules
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $courseClass->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            SystemRole::BranchManager =>
            $this->isAssignedBranchManager(
                $user,
                $courseClass
            ),

            default => false,
        };
    }

    private function isAssignedBranchManager(
        User $user,
        CourseClass $courseClass
    ): bool {
        return $user
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $courseClass->center_id
            )
            ->where(
                'branch_id',
                $courseClass->branch_id
            )
            ->exists();
    }

    private function isAssignedTeacher(
        User $user,
        int $teacherId,
        int $centerId
    ): bool {
        return Teacher::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'status',
                StaffStatus::Active->value
            )
            ->exists();
    }

    private function courseClassForSchedule(
        ClassSchedule $schedule
    ): ?CourseClass {
        return CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $schedule->class_id
            )
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->first();
    }
}
