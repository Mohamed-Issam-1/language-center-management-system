<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Branch;
use App\Models\Course;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class AttendancePolicy
{
    public function create(
        User $user,
        ClassSession $session,
        Enrollment $enrollment
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageAttendance
            )
        ) {
            return false;
        }

        return $this->canManagePair(
            $user,
            $session,
            $enrollment
        );
    }

    public function update(
        User $user,
        Attendance $attendance
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageAttendance
            )
        ) {
            return false;
        }

        [
            $session,
            $enrollment,
        ] = $this->recordsForAttendance(
            $attendance
        );

        if (
            $session === null
            || $enrollment === null
        ) {
            return false;
        }

        return $this->canManagePair(
            $user,
            $session,
            $enrollment
        );
    }

    public function view(
        User $user,
        Attendance $attendance
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewAttendance
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $attendance->center_id
        ) {
            return false;
        }

        [
            $session,
            $enrollment,
        ] = $this->recordsForAttendance(
            $attendance
        );

        if (
            $session === null
            || $enrollment === null
        ) {
            return false;
        }

        $courseClass =
            $this->courseClassForPair(
                $session,
                $enrollment
            );

        if ($courseClass === null) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerCanAccessClass(
                $user,
                $courseClass
            ),

            SystemRole::Teacher =>
            $this->isAssignedTeacher(
                $user,
                $session
            ),

            SystemRole::Student =>
            $this->isEnrollmentStudent(
                $user,
                $enrollment
            ),

            default => false,
        };
    }

    public function viewEnrollmentSummary(
        User $user,
        Enrollment $enrollment
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewAttendance
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $enrollment->center_id
        ) {
            return false;
        }

        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->class_id
            )
            ->where(
                'center_id',
                $enrollment->center_id
            )
            ->first();

        if ($courseClass === null) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerCanAccessClass(
                $user,
                $courseClass
            ),

            SystemRole::Teacher =>
            $this->isAssignedClassTeacher(
                $user,
                $courseClass
            ),

            SystemRole::Student =>
            $this->isEnrollmentStudent(
                $user,
                $enrollment
            ),

            default => false,
        };
    }

    public function viewClassSummary(
        User $user,
        CourseClass $courseClass
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewAttendance
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
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerCanAccessClass(
                $user,
                $courseClass
            ),

            SystemRole::Teacher =>
            $this->isAssignedClassTeacher(
                $user,
                $courseClass
            ),

            default => false,
        };
    }

    public function viewCourseSummary(
        User $user,
        Course $course
    ): bool {
        return $user->hasPermission(
            SystemPermission::ViewAttendance
        )
            && $user->center_id !== null
            && $user->center_id === $course->center_id
            && $user->systemRole()
            === SystemRole::CenterOwner;
    }

    public function viewBranchSummary(
        User $user,
        Branch $branch
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewAttendance
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $branch->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $user
                ->activeBranchManagerAssignment()
                ->where(
                    'center_id',
                    $branch->center_id
                )
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->exists(),

            default => false,
        };
    }

    private function canManagePair(
        User $user,
        ClassSession $session,
        Enrollment $enrollment
    ): bool {
        if (
            $session->center_id
            !== $enrollment->center_id
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $session->center_id
        ) {
            return false;
        }

        $courseClass =
            $this->courseClassForPair(
                $session,
                $enrollment
            );

        if ($courseClass === null) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::BranchManager =>
            $this->branchManagerCanAccessClass(
                $user,
                $courseClass
            ),

            SystemRole::Teacher =>
            $this->isAssignedTeacher(
                $user,
                $session
            ),

            default => false,
        };
    }

    private function courseClassForPair(
        ClassSession $session,
        Enrollment $enrollment
    ): ?CourseClass {
        if (
            $session->class_id
            !== $enrollment->class_id
        ) {
            return null;
        }

        return CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $session->class_id
            )
            ->where(
                'center_id',
                $session->center_id
            )
            ->first();
    }

    private function branchManagerCanAccessClass(
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

    private function isAssignedClassTeacher(
        User $user,
        CourseClass $courseClass
    ): bool {
        return Teacher::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseClass->assigned_teacher_id
            )
            ->where(
                'center_id',
                $courseClass->center_id
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

    private function isAssignedTeacher(
        User $user,
        ClassSession $session
    ): bool {
        return Teacher::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $session->teacher_id
            )
            ->where(
                'center_id',
                $session->center_id
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

    private function isEnrollmentStudent(
        User $user,
        Enrollment $enrollment
    ): bool {
        return Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->student_id
            )
            ->where(
                'center_id',
                $enrollment->center_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();
    }

    /**
     * @return array{
     *     0: ?ClassSession,
     *     1: ?Enrollment
     * }
     */
    private function recordsForAttendance(
        Attendance $attendance
    ): array {
        $session =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $attendance->session_id
            )
            ->where(
                'center_id',
                $attendance->center_id
            )
            ->first();

        $enrollment =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $attendance->enrollment_id
            )
            ->where(
                'center_id',
                $attendance->center_id
            )
            ->first();

        return [
            $session,
            $enrollment,
        ];
    }
}
