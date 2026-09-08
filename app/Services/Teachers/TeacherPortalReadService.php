<?php

namespace App\Services\Teachers;

use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
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
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class TeacherPortalReadService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Return every Course Class currently assigned to the
     * authenticated Teacher.
     *
     * Historical/completed/cancelled Classes remain visible while
     * their persisted assigned_teacher_id still points to this
     * Teacher.
     *
     * @return array{
     *     teacher: array<string, mixed>,
     *     classes: array<int, array<string, mixed>>
     * }
     */
    public function classes(
        User $actor
    ): array {
        $context =
            $this->authorizedTeacherContext(
                $actor
            );

        $classes =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->with([
                'course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'assignedClassroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->withCount([
                'enrollments as enrollment_count' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'assigned_teacher_id',
                $context['teacher']->id
            )
            ->orderByDesc(
                'start_date'
            )
            ->orderByDesc(
                'id'
            )
            ->get();

        return [
            'teacher' =>
            $this->teacherPayload(
                $context['actor'],
                $context['teacher'],
                $context['center']
            ),

            'classes' =>
            $classes
                ->map(
                    fn(
                        CourseClass $courseClass
                    ): array =>
                    $this->classPayload(
                        $courseClass,
                        (int) $courseClass
                            ->getAttribute(
                                'enrollment_count'
                            )
                    )
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return one exact Teacher-assigned Course Class together
     * with its Enrollment roster.
     *
     * Student visibility is deliberately resolved through the
     * assigned Course Class rather than StudentPolicy::viewAny().
     *
     * This prevents Teacher accounts from gaining general
     * Student-directory access.
     *
     * @return array{
     *     teacher: array<string, mixed>,
     *     class: array<string, mixed>,
     *     students: array<int, array<string, mixed>>
     * }
     */
    public function classDetails(
        User $actor,
        int $courseClassId
    ): array {
        $context =
            $this->authorizedTeacherContext(
                $actor
            );

        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->with([
                'course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'assignedClassroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'enrollments' =>
                fn($query) =>
                $query
                    ->withoutGlobalScopes()
                    ->orderBy(
                        'enrollment_date'
                    )
                    ->orderBy(
                        'id'
                    ),

                'enrollments.student' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'enrollments.student.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'assigned_teacher_id',
                $context['teacher']->id
            )
            ->whereKey(
                $courseClassId
            )
            ->first();

        /*
         * Deliberately return the same fail-closed result for:
         *
         * - nonexistent Class;
         * - another Teacher's Class;
         * - another Center's Class;
         * - a Class that has been reassigned away.
         *
         * The caller must not learn which condition occurred.
         */
        if ($courseClass === null) {
            throw new AuthorizationException(
                'The Course Class is outside the authenticated Teacher scope.'
            );
        }

        $enrollments =
            $courseClass
            ->enrollments;

        return [
            'teacher' =>
            $this->teacherPayload(
                $context['actor'],
                $context['teacher'],
                $context['center']
            ),

            'class' =>
            $this->classPayload(
                $courseClass,
                $enrollments->count()
            ),

            'students' =>
            $enrollments
                ->map(
                    fn(
                        Enrollment $enrollment
                    ): array =>
                    $this->rosterPayload(
                        $enrollment
                    )
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return the authenticated Teacher's recurring Schedule
     * assignments and concrete Session assignments.
     *
     * Concrete Sessions use ClassSession.teacher_id as the
     * authoritative Teacher assignment because an individual
     * Session may differ from its recurring Schedule.
     *
     * Cancelled records remain visible as historical schedule
     * information.
     *
     * @return array{
     *     teacher: array<string, mixed>,
     *     schedules: array<int, array<string, mixed>>,
     *     sessions: array<int, array<string, mixed>>
     * }
     */
    public function schedule(
        User $actor
    ): array {
        $context =
            $this->authorizedTeacherContext(
                $actor
            );

        $schedules =
            ClassSchedule::query()
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
            ->orderBy(
                'day_of_week'
            )
            ->orderBy(
                'start_time'
            )
            ->orderBy(
                'id'
            )
            ->get();

        $sessions =
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
            ->orderBy(
                'session_date'
            )
            ->orderBy(
                'start_time'
            )
            ->orderBy(
                'id'
            )
            ->get();

        return [
            'teacher' =>
            $this->teacherPayload(
                $context['actor'],
                $context['teacher'],
                $context['center']
            ),

            'schedules' =>
            $schedules
                ->map(
                    fn(
                        ClassSchedule $schedule
                    ): array =>
                    $this->schedulePayload(
                        $schedule
                    )
                )
                ->values()
                ->all(),

            'sessions' =>
            $sessions
                ->map(
                    fn(
                        ClassSession $session
                    ): array =>
                    $this->sessionPayload(
                        $session
                    )
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Resolve the exact persisted Active Teacher account.
     *
     * This service remains fail-closed independently from route
     * middleware so future controllers, APIs, commands, or other
     * callers cannot accidentally bypass the Teacher boundary.
     *
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
                'Teacher Portal reads require a persisted User Account.'
            );
        }

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
                'Only an Active User Account may read Teacher Portal data.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Teacher
        ) {
            throw new AuthorizationException(
                'The account is not authorized for Teacher Portal reads.'
            );
        }

        if (
            ! $persistedActor->hasPermission(
                SystemPermission::ViewSchedules
            )
            || ! $persistedActor->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        ) {
            throw new AuthorizationException(
                'The Teacher account does not have the required academic read permissions.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Teacher Portal reads require a Center-scoped tenant context.'
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
                'Teacher Portal reads require a linked Person identity.'
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
                'Teacher Portal reads require an Active Teacher record linked to this exact account and Person.'
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
            $actor->account_login_identifier,

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
    private function classPayload(
        CourseClass $courseClass,
        int $enrollmentCount
    ): array {
        $course =
            $courseClass
            ->course;

        $branch =
            $courseClass
            ->branch;

        if ($course === null) {
            throw new LogicException(
                'The Teacher Course Class references a missing Course.'
            );
        }

        if ($branch === null) {
            throw new LogicException(
                'The Teacher Course Class references a missing Branch.'
            );
        }

        return [
            'id' =>
            (int) $courseClass->id,

            'code' =>
            $courseClass
                ->class_code,

            'name' =>
            $courseClass->name,

            'start_date' =>
            $courseClass
                ->start_date
                ?->toDateString(),

            'end_date' =>
            $courseClass
                ->end_date
                ?->toDateString(),

            'capacity' =>
            (int) $courseClass->capacity,

            'delivery_mode' =>
            $courseClass
                ->delivery_mode,

            'status' =>
            $courseClass
                ->class_status
                ->value,

            'status_label' =>
            $courseClass
                ->class_status
                ->label(),

            'enrollment_count' =>
            $enrollmentCount,

            'course' => [
                'id' =>
                (int) $course->id,

                'code' =>
                $course->code,

                'name' =>
                $course->name,
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
                $courseClass
                    ->assignedClassroom
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterPayload(
        Enrollment $enrollment
    ): array {
        $student =
            $enrollment
            ->student;

        if (! $student instanceof Student) {
            throw new LogicException(
                'The Teacher Class Enrollment references a missing Student.'
            );
        }

        $person =
            $student
            ->person;

        if ($person === null) {
            throw new LogicException(
                'The Teacher Class Student references a missing Person identity.'
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

            'student' => [
                'id' =>
                (int) $student->id,

                'person_id' =>
                (int) $student->person_id,

                /*
                 * Deliberately expose only class-relevant identity.
                 *
                 * National ID, email, phone, recovery email,
                 * personal-picture storage path, and User Account
                 * internals are not part of the Teacher roster.
                 */
                'name' =>
                $person->full_name,

                'status' =>
                $student
                    ->status
                    ->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(
        ClassSchedule $schedule
    ): array {
        $courseClass =
            $schedule
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Teacher Schedule references a missing Course Class.'
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
                'The Teacher Schedule Course Class references missing academic data.'
            );
        }

        return [
            'schedule_id' =>
            (int) $schedule->id,

            'class_id' =>
            (int) $courseClass->id,

            'day_of_week' =>
            (int) $schedule->day_of_week,

            'start_time' =>
            $schedule->start_time,

            'end_time' =>
            $schedule->end_time,

            'effective_from' =>
            $schedule
                ->effective_from
                ?->toDateString(),

            'effective_until' =>
            $schedule
                ->effective_until
                ?->toDateString(),

            'status' =>
            $schedule
                ->status
                ->value,

            'status_label' =>
            $schedule
                ->status
                ->label(),

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
                $schedule->classroom
            ),
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
                'The Teacher Session references a missing Course Class.'
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
                'The Teacher Session Course Class references missing academic data.'
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

            /*
             * session_date is authoritative for the concrete
             * instructional occurrence.
             */
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
