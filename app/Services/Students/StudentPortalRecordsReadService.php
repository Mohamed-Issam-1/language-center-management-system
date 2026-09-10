<?php

namespace App\Services\Students;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Reports\DashboardReadService;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class StudentPortalRecordsReadService
{
    public function __construct(
        private readonly DashboardReadService $dashboardReports,
        private readonly AttendanceCalculationService $attendance,
    ) {
    }

    /**
     * Student schedule.
     *
     * @return array<string, mixed>
     */
    public function scheduleForUser(
        User $actor
    ): array {
        [
            'student' => $student,
        ] = $this->studentContext($actor);

        $center = $student->center;
        $branch = $student->branch;

        $timezone =
            $center?->timezone
            ?: config('app.timezone');

        $today =
            CarbonImmutable::now($timezone)
                ->startOfDay();

        /*
         * The current schedule only needs active
         * Student enrollments.
         */
        $enrollments =
            Enrollment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->where(
                    'enrollment_status',
                    EnrollmentStatus::Active->value
                )
                ->with([
                    'courseClass' =>
                        function ($query): void {
                            $query
                                ->withoutGlobalScopes()
                                ->with([
                                    'course' =>
                                        fn ($courseQuery) =>
                                        $courseQuery
                                            ->withoutGlobalScopes(),

                                    'assignedTeacher' =>
                                        fn ($teacherQuery) =>
                                        $teacherQuery
                                            ->withoutGlobalScopes(),

                                    'assignedClassroom' =>
                                        fn ($classroomQuery) =>
                                        $classroomQuery
                                            ->withoutGlobalScopes(),
                                ]);
                        },
                ])
                ->orderBy('id')
                ->get();

        $courseOptions = [];
        $classPresentation = [];

        foreach ($enrollments as $enrollment) {
            $courseClass =
                $enrollment->courseClass;

            if ($courseClass === null) {
                continue;
            }

            $course =
                $courseClass->course;

            if ($course === null) {
                continue;
            }

            $courseName =
                $course->name
                ?: $courseClass->name
                ?: 'Course';

            $teacher =
                $this->teacherName(
                    $courseClass->assignedTeacher
                )
                ?: 'Teacher not assigned';

            $room =
                $courseClass
                    ->assignedClassroom
                    ?->name
                ?: 'Room not assigned';

            $courseOptions[] = [
                /*
                 * Enrollment ID is the Student-facing
                 * identifier already used by My Courses.
                 */
                'id' =>
                    (int) $enrollment->id,

                'label' =>
                    $courseName,
            ];

            $classPresentation[
                (int) $courseClass->id
            ] = [
                'courseId' =>
                    (int) $enrollment->id,

                'courseName' =>
                    $courseName,

                'teacher' =>
                    $teacher,

                'room' =>
                    $room,
            ];
        }

        $classIds =
            array_keys(
                $classPresentation
            );

        if ($classIds === []) {
            $sessions = collect();
        } else {
            $sessions =
                ClassSession::query()
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $student->center_id
                    )
                    ->whereIn(
                        'class_id',
                        $classIds
                    )
                    ->with([
                        'classroom' =>
                            fn ($query) =>
                            $query
                                ->withoutGlobalScopes(),

                        'teacher' =>
                            fn ($query) =>
                            $query
                                ->withoutGlobalScopes(),
                    ])
                    ->get();
        }

        $sessionRows =
            $sessions
                ->map(
                    function (
                        ClassSession $session
                    ) use (
                        $classPresentation,
                        $timezone
                    ): ?array {
                        $presentation =
                            $classPresentation[
                                (int) $session->class_id
                            ] ?? null;

                        if ($presentation === null) {
                            return null;
                        }

                        $date =
                            $this->sessionDate(
                                $session,
                                $timezone
                            );

                        if ($date === null) {
                            return null;
                        }

                        $status =
                            match (
                                $session->session_status
                            ) {
                                ClassSessionStatus::Scheduled =>
                                    'Upcoming',

                                ClassSessionStatus::Completed =>
                                    'Completed',

                                ClassSessionStatus::Cancelled =>
                                    'Cancelled',

                                default =>
                                    null,
                            };

                        if ($status === null) {
                            return null;
                        }

                        $courseName =
                            (string) $presentation[
                                'courseName'
                            ];

                        $shortCourseName =
                            preg_split(
                                '/\s+[–—-]\s+/u',
                                $courseName,
                                2
                            )[0]
                            ?? $courseName;

                        $teacher =
                            $this->teacherName(
                                $session->teacher
                            )
                            ?: (string) $presentation[
                                'teacher'
                            ];

                        $room =
                            $session
                                ->classroom
                                ?->name
                            ?: (string) $presentation[
                                'room'
                            ];

                        $startTime =
                            $this->formatTime(
                                (string)
                                $session->start_time
                            );

                        return [
                            'id' =>
                                (int) $session->id,

                            'courseId' =>
                                (int) $presentation[
                                    'courseId'
                                ],

                            'courseName' =>
                                $courseName,

                            'teacher' =>
                                $teacher,

                            'room' =>
                                $room,

                            'date' =>
                                $date->toDateString(),

                            'time' =>
                                $startTime
                                . ' – '
                                . $this->formatTime(
                                    (string)
                                    $session->end_time
                                ),

                            'calendarLabel' =>
                                trim(
                                    $shortCourseName
                                    . ' '
                                    . $startTime
                                ),

                            'status' =>
                                $status,
                        ];
                    }
                )
                ->filter()
                ->sortBy(
                    fn (array $session): string =>
                    $session['date']
                    . ' '
                    . $session['time']
                )
                ->values()
                ->all();

