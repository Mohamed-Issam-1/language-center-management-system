<?php

namespace App\Services\Students;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class StudentAttendanceReadService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AttendanceCalculationService $calculations
    ) {}

    /**
     * Return the authenticated Student's Attendance history.
     *
     * Historical Enrollments remain visible intentionally.
     * Attendance history must not disappear after completion,
     * withdrawal, transfer, or cancellation of an Enrollment.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     enrollments: array<int, array<string, mixed>>,
     *     records: array<int, array<string, mixed>>
     * }
     */
    public function overview(
        User $actor
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $enrollments =
            $this->studentEnrollments(
                $context['student'],
                $context['center_id']
            );

        $enrollmentPayloads =
            $enrollments
            ->map(
                fn(
                    Enrollment $enrollment
                ): array =>
                $this->enrollmentSummaryPayload(
                    $context['actor'],
                    $enrollment
                )
            )
            ->values()
            ->all();

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $context['student'],
                $context['center']
            ),

            'summary' =>
            $this->aggregateSummaries(
                $enrollmentPayloads
            ),

            'enrollments' =>
            $enrollmentPayloads,

            'records' =>
            $this->attendanceRecords(
                $context['student'],
                $context['center_id']
            ),
        ];
    }

    /**
     * Return Attendance belonging to one exact Student-owned
     * Enrollment.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     enrollment: array<string, mixed>,
     *     records: array<int, array<string, mixed>>
     * }
     */
    public function forEnrollment(
        User $actor,
        int $enrollmentId
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

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

                'courseClass.branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $context['center_id']
            )
            ->where(
                'student_id',
                $context['student']->id
            )
            ->whereKey(
                $enrollmentId
            )
            ->first();

        /*
         * Deliberately use one fail-closed result for:
         *
         * - missing Enrollment;
         * - another Student's Enrollment;
         * - another Center's Enrollment.
         *
         * The caller must not learn which condition occurred.
         */
        if ($enrollment === null) {
            throw new AuthorizationException(
                'The Enrollment is outside the authenticated Student scope.'
            );
        }

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $context['student'],
                $context['center']
            ),

            'enrollment' =>
            $this->enrollmentSummaryPayload(
                $context['actor'],
                $enrollment
            ),

            'records' =>
            $this->attendanceRecords(
                $context['student'],
                $context['center_id'],
                $enrollment->id
            ),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Enrollment>
     */
    private function studentEnrollments(
        Student $student,
        int $centerId
    ): \Illuminate\Database\Eloquent\Collection {
        return Enrollment::query()
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
    }

    /**
     * Reuse the authoritative Attendance calculation service.
     *
     * No contribution or minimum-attendance business rules are
     * reimplemented here.
     *
     * @return array<string, mixed>
     */
    private function enrollmentSummaryPayload(
        User $actor,
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

        $summary =
            $this->calculations
            ->forEnrollment(
                $actor,
                $enrollment
            );

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
                $courseClass
                    ->name,
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

            'summary' =>
            $summary,
        ];
    }

    /**
     * Return only valid Student/Enrollment/Session Attendance
     * pairs.
     *
     * Cancelled Sessions are deliberately excluded so detailed
     * records agree with AttendanceCalculationService summaries.
     *
     * Historical inactive Attendance Statuses are NOT filtered.
     * They remain part of the historical Attendance record.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attendanceRecords(
        Student $student,
        int $centerId,
        ?int $enrollmentId = null
    ): array {
        $query =
            Attendance::query()
            ->withoutGlobalScopes()
            ->select(
                'attendances.*'
            )
            ->join(
                'enrollments as e',
                function ($join) {
                    $join
                        ->on(
                            'e.id',
                            '=',
                            'attendances.enrollment_id'
                        )
                        ->on(
                            'e.center_id',
                            '=',
                            'attendances.center_id'
                        );
                }
            )
            ->join(
                'class_sessions as cs',
                function ($join) {
                    $join
                        ->on(
                            'cs.id',
                            '=',
                            'attendances.session_id'
                        )
                        ->on(
                            'cs.center_id',
                            '=',
                            'attendances.center_id'
                        );
                }
            )
            ->with([
                'attendanceStatus' =>
                fn($relation) =>
                $relation->withoutGlobalScopes(),

                'session' =>
                fn($relation) =>
                $relation->withoutGlobalScopes(),

                'enrollment' =>
                fn($relation) =>
                $relation->withoutGlobalScopes(),

                'enrollment.courseClass' =>
                fn($relation) =>
                $relation->withoutGlobalScopes(),

                'enrollment.courseClass.course' =>
                fn($relation) =>
                $relation->withoutGlobalScopes(),
            ])
            ->where(
                'attendances.center_id',
                $centerId
            )
            ->where(
                'e.student_id',
                $student->id
            )
            ->whereColumn(
                'e.class_id',
                'cs.class_id'
            )
            ->where(
                'cs.session_status',
                '!=',
                ClassSessionStatus::Cancelled
                    ->value
            );

        if ($enrollmentId !== null) {
            $query->where(
                'attendances.enrollment_id',
                $enrollmentId
            );
        }

        return $query
            ->orderByDesc(
                'cs.session_date'
            )
            ->orderByDesc(
                'cs.start_time'
            )
            ->orderByDesc(
                'attendances.id'
            )
            ->get()
            ->map(
                fn(
                    Attendance $attendance
                ): array =>
                $this->attendancePayload(
                    $attendance
                )
            )
            ->values()
            ->all();
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

        $session =
            $attendance
            ->session;

        $enrollment =
            $attendance
            ->enrollment;

        if (
            $status === null
            || $session === null
            || $enrollment === null
        ) {
            throw new LogicException(
                'The Attendance record references missing required data.'
            );
        }

        $courseClass =
            $enrollment
            ->courseClass;

        if ($courseClass === null) {
            throw new LogicException(
                'The Attendance Enrollment references a missing Course Class.'
            );
        }

        $course =
            $courseClass
            ->course;

        if ($course === null) {
            throw new LogicException(
                'The Attendance Course Class references a missing Course.'
            );
        }

        return [
            'attendance_id' =>
            (int) $attendance->id,

            'enrollment_id' =>
            (int) $enrollment->id,

            'session_id' =>
            (int) $session->id,

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
                $courseClass
                    ->name,
            ],

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
                 * Historical statuses may now be inactive.
                 * Their persisted historical meaning is retained.
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
     * Aggregate only already-authoritative per-Enrollment
     * AttendanceCalculationService outputs.
     *
     * @param array<int, array<string, mixed>> $enrollments
     *
     * @return array<string, mixed>
     */
    private function aggregateSummaries(
        array $enrollments
    ): array {
        $recordedSessions =
            0;

        $attendanceEquivalent =
            0.0;

        $absenceEquivalent =
            0.0;

        foreach (
            $enrollments
            as $enrollment
        ) {
            $summary =
                $enrollment['summary'];

            $recordedSessions +=
                (int) $summary['recorded_sessions'];

            $attendanceEquivalent +=
                (float) $summary['attendance_equivalent'];

            $absenceEquivalent +=
                (float) $summary['absence_equivalent'];
        }

        if ($recordedSessions === 0) {
            return [
                'enrollment_count' =>
                count(
                    $enrollments
                ),

                'recorded_sessions' =>
                0,

                'attendance_equivalent' =>
                '0.00',

                'absence_equivalent' =>
                '0.00',

                'attendance_percentage' =>
                null,

                'absence_percentage' =>
                null,
            ];
        }

        $attendancePercentage =
            (
                $attendanceEquivalent
                / $recordedSessions
            ) * 100;

        $absencePercentage =
            100
            - $attendancePercentage;

        return [
            'enrollment_count' =>
            count(
                $enrollments
            ),

            'recorded_sessions' =>
            $recordedSessions,

            'attendance_equivalent' =>
            number_format(
                $attendanceEquivalent,
                2,
                '.',
                ''
            ),

            'absence_equivalent' =>
            number_format(
                $absenceEquivalent,
                2,
                '.',
                ''
            ),

            'attendance_percentage' =>
            number_format(
                $attendancePercentage,
                2,
                '.',
                ''
            ),

            'absence_percentage' =>
            number_format(
                $absencePercentage,
                2,
                '.',
                ''
            ),
        ];
    }

    /**
     * Resolve the exact persisted Active Student account.
     *
     * The service remains fail-closed independently of route
     * middleware so it is safe for future non-route callers too.
     *
     * @return array{
     *     actor: User,
     *     student: Student,
     *     center: Center,
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
                'Student Attendance reads require a persisted User Account.'
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
                'Only an Active User Account may read Student Attendance.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'The account is not authorized for Student Attendance.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Student Attendance reads require a Center-scoped tenant context.'
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
                'Student Attendance requires a linked Person identity.'
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
                'Student Attendance requires an Active Student record linked to this exact account and Person.'
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
     * Keep the Student identity contract aligned with the
     * existing Student portal read service.
     *
     * @return array<string, mixed>
     */
    private function studentPayload(
        User $actor,
        Student $student,
        Center $center
    ): array {
        return [
            'id' =>
            (int) $student->id,

            'person_id' =>
            (int) $student->person_id,

            'user_id' =>
            (int) $student->user_id,

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
                (int) $student
                    ->branch_id,

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
}