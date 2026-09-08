<?php

namespace App\Services\Students;

use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class StudentPortalReadService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Return the canonical academic records visible to the
     * authenticated Student account.
     *
     * Enrollment is deliberately used as the portal course-record
     * identity. A Student may repeat the same Course or move between
     * Classes over time, so Course ID alone is not sufficiently
     * specific for Student-facing records.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     enrollments: array<int, array<string, mixed>>
     * }
     */
    public function courses(
        User $actor
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $student =
            $context['student'];

        $centerId =
            $context['center_id'];

        $enrollments =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->with([
                'courseClass' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course.language' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course.academicLevel' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedTeacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedTeacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedClassroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules' =>
                fn($query) =>
                $query
                    ->withoutGlobalScopes()
                    ->orderBy(
                        'day_of_week'
                    )
                    ->orderBy(
                        'start_time'
                    )
                    ->orderBy(
                        'id'
                    ),

                'courseClass.classSchedules.teacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules.teacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules.classroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->orderByDesc(
                'enrollment_date'
            )
            ->orderByDesc(
                'id'
            )
            ->get();

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $student,
                $context['center']
            ),

            'enrollments' =>
            $enrollments
                ->map(
                    fn(
                        Enrollment $enrollment
                    ): array =>
                    $this->enrollmentPayload(
                        $enrollment
                    )
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return one Student-owned Enrollment with its authoritative
     * Course/Class details and concrete generated Sessions.
     *
     * Enrollment ID is the Student portal record identity.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     enrollment: array<string, mixed>,
     *     sessions: array<int, array<string, mixed>>
     * }
     */
    public function courseDetails(
        User $actor,
        int $enrollmentId
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $student =
            $context['student'];

        $centerId =
            $context['center_id'];

        $enrollment =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->with([
                'courseClass' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course.language' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.course.academicLevel' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedTeacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedTeacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.assignedClassroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules' =>
                fn($query) =>
                $query
                    ->withoutGlobalScopes()
                    ->orderBy(
                        'day_of_week'
                    )
                    ->orderBy(
                        'start_time'
                    )
                    ->orderBy(
                        'id'
                    ),

                'courseClass.classSchedules.teacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules.teacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSchedules.classroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions' =>
                fn($query) =>
                $query
                    ->withoutGlobalScopes()
                    ->orderBy(
                        'session_date'
                    )
                    ->orderBy(
                        'start_time'
                    )
                    ->orderBy(
                        'id'
                    ),

                'courseClass.classSessions.courseClass' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions.courseClass.course' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions.teacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions.teacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions.classroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'courseClass.classSessions.schedule' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->whereKey(
                $enrollmentId
            )
            ->first();

        /*
     * Do not reveal whether the supplied Enrollment belongs
     * to another Student or another Center.
     */
        if ($enrollment === null) {
            throw new AuthorizationException(
                'The Enrollment is outside the authenticated Student scope.'
            );
        }

        $courseClass =
            $enrollment
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Student Enrollment references a missing Course Class.'
            );
        }

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $student,
                $context['center']
            ),

            'enrollment' =>
            $this->enrollmentPayload(
                $enrollment
            ),

            'sessions' =>
            $courseClass
                ->classSessions
                ->map(
                    fn(
                        ClassSession $session
                    ): array =>
                    $this->sessionPayload(
                        $session,
                        $enrollment
                    )
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return concrete generated Class Sessions for Classes in
     * which the current Student has an Enrollment.
     *
     * This intentionally reads ClassSession rather than projecting
     * recurring ClassSchedule rules into synthetic occurrences.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     sessions: array<int, array<string, mixed>>
     * }
     */
    public function schedule(
        User $actor
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $student =
            $context['student'];

        $centerId =
            $context['center_id'];

        $enrollments =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'enrollment_status',
                EnrollmentStatus::Active->value
            )
            ->orderBy(
                'id'
            )
            ->get([
                'id',
                'class_id',
            ]);

        if ($enrollments->isEmpty()) {
            return [
                'student' =>
                $this->studentPayload(
                    $context['actor'],
                    $student,
                    $context['center']
                ),

                'sessions' => [],
            ];
        }

        /*
         * The Enrollment ID is the Student portal's stable
         * course-record identity.
         */
        $enrollmentByClass =
            $enrollments
            ->keyBy(
                'class_id'
            );

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

                'teacher' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'teacher.person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'classroom' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'schedule' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'class_id',
                $enrollmentByClass
                    ->keys()
                    ->all()
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
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $student,
                $context['center']
            ),

            'sessions' =>
            $sessions
                ->map(
                    function (
                        ClassSession $session
                    ) use (
                        $enrollmentByClass
                    ): array {
                        $enrollment =
                            $enrollmentByClass
                            ->get(
                                $session->class_id
                            );

                        if (
                            ! $enrollment
                                instanceof Enrollment
                        ) {
                            throw new LogicException(
                                'A Student portal Session could not be matched to its Enrollment.'
                            );
                        }

                        return $this
                            ->sessionPayload(
                                $session,
                                $enrollment
                            );
                    }
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Resolve the authoritative Student account and exact linked
     * operational Student record.
     *
     * Never trust mutable in-memory User state supplied by the
     * caller. The account is re-read before authorization.
     *
     * @return array{
     *     actor: User,
     *     student: Student,
     *     center: \App\Models\Center,
     *     center_id: int
     * }
     */
    private function authorizedStudentContext(
        User $actor
    ): array {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Student portal reads require a persisted User Account.'
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
                'The Student User Account could not be resolved.'
            );
        }

        if (
            $persistedActor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an Active User Account may read the Student portal.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'The account is not authorized for the Student portal.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Student portal reads require a Center-scoped tenant context.'
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
                'Authenticated Student account and tenant context do not match.'
            );
        }

        if (
            $persistedActor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Student portal access requires a linked Person identity.'
            );
        }

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->with([
                'person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'branch' =>
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
                StudentStatus::Active->value
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'Student portal access requires an Active Student record linked to this exact account and Person.'
            );
        }

        return [
            'actor' =>
            $persistedActor,

            'student' =>
            $student,

            'center' =>
            $center,

            'center_id' =>
            (int) $centerId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentPayload(
        User $actor,
        Student $student,
        \App\Models\Center $center
    ): array {
        return [
            'id' =>
            (int) $student->id,

            'person_id' =>
            (int) $student->person_id,

            'user_id' =>
            (int) $student->user_id,

            /*
             * LCMS currently does not have a separate
             * Student-number domain field. Do not invent one.
             */
            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            'name' =>
            $student
                ->person
                ?->full_name
                ?? $actor->name,

            'status' =>
            $student
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

            'branch' => [
                'id' =>
                (int) $student->branch_id,

                'code' =>
                $student
                    ->branch
                    ?->code,

                'name' =>
                $student
                    ->branch
                    ?->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentPayload(
        Enrollment $enrollment
    ): array {
        $courseClass =
            $enrollment
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Student Enrollment references a missing Course Class.'
            );
        }

        $course =
            $courseClass
            ->course;

        if ($course === null) {
            throw new LogicException(
                'The Student Enrollment Course Class references a missing Course.'
            );
        }

        $teacher =
            $courseClass
            ->assignedTeacher;

        $classroom =
            $courseClass
            ->assignedClassroom;

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

            'status' =>
            $enrollment
                ->enrollment_status
                ->value,

            'status_label' =>
            $enrollment
                ->enrollment_status
                ->label(),

            'eligibility_status' =>
            $enrollment
                ->eligibility_status,

            'withdrawal_date' =>
            $enrollment
                ->withdrawal_date
                ?->toDateString(),

            'withdrawal_reason' =>
            $enrollment
                ->withdrawal_reason,

            'course' => [
                'id' =>
                (int) $course->id,

                'code' =>
                $course->code,

                'name' =>
                $course->name,

                'duration_weeks' =>
                (int) $course
                    ->duration_weeks,

                'total_hours' =>
                (string) $course
                    ->total_hours,

                'minimum_attendance' =>
                $course->minimum_attendance
                    === null
                    ? null
                    : (string) $course
                        ->minimum_attendance,

                'language' =>
                $course->language
                    ? [
                        'id' =>
                        (int) $course
                            ->language
                            ->id,

                        'code' =>
                        $course
                            ->language
                            ->code,

                        'name' =>
                        $course
                            ->language
                            ->name,
                    ]
                    : null,

                'academic_level' =>
                $course->academicLevel
                    ? [
                        'id' =>
                        (int) $course
                            ->academicLevel
                            ->id,

                        'code' =>
                        $course
                            ->academicLevel
                            ->code,

                        'name' =>
                        $course
                            ->academicLevel
                            ->name,
                    ]
                    : null,
            ],

            'class' => [
                'id' =>
                (int) $courseClass->id,

                'code' =>
                $courseClass
                    ->class_code,

                'name' =>
                $courseClass
                    ->name,

                'delivery_mode' =>
                $courseClass
                    ->delivery_mode,

                'start_date' =>
                $courseClass
                    ->start_date
                    ?->toDateString(),

                'end_date' =>
                $courseClass
                    ->end_date
                    ?->toDateString(),

                'status' =>
                $courseClass
                    ->class_status
                    ->value,

                'status_label' =>
                $courseClass
                    ->class_status
                    ->label(),
            ],

            'branch' =>
            $courseClass->branch
                ? [
                    'id' =>
                    (int) $courseClass
                        ->branch
                        ->id,

                    'code' =>
                    $courseClass
                        ->branch
                        ->code,

                    'name' =>
                    $courseClass
                        ->branch
                        ->name,
                ]
                : null,

            'assigned_teacher' =>
            $teacher
                ? [
                    'id' =>
                    (int) $teacher->id,

                    'name' =>
                    $teacher
                        ->person
                        ?->full_name,
                ]
                : null,

            'assigned_classroom' =>
            $classroom
                ? [
                    'id' =>
                    (int) $classroom->id,

                    'code' =>
                    $classroom->code,

                    'name' =>
                    $classroom->name,

                    'location' =>
                    $classroom->location,
                ]
                : null,

            'schedules' =>
            $courseClass
                ->classSchedules
                ->map(
                    function (
                        $schedule
                    ): array {
                        return [
                            'id' =>
                            (int) $schedule->id,

                            'day_of_week' =>
                            (int) $schedule
                                ->day_of_week,

                            'start_time' =>
                            $schedule
                                ->start_time,

                            'end_time' =>
                            $schedule
                                ->end_time,

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

                            'teacher' =>
                            $schedule->teacher
                                ? [
                                    'id' =>
                                    (int) $schedule
                                        ->teacher
                                        ->id,

                                    'name' =>
                                    $schedule
                                        ->teacher
                                        ->person
                                        ?->full_name,
                                ]
                                : null,

                            'classroom' =>
                            $schedule->classroom
                                ? [
                                    'id' =>
                                    (int) $schedule
                                        ->classroom
                                        ->id,

                                    'code' =>
                                    $schedule
                                        ->classroom
                                        ->code,

                                    'name' =>
                                    $schedule
                                        ->classroom
                                        ->name,

                                    'location' =>
                                    $schedule
                                        ->classroom
                                        ->location,
                                ]
                                : null,
                        ];
                    }
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(
        ClassSession $session,
        Enrollment $enrollment
    ): array {
        $courseClass =
            $session
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Student Session references a missing Course Class.'
            );
        }

        $course =
            $courseClass
            ->course;

        if ($course === null) {
            throw new LogicException(
                'The Student Session Course Class references a missing Course.'
            );
        }

        return [
            'session_id' =>
            (int) $session->id,

            'enrollment_id' =>
            (int) $enrollment->id,

            'class_id' =>
            (int) $session->class_id,

            'course_id' =>
            (int) $course->id,

            'course_code' =>
            $course->code,

            'course_name' =>
            $course->name,

            'class_code' =>
            $courseClass
                ->class_code,

            'schedule_id' =>
            (int) $session
                ->schedule_id,

            'session_date' =>
            $session
                ->session_date
                ?->toDateString(),

            'start_time' =>
            $session
                ->start_time,

            'end_time' =>
            $session
                ->end_time,

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

            /*
             * Session resources are authoritative here because a
             * concrete Session may differ from the recurring
             * Schedule's default Teacher or Classroom.
             */
            'teacher' =>
            $session->teacher
                ? [
                    'id' =>
                    (int) $session
                        ->teacher
                        ->id,

                    'name' =>
                    $session
                        ->teacher
                        ->person
                        ?->full_name,
                ]
                : null,

            'classroom' =>
            $session->classroom
                ? [
                    'id' =>
                    (int) $session
                        ->classroom
                        ->id,

                    'code' =>
                    $session
                        ->classroom
                        ->code,

                    'name' =>
                    $session
                        ->classroom
                        ->name,

                    'location' =>
                    $session
                        ->classroom
                        ->location,
                ]
                : null,
        ];
    }
}