        return [
            'student' =>
                $this->studentIdentity(
                    $actor,
                    $student
                ),

            'center' => [
                'name' =>
                    $center?->name
                    ?: 'Language Center',

                'branch' =>
                    $branch?->name
                    ?: 'Branch',
            ],

            'today' =>
                $today->toDateString(),

            'courseOptions' =>
                $courseOptions,

            'sessions' =>
                $sessionRows,
        ];
    }

    /**
     * Student attendance.
     *
     * @return array<string, mixed>
     */
    public function attendanceForUser(
        User $actor
    ): array {
        [
            'student' => $student,
            'report' => $reportDashboard,
        ] = $this->studentContext($actor);

        $center =
            $student->center;

        $branch =
            $student->branch;

        $timezone =
            $center?->timezone
            ?: config('app.timezone');

        $now =
            CarbonImmutable::now(
                $timezone
            );

        /*
         * Attendance is historical information, so we
         * retain Active, Completed, Withdrawn and
         * Transferred Enrollment history.
         *
         * Cancelled Enrollment records are excluded.
         */
        $enrollments =
            Enrollment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->where(
                    'enrollment_status',
                    '!=',
                    EnrollmentStatus::Cancelled->value
                )
                ->with([
                    'courseClass' =>
                        function ($query): void {
                            $query
                                ->withoutGlobalScopes()
                                ->with([
                                    'course' =>
                                        fn ($courseQuery) =>
                                        $courseQuery
                                            ->withoutGlobalScopes(),
                                ]);
                        },
                ])
                ->orderBy('id')
                ->get();

        $courseSummaries = [];
        $policyIssues = [];

        foreach (
            $enrollments as $enrollment
        ) {
            $courseClass =
                $enrollment->courseClass;

            $course =
                $courseClass?->course;

            if (
                $courseClass === null
                || $course === null
            ) {
                continue;
            }

            $courseName =
                $course->name
                ?: $courseClass->name
                ?: 'Course';

            /*
             * Keep the authoritative attendance percentage
             * from AttendanceCalculationService.
             */
            $metrics =
                $this->attendance
                    ->forEnrollment(
                        $actor,
                        $enrollment
                    );

            $rate =
                $metrics[
                    'attendance_percentage'
                ] ?? null;

            $rate =
                $rate === null
                    ? 0
                    : (int) round(
                        (float) $rate
                    );

            $courseSummaries[
                (int) $enrollment->id
            ] = [
                'id' =>
                    (int) $enrollment->id,

                'courseName' =>
                    $courseName,

                'rate' =>
                    $rate,

                'present' =>
                    0,

                'absent' =>
                    0,

                'late' =>
                    0,
            ];

            if (
                ($metrics[
                    'meets_minimum_attendance'
                ] ?? null) === false
                && $metrics[
                    'minimum_attendance'
                ] !== null
            ) {
                $policyIssues[] = [
                    'course' =>
                        $courseName,

                    'rate' =>
                        $rate,

                    'minimum' =>
                        (float) $metrics[
                            'minimum_attendance'
                        ],
                ];
            }
        }

        $enrollmentIds =
            array_keys(
                $courseSummaries
            );

        $attendanceRecords =
            $enrollmentIds === []
                ? collect()
                : Attendance::query()
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $student->center_id
                    )
                    ->whereIn(
                        'enrollment_id',
                        $enrollmentIds
                    )
                    ->with([
                        'attendanceStatus' =>
                            fn ($query) =>
                            $query
                                ->withoutGlobalScopes(),

                        'session' =>
                            fn ($query) =>
                            $query
                                ->withoutGlobalScopes(),
                    ])
                    ->get();

        $present = 0;
        $absent = 0;
        $late = 0;
        $excused = 0;

        $records = [];

        /*
         * W1 is the oldest displayed week.
         * W8 is the current week.
         */
        $weekStart =
            $now
                ->startOfWeek()
                ->subWeeks(7);

        $weekEnd =
            $now->endOfWeek();

        $weekly = [];

        for (
            $index = 0;
            $index < 8;
            $index++
        ) {
            $weekly[] = [
                'week' =>
                    'W' . ($index + 1),

                'present' =>
                    0,

                'absent' =>
                    0,
            ];
        }

        foreach (
            $attendanceRecords as $record
        ) {
            $enrollmentId =
                (int) $record
                    ->enrollment_id;

            if (
                ! isset(
                    $courseSummaries[
                        $enrollmentId
                    ]
                )
            ) {
                continue;
            }

            $status =
                $this->attendanceStatus(
                    $record
                );

            /*
             * Unknown custom center statuses are not
             * incorrectly relabelled as one of the four
             * Student-portal visual statuses.
             */
            if ($status === null) {
                continue;
            }

            switch ($status) {
                case 'Present':
                    $present++;
                    $courseSummaries[
                        $enrollmentId
                    ]['present']++;
                    break;

                case 'Absent':
                    $absent++;
                    $courseSummaries[
                        $enrollmentId
                    ]['absent']++;
                    break;

                case 'Late':
                    $late++;
                    $courseSummaries[
                        $enrollmentId
                    ]['late']++;
                    break;

                case 'Excused':
                    $excused++;
                    break;
            }

            $date =
                $this->attendanceDate(
                    $record,
                    $timezone
                );

            if ($date === null) {
                continue;
            }

            $note =
                trim(
                    (string) (
                        $record->excuse
                        ?: $record->notes
                        ?: ''
                    )
                );

            $records[] = [
                'id' =>
                    (int) $record->id,

                'date' =>
                    $date->format(
                        'M j, Y'
                    ),

                'sortDate' =>
                    $date->toDateString(),

                'day' =>
                    $date->format('D'),

                'course' =>
                    $courseSummaries[
                        $enrollmentId
                    ]['courseName'],

                'status' =>
                    $status,

                'note' =>
                    $note,
            ];

            if (
                $date->lessThan(
                    $weekStart
                )
                || $date->greaterThan(
                    $weekEnd
                )
            ) {
                continue;
            }

            $daysFromStart =
                (int) floor(
                    $weekStart
                        ->diffInDays(
                            $date
                        )
                );

            $weekIndex =
                intdiv(
                    $daysFromStart,
                    7
                );

            if (
                $weekIndex < 0
                || $weekIndex > 7
            ) {
                continue;
            }

            if ($status === 'Present') {
                $weekly[
                    $weekIndex
                ]['present']++;
            }

            if ($status === 'Absent') {
                $weekly[
                    $weekIndex
                ]['absent']++;
            }
        }

        usort(
            $records,
            fn (
                array $left,
                array $right
            ): int =>
            strcmp(
                $right['sortDate'],
                $left['sortDate']
            )
        );

        $overallRate =
            $reportDashboard[
                'attendance'
            ][
                'attendance_percentage'
            ] ?? null;

        $overallRate =
            $overallRate === null
                ? 0
                : (int) round(
                    (float) $overallRate
                );

        /*
         * There is no approved absolute "6 absences"
         * setting in the current backend.
         *
         * The actual policy stored by LCMS is the
         * Course minimum_attendance percentage.
         */
        $alert = null;

        if ($policyIssues !== []) {
            usort(
                $policyIssues,
                fn (
                    array $left,
                    array $right
                ): int =>
                $left['rate']
                <=> $right['rate']
            );

            $issue =
                $policyIssues[0];

            $minimum =
                rtrim(
                    rtrim(
                        number_format(
                            $issue['minimum'],
                            2,
                            '.',
                            ''
                        ),
                        '0'
                    ),
                    '.'
                );

            $alert = [
                'title' =>
                    'Attendance Below Required Minimum',

                'message' =>
                    sprintf(
                        '%s attendance is %d%%. The approved minimum is %s%%. Continued absences may affect your enrollment status.',
                        $issue['course'],
                        $issue['rate'],
                        $minimum
                    ),
            ];
        }

        return [
            'student' =>
                $this->studentIdentity(
                    $actor,
                    $student
                ),

            'center' => [
                'name' =>
                    $center?->name
                    ?: 'Language Center',

                'branch' =>
                    $branch?->name
                    ?: 'Branch',
            ],

            'summary' => [
                'averageRate' =>
                    $overallRate,

                'present' =>
                    $present,

                'absent' =>
                    $absent,

                'late' =>
                    $late,

                'excused' =>
                    $excused,

                /*
                 * Kept nullable because there is no approved
                 * absolute maximum-absence policy field.
                 */
                'maximumAbsences' =>
                    null,
            ],

            'alert' =>
                $alert,

            'courses' =>
                array_values(
                    $courseSummaries
                ),

            'weekly' =>
                $weekly,

            'records' =>
                $records,
        ];
    }

    /**
     * @return array{
     *     student: Student,
     *     report: array<string, mixed>
     * }
     */
    private function studentContext(
        User $actor
    ): array {
        if (
            $actor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'Only a Student account may access Student portal records.'
            );
        }

        $report =
            $this->dashboardReports
                ->forUser($actor);

        $scope =
            $report['scope']
            ?? [];

        if (
            ($scope['scope_type'] ?? null)
            !== 'student'
            || ($scope['center_id'] ?? null)
            === null
            || ($scope['subject_id'] ?? null)
            === null
        ) {
            throw new AuthorizationException(
                'Student reporting scope could not be resolved.'
            );
        }

        $student =
            Student::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    (int) $scope[
                        'subject_id'
                    ]
                )
                ->where(
                    'center_id',
                    (int) $scope[
                        'center_id'
                    ]
                )
                ->where(
                    'user_id',
                    $actor->id
                )
                ->with([
                    'center',

                    'branch' =>
                        fn ($query) =>
                        $query
                            ->withoutGlobalScopes(),

                    'person' =>
                        fn ($query) =>
                        $query
                            ->withoutGlobalScopes(),
                ])
                ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'The Student record could not be resolved.'
            );
        }

        return [
            'student' =>
                $student,

            'report' =>
                $report,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     studentId: string
     * }
     */
    private function studentIdentity(
        User $actor,
        Student $student,
    ): array {
        return [
            'name' =>
                $student
                    ->person
                    ?->full_name
                ?: $actor->name
                ?: 'Student',

            'studentId' =>
                $actor
                    ->account_login_identifier
                ?: (string)
                    $student->id,
        ];
    }

    private function attendanceStatus(
        Attendance $record
    ): ?string {
        $attendanceStatus =
            $record->attendanceStatus;

        if ($attendanceStatus === null) {
            return null;
        }

        $value =
            strtolower(
                trim(
                    (string) (
                        $attendanceStatus->code
                        . ' '
                        . $attendanceStatus->name
                    )
                )
            );

        if (
            str_contains(
                $value,
                'excused'
            )
        ) {
            return 'Excused';
        }

        if (
            str_contains(
                $value,
                'absent'
            )
            || str_contains(
                $value,
                'absence'
            )
        ) {
            return 'Absent';
        }

        if (
            str_contains(
                $value,
                'late'
            )
            || (int) $record
                ->late_minutes > 0
        ) {
            return 'Late';
        }

        if (
            str_contains(
                $value,
                'present'
            )
        ) {
            return 'Present';
        }

        return null;
    }

    private function attendanceDate(
        Attendance $record,
        string $timezone,
    ): ?CarbonImmutable {
        $sessionDate =
            $record
                ->session
                ?->occurrence_date
            ?? $record
                ->session
                ?->session_date;

        if ($sessionDate !== null) {
            $value =
                $sessionDate
                instanceof DateTimeInterface
                    ? $sessionDate
                        ->format('Y-m-d')
                    : (string)
                        $sessionDate;

            return CarbonImmutable::parse(
                $value,
                $timezone
            )->startOfDay();
        }

        if ($record->recorded_at === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $record
                ->recorded_at
                ->toIso8601String()
        )
            ->setTimezone(
                $timezone
            )
            ->startOfDay();
    }

    private function sessionDate(
        ClassSession $session,
        string $timezone,
    ): ?CarbonImmutable {
        $value =
            $session->occurrence_date
            ?? $session->session_date;

        if ($value === null) {
            return null;
        }

        $date =
            $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : (string) $value;

        return CarbonImmutable::parse(
            $date,
            $timezone
        )->startOfDay();
    }

    private function teacherName(
        mixed $teacher
    ): ?string {
        if ($teacher === null) {
            return null;
        }

        if (
            method_exists(
                $teacher,
                'person'
            )
        ) {
            $teacher->loadMissing([
                'person' =>
                    fn ($query) =>
                    $query
                        ->withoutGlobalScopes(),
            ]);

            $name =
                $teacher
                    ->person
                    ?->full_name;

            if (
                is_string($name)
                && trim($name) !== ''
            ) {
                return trim($name);
            }
        }

        if (
            method_exists(
                $teacher,
                'user'
            )
        ) {
            $teacher->loadMissing([
                'user' =>
                    fn ($query) =>
                    $query
                        ->withoutGlobalScopes(),
            ]);

            $name =
                $teacher
                    ->user
                    ?->name;

            if (
                is_string($name)
                && trim($name) !== ''
            ) {
                return trim($name);
            }
        }

        return null;
    }

    private function formatTime(
        string $value
    ): string {
        try {
            return CarbonImmutable::parse(
                $value
            )->format('g:i A');
        } catch (Throwable) {
            return $value;
        }
    }
}