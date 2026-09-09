<?php

namespace App\Services\Teachers;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Center;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class TeacherAttendanceReadService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Return one exact Teacher-assigned Session together with:
     *
     * - the complete Class Enrollment roster;
     * - any existing Attendance for each Enrollment;
     * - active Attendance Status options for future writes.
     *
     * Enrollment lifecycle eligibility is intentionally NOT
     * reimplemented here.
     *
     * AttendanceManagementService remains authoritative for
     * deciding whether a new Attendance record may be created
     * or an existing record may be updated.
     *
     * @return array{
     *     teacher: array<string, mixed>,
     *     session: array<string, mixed>,
     *     attendance_statuses: array<int, array<string, mixed>>,
     *     roster: array<int, array<string, mixed>>
     * }
     */
    public function forSession(
        User $actor,
        int $sessionId
    ): array {
        $context =
            $this->authorizedTeacherContext(
                $actor
            );

        /*
         * ClassSession.teacher_id is authoritative for Teacher
         * Attendance scope.
         *
         * A concrete Session may be assigned to a different
         * Teacher than the recurring Schedule or the Course
         * Class's current default Teacher.
         */
        $session =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->with([
                'courseClass' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'classroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'teacher_id',
                $context['teacher']->id
            )
            ->whereKey(
                $sessionId
            )
            ->first();

        /*
         * Deliberately use the same fail-closed result for:
         *
         * - missing Session;
         * - another Teacher's Session;
         * - another Center's Session;
         * - Session reassigned to another Teacher.
         */
        if ($session === null) {
            throw new AuthorizationException(
                'The Class Session is outside the authenticated Teacher Attendance scope.'
            );
        }

        $courseClass =
            $session->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Teacher Attendance Session references a missing Course Class.'
            );
        }

        $enrollments =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->with([
                'student' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'student.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'class_id',
                $courseClass->id
            )
            ->orderBy(
                'enrollment_date'
            )
            ->orderBy(
                'id'
            )
            ->get();

        /*
         * The roster intentionally contains historical
         * Enrollment states too.
         *
         * Completed, Withdrawn, Transferred, and Cancelled
         * records remain useful for historical Attendance
         * visibility/correction.
         *
         * Whether a NEW Attendance record may be created for
         * any particular Enrollment/Session pair is decided only
         * by AttendanceManagementService.
         */
        $enrollmentIds =
            $enrollments
            ->pluck(
                'id'
            )
            ->all();

        $attendances =
            $enrollmentIds === []
            ? collect()
            : Attendance::query()
            ->withoutGlobalScopes()
            ->with([
                'attendanceStatus' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'session_id',
                $session->id
            )
            ->whereIn(
                'enrollment_id',
                $enrollmentIds
            )
            ->get()
            ->keyBy(
                'enrollment_id'
            );

        return [
            'teacher' =>
            $this->teacherPayload(
                $context['actor'],
                $context['teacher'],
                $context['center']
            ),

            'session' =>
            $this->sessionPayload(
                $session
            ),

            /*
             * Only active statuses are offered as choices for
             * future record/update operations.
             *
             * Historical Attendance may still reference an
             * inactive status; that status is preserved inside
             * the corresponding existing Attendance payload.
             */
            'attendance_statuses' =>
            $this->activeAttendanceStatuses(
                $context['center_id']
            ),

            'roster' =>
            $enrollments
                ->map(
                    function (
                        Enrollment $enrollment
                    ) use (
                        $attendances
                    ): array {
                        /** @var Attendance|null $attendance */
                        $attendance =
                            $attendances->get(
                                $enrollment->id
                            );

                        return $this->rosterPayload(
                            $enrollment,
                            $attendance
                        );
                    }
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     actor: User,
     *     teacher: Teacher,
     *     center: Center,
     *     center_id: int
     * }
     */
    private function authorizedTeacherContext(
        User $actor
    ): array {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Teacher Attendance reads require a persisted User Account.'
            );
        }

        /*
         * Re-read persisted User state.
         *
         * A stale authenticated User object must not preserve
         * Teacher Attendance access after account deactivation,
         * role change, Person change, or Center change.
         */
        $persistedActor =
            User::query()
            ->withoutGlobalScopes()
            ->with(
                'role'
            )
            ->whereKey(
                $actor->getKey()
            )
            ->first();

        if ($persistedActor === null) {
            throw new AuthorizationException(
                'The Teacher User Account could not be resolved.'
            );
        }

        if (
            $persistedActor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an Active User Account may read Teacher Attendance.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Teacher
        ) {
            throw new AuthorizationException(
                'The account is not authorized for Teacher Attendance.'
            );
        }

        if (
            ! $persistedActor->hasPermission(
                SystemPermission::ViewAttendance
            )
            || ! $persistedActor->hasPermission(
                SystemPermission::ManageAttendance
            )
        ) {
            throw new AuthorizationException(
                'The Teacher account does not have the required Attendance permissions.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Teacher Attendance reads require a Center-scoped tenant context.'
            );
        }

        $center =
            $this->tenant
            ->center();

        $centerId =
            $this->tenant
            ->centerId();

        if (
            $center === null
            || $centerId === null
            || $persistedActor->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'Authenticated Teacher account and tenant context do not match.'
            );
        }

        if (
            $persistedActor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Teacher Attendance reads require a linked Person identity.'
            );
        }

        $teacher =
            Teacher::query()
            ->withoutGlobalScopes()
            ->with([
                'person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $persistedActor->id
            )
            ->where(
                'person_id',
                $persistedActor->person_id
            )
            ->where(
                'status',
                StaffStatus::Active->value
            )
            ->first();

        if ($teacher === null) {
            throw new AuthorizationException(
                'Teacher Attendance requires an Active Teacher record linked to this exact account and Person.'
            );
        }

        return [
            'actor' =>
            $persistedActor,

            'teacher' =>
            $teacher,

            'center' =>
            $center,

            'center_id' =>
            (int) $centerId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeAttendanceStatuses(
        int $centerId
    ): array {
        return AttendanceStatus::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'is_active',
                true
            )
            ->orderBy(
                'name'
            )
            ->orderBy(
                'id'
            )
            ->get()
            ->map(
                fn(
                    AttendanceStatus $status
                ): array => [
                    'id' =>
                    (int) $status->id,

                    'code' =>
                    $status->code,

                    'name' =>
                    $status->name,

                    'contribution_value' =>
                    (string) $status
                        ->contribution_value,
                ]
            )
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function teacherPayload(
        User $actor,
        Teacher $teacher,
        Center $center
    ): array {
        return [
            'id' =>
            (int) $teacher->id,

            'person_id' =>
            (int) $teacher->person_id,

            'user_id' =>
            (int) $teacher->user_id,

            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            'name' =>
            $teacher
                ->person
                ?->full_name
                ?? $actor->name,

            'status' =>
            $teacher
                ->status
                ->value,

            'center' => [
                'id' =>
                (int) $center->id,

                'code' =>
                $center->code,

                'name' =>
                $center->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(
        ClassSession $session
    ): array {
        $courseClass =
            $session
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Teacher Attendance Session references a missing Course Class.'
            );
        }

        $course =
            $courseClass
            ->course;

        $branch =
            $courseClass
            ->branch;

        if (
            $course === null
            || $branch === null
        ) {
            throw new LogicException(
                'The Teacher Attendance Session references missing academic data.'
            );
        }

        return [
            'session_id' =>
            (int) $session->id,

            'schedule_id' =>
            $session->schedule_id === null
                ? null
                : (int) $session
                    ->schedule_id,

            'class_id' =>
            (int) $courseClass->id,

            'session_date' =>
            $session
                ->session_date
                ?->toDateString(),

            'start_time' =>
            $session->start_time,

            'end_time' =>
            $session->end_time,

            'topic' =>
            $session->topic,

            'status' =>
            $session
                ->session_status
                ->value,

            'status_label' =>
            $session
                ->session_status
                ->label(),

            'cancellation_reason' =>
            $session
                ->cancellation_reason,

            'course' => [
                'id' =>
                (int) $course->id,

                'code' =>
                $course->code,

                'name' =>
                $course->name,
            ],

            'class' => [
                'id' =>
                (int) $courseClass->id,

                'code' =>
                $courseClass
                    ->class_code,

                'name' =>
                $courseClass->name,

                'status' =>
                $courseClass
                    ->class_status
                    ->value,

                'status_label' =>
                $courseClass
                    ->class_status
                    ->label(),
            ],

            'branch' => [
                'id' =>
                (int) $branch->id,

                'code' =>
                $branch->code,

                'name' =>
                $branch->name,
            ],

            'classroom' =>
            $this->classroomPayload(
                $session->classroom
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterPayload(
        Enrollment $enrollment,
        ?Attendance $attendance
    ): array {
        $student =
            $enrollment
            ->student;

        if (! $student instanceof Student) {
            throw new LogicException(
                'The Teacher Attendance roster references a missing Student.'
            );
        }

        $person =
            $student
            ->person;

        if ($person === null) {
            throw new LogicException(
                'The Teacher Attendance Student references a missing Person identity.'
            );
        }

        return [
            'enrollment_id' =>
            (int) $enrollment->id,

            'enrollment_number' =>
            $enrollment
                ->enrollment_number,

            'enrollment_date' =>
            $enrollment
                ->enrollment_date
                ?->toDateString(),

            'enrollment_status' =>
            $enrollment
                ->enrollment_status
                ->value,

            'enrollment_status_label' =>
            $enrollment
                ->enrollment_status
                ->label(),

            /*
             * These lifecycle fields are descriptive only.
             *
             * The frontend must not infer authoritative
             * recordability from them. The write service performs
             * the complete persisted eligibility validation.
             */
            'withdrawal_date' =>
            $enrollment
                ->withdrawal_date
                ?->toDateString(),

            'student' => [
                'id' =>
                (int) $student->id,

                'person_id' =>
                (int) $student->person_id,

                'name' =>
                $person->full_name,

                'status' =>
                $student
                    ->status
                    ->value,
            ],

            'attendance' =>
            $attendance === null
                ? null
                : $this->attendancePayload(
                    $attendance
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendancePayload(
        Attendance $attendance
    ): array {
        $status =
            $attendance
            ->attendanceStatus;

        if ($status === null) {
            throw new LogicException(
                'The Teacher Attendance record references a missing Attendance Status.'
            );
        }

        return [
            'attendance_id' =>
            (int) $attendance->id,

            'attendance_status' => [
                'id' =>
                (int) $status->id,

                'code' =>
                $status->code,

                'name' =>
                $status->name,

                'contribution_value' =>
                (string) $status
                    ->contribution_value,

                /*
                 * Existing historical Attendance may reference
                 * a Status that is no longer active.
                 */
                'is_active' =>
                (bool) $status
                    ->is_active,
            ],

            'late_minutes' =>
            (int) $attendance
                ->late_minutes,

            'excuse' =>
            $attendance->excuse,

            'notes' =>
            $attendance->notes,

            'recorded_at' =>
            $attendance
                ->recorded_at
                ?->toISOString(),

            'updated_at' =>
            $attendance
                ->updated_at
                ?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classroomPayload(
        mixed $classroom
    ): ?array {
        if ($classroom === null) {
            return null;
        }

        return [
            'id' =>
            (int) $classroom->id,

            'code' =>
            $classroom->code,

            'name' =>
            $classroom->name,

            'location' =>
            $classroom->location,
        ];
    }
}