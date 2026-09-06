<?php

namespace App\Services\Students;

use App\Models\ClassSession;
use App\Models\CourseClass;
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
use Illuminate\Support\Collection;
use Throwable;

final class StudentDashboardReadService
{
    private const DAY_NAMES = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    public function __construct(
        private readonly DashboardReadService $dashboardReports,
        private readonly AttendanceCalculationService $attendance,
    ) {
    }

    /**
     * Build the Student dashboard payload consumed by the
     * existing React Student dashboard.
     *
     * Attendance and financial calculations remain delegated
     * to the existing authoritative backend services.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $actor): array
    {
        if ($actor->systemRole() !== SystemRole::Student) {
            throw new AuthorizationException(
                'Only a Student account may use the Student dashboard.'
            );
        }

        $reportDashboard = $this->dashboardReports->forUser($actor);

        $scope = $reportDashboard['scope'];

        if (
            ($scope['scope_type'] ?? null) !== 'student'
            || ($scope['center_id'] ?? null) === null
            || ($scope['subject_id'] ?? null) === null
        ) {
            throw new AuthorizationException(
                'The Student dashboard requires Student reporting scope.'
            );
        }

        $centerId = (int) $scope['center_id'];
        $studentId = (int) $scope['subject_id'];

        $student = Student::query()
            ->withoutGlobalScopes()
            ->whereKey($studentId)
            ->where('center_id', $centerId)
            ->first();

        if (
            $student === null
            || $student->user_id !== $actor->id
        ) {
            throw new AuthorizationException(
                'The Student record does not belong to the authenticated account.'
            );
        }

        $student->load([
            'center',
            'branch' => fn ($query) =>
                $query->withoutGlobalScopes(),
            'person' => fn ($query) =>
                $query->withoutGlobalScopes(),
        ]);

        $center = $student->center;
        $branch = $student->branch;

        if ($center === null || $branch === null) {
            throw new AuthorizationException(
                'The Student dashboard requires a valid Center and Branch.'
            );
        }

        $timezone = $center->timezone
            ?: config('app.timezone');

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();

        $activeEnrollments = Enrollment::query()
            ->withoutGlobalScopes()
            ->where('center_id', $centerId)
            ->where('student_id', $student->id)
            ->where(
                'enrollment_status',
                EnrollmentStatus::Active->value
            )
            ->orderBy('id')
            ->get();

        $classIds = $activeEnrollments
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $classes = CourseClass::query()
            ->withoutGlobalScopes()
            ->where('center_id', $centerId)
            ->whereIn('id', $classIds)
            ->with([
                'course' => function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->with([
                            'academicLevel' =>
                                fn ($levelQuery) =>
                                $levelQuery->withoutGlobalScopes(),
                        ]);
                },

                'assignedClassroom' =>
                    fn ($query) =>
                    $query->withoutGlobalScopes(),

                'assignedTeacher' =>
                    fn ($query) =>
                    $query->withoutGlobalScopes(),

                'classSchedules' =>
                    fn ($query) =>
                    $query
                        ->withoutGlobalScopes()
                        ->orderBy('day_of_week')
                        ->orderBy('start_time'),
            ])
            ->get()
            ->keyBy('id');

        $courseRows = [];
        $attendanceRows = [];
        $classPresentation = [];
        $courseNamesByEnrollment = [];

        foreach (
            $activeEnrollments as $index => $enrollment
        ) {
            $courseClass = $classes->get(
                (int) $enrollment->class_id
            );

            if ($courseClass === null) {
                continue;
            }

            $course = $courseClass->course;

            if ($course === null) {
                continue;
            }

            $attendanceSummary =
                $this->attendance->forEnrollment(
                    $actor,
                    $enrollment
                );

            $attendancePercentage =
                $attendanceSummary['attendance_percentage']
                    ?? null;

            $attendancePercentage = $attendancePercentage === null
                ? 0
                : (int) round(
                    (float) $attendancePercentage
                );

            $teacherName =
                $this->teacherName(
                    $courseClass->assignedTeacher
                );

            $title = $course->name
                ?: $courseClass->name;

            $code =
                $course->academicLevel?->code
                ?: $course->code
                ?: $courseClass->class_code;

            $schedule = $this->formatSchedule(
                $courseClass->classSchedules
            );

            $courseRows[] = [
                'code' => $code,
                'title' => $title,
                'teacher' =>
                    $teacherName
                    ?: 'Teacher not assigned',
                'schedule' =>
                    $schedule
                    ?: 'Schedule not available',
                'attendance' =>
                    $attendancePercentage,
                'accent' =>
                    $index % 2 === 0
                        ? 'indigo'
                        : 'violet',
            ];

            $attendanceRows[] = [
                'title' => $title,
                'percentage' =>
                    $attendancePercentage,
                'minimum' =>
                    isset(
                        $attendanceSummary[
                            'minimum_attendance'
                        ]
                    )
                        ? (float) $attendanceSummary[
                            'minimum_attendance'
                        ]
                        : null,
                'meetsMinimum' =>
                    $attendanceSummary[
                        'meets_minimum_attendance'
                    ] ?? null,
            ];

            $classPresentation[
                (int) $courseClass->id
            ] = [
                'title' => $title,
                'teacher' =>
                    $teacherName
                    ?: 'Teacher not assigned',
                'room' =>
                    $courseClass
                        ->assignedClassroom
                        ?->name
                    ?: 'Room not assigned',
            ];

            $courseNamesByEnrollment[
                (int) $enrollment->id
            ] = $title;
        }

        $scheduledSessions =
            $this->scheduledSessions(
                centerId: $centerId,
                classIds: $classIds,
                today: $today,
            );

        $futureSessions = $scheduledSessions
            ->filter(
                function (
                    ClassSession $session
                ) use (
                    $now,
                    $timezone
                ): bool {
                    $startsAt =
                        $this->sessionStart(
                            $session,
                            $timezone
                        );

                    return $startsAt !== null
                        && $startsAt->greaterThanOrEqualTo(
                            $now
                        );
                }
            )
            ->sortBy(
                fn (
                    ClassSession $session
                ): int =>
                $this
                    ->sessionStart(
                        $session,
                        $timezone
                    )
                    ?->getTimestamp()
                ?? PHP_INT_MAX
            )
            ->values();

        $nextSessionModel =
            $futureSessions->first();

        $nextSession =
            $nextSessionModel instanceof ClassSession
                ? $this->sessionPayload(
                    session: $nextSessionModel,
                    classPresentation:
                        $classPresentation,
                    timezone: $timezone,
                    includeDate: true,
                    now: $now,
                )
                : null;

        $todaySessions = $scheduledSessions
            ->filter(
                function (
                    ClassSession $session
                ) use (
                    $today,
                    $timezone
                ): bool {
                    $date =
                        $this->sessionDate(
                            $session,
                            $timezone
                        );

                    return $date !== null
                        && $date->isSameDay(
                            $today
                        );
                }
            )
            ->sortBy(
                fn (
                    ClassSession $session
                ): string =>
                (string) $session->start_time
            )
            ->map(
                fn (
                    ClassSession $session
                ): array =>
                $this->sessionPayload(
                    session: $session,
                    classPresentation:
                        $classPresentation,
                    timezone: $timezone,
                    includeDate: false,
                    now: $now,
                )
            )
            ->values()
            ->all();

        $attendanceReport =
            $reportDashboard['attendance']
            ?? [];

        $averageAttendance =
            $attendanceReport[
                'attendance_percentage'
            ] ?? null;

        $averageAttendance =
            $averageAttendance === null
                ? 0
                : (int) round(
                    (float) $averageAttendance
                );

        $totalAbsent =
            (int) round(
                (float) (
                    $attendanceReport[
                        'absence_equivalent'
                    ] ?? 0
                )
            );

        $warningText =
            $this->attendanceWarning(
                $attendanceRows
            );

        $finance =
            $this->financialPayload(
                financeReport:
                    $reportDashboard['finance']
                    ?? [],
                operatingCurrency:
                    $center
                        ->operating_currency_code,
                courseNamesByEnrollment:
                    $courseNamesByEnrollment,
                today: $today,
            );

        $studentName =
            $student->person?->full_name
            ?: $actor->name
            ?: 'Student';

        $firstName =
            trim(
                explode(
                    ' ',
                    $studentName
                )[0] ?? $studentName
            );

        $greetingPeriod =
            match (true) {
                $now->hour < 12 =>
                    'morning',

                $now->hour < 17 =>
                    'afternoon',

                default =>
                    'evening',
            };

        return [
            'student' => [
                'name' =>
                    $studentName,

                'studentId' =>
                    $actor
                        ->account_login_identifier
                    ?: (string) $student->id,
            ],

            'center' => [
                'name' =>
                    $center->name,

                'branch' =>
                    $branch->name,
            ],

            'greeting' => [
                'dateLabel' =>
                    $now->format(
                        'l, F j, Y'
                    ),

                'title' =>
                    "Good {$greetingPeriod}, {$firstName}!",
            ],

            'stats' => [
                [
                    'label' =>
                        'Active Courses',

                    'value' =>
                        (string) count(
                            $courseRows
                        ),

                    'tone' =>
                        'blue',

                    'icon' =>
                        'courses',
                ],
                [
                    'label' =>
                        'Avg Attendance',

                    'value' =>
                        "{$averageAttendance}%",

                    'tone' =>
                        'cyan',

                    'icon' =>
                        'attendance',
                ],
                [
                    'label' =>
                        'Total Paid',

                    'value' =>
                        $this->moneyLabel(
                            $finance[
                                'currency'
                            ],
                            $finance[
                                'totalPaid'
                            ]
                        ),

                    'tone' =>
                        'cyan',

                    'icon' =>
                        'paid',
                ],
                [
                    'label' =>
                        'Outstanding',

                    'value' =>
                        $this->moneyLabel(
                            $finance[
                                'currency'
                            ],
                            $finance[
                                'remaining'
                            ]
                        ),

                    'tone' =>
                        'red',

                    'icon' =>
                        'outstanding',
                ],
            ],

            'courses' =>
                $courseRows,

            'nextSession' =>
                $nextSession,

            'todaySchedule' =>
                $todaySessions,

            'todayScheduleLabel' =>
                $now->format(
                    'D, M j'
                ),

            'attendance' => [
                'average' =>
                    $averageAttendance,

                'totalAbsent' =>
                    $totalAbsent,

                'warningText' =>
                    $warningText,

                /*
                 * The backend currently defines minimum
                 * attendance percentage per Course, not a
                 * single global maximum-absence count.
                 */
                'maximumAllowedAbsences' =>
                    null,
            ],

            'financial' =>
                $finance,
        ];
    }

    /**
     * @param Collection<int, mixed> $classIds
     *
     * @return Collection<int, ClassSession>
     */
    private function scheduledSessions(
        int $centerId,
        Collection $classIds,
        CarbonImmutable $today,
    ): Collection {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return ClassSession::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'class_id',
                $classIds
            )
            ->where(
                'session_status',
                ClassSessionStatus::Scheduled
                    ->value
            )
            ->where(
                function (
                    $query
                ) use (
                    $today
                ): void {
                    $query
                        ->whereDate(
                            'occurrence_date',
                            '>=',
                            $today
                                ->toDateString()
                        )
                        ->orWhere(
                            function (
                                $fallback
                            ) use (
                                $today
                            ): void {
                                $fallback
                                    ->whereNull(
                                        'occurrence_date'
                                    )
                                    ->whereDate(
                                        'session_date',
                                        '>=',
                                        $today
                                            ->toDateString()
                                    );
                            }
                        );
                }
            )
            ->with([
                'classroom' =>
                    fn ($query) =>
                    $query->withoutGlobalScopes(),

                'teacher' =>
                    fn ($query) =>
                    $query->withoutGlobalScopes(),
            ])
            ->get();
    }

    /**
     * @param array<int, array<string, mixed>> $classPresentation
     *
     * @return array<string, string>
     */
    private function sessionPayload(
        ClassSession $session,
        array $classPresentation,
        string $timezone,
        bool $includeDate,
        CarbonImmutable $now,
    ): array {
        $class =
            $classPresentation[
                (int) $session->class_id
            ] ?? [];

        $teacher =
            $this->teacherName(
                $session->teacher
            )
            ?: (
                $class['teacher']
                ?? 'Teacher not assigned'
            );

        $room =
            $session->classroom?->name
            ?: (
                $class['room']
                ?? 'Room not assigned'
            );

        $course =
            $class['title']
            ?? 'Course';

        $range =
            $this->formatTime(
                (string) $session
                    ->start_time
            )
            . ' – '
            . $this->formatTime(
                (string) $session
                    ->end_time
            );

        if ($includeDate) {
            $date =
                $this->sessionDate(
                    $session,
                    $timezone
                );

            if ($date !== null) {
                $dateLabel =
                    $date->isSameDay(
                        $now
                    )
                        ? 'Today'
                        : $date->format(
                            'D, M j'
                        );

                $range =
                    "{$dateLabel} · {$range}";
            }
        }

        return [
            'course' =>
                $course,

            'teacher' =>
                $teacher,

            'room' =>
                $room,

            'time' =>
                $range,
        ];
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

    private function sessionStart(
        ClassSession $session,
        string $timezone,
    ): ?CarbonImmutable {
        $date =
            $this->sessionDate(
                $session,
                $timezone
            );

        if ($date === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $date->format('Y-m-d')
            . ' '
            . (string) $session->start_time,
            $timezone
        );
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
                    $query->withoutGlobalScopes(),
            ]);

            $name =
                $teacher->person?->full_name;

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
                    $query->withoutGlobalScopes(),
            ]);

            $name =
                $teacher->user?->name;

            if (
                is_string($name)
                && trim($name) !== ''
            ) {
                return trim($name);
            }
        }

        return null;
    }

    private function formatSchedule(
        mixed $schedules
    ): string {
        if (
            $schedules === null
            || ! method_exists(
                $schedules,
                'filter'
            )
        ) {
            return '';
        }

        $active =
            $schedules
                ->filter(
                    fn ($schedule): bool =>
                    method_exists(
                        $schedule,
                        'isActive'
                    )
                        ? $schedule
                            ->isActive()
                        : true
                );

        if ($active->isEmpty()) {
            return '';
        }

        return $active
            ->groupBy(
                fn ($schedule): string =>
                (string) $schedule
                    ->start_time
                . '|'
                . (string) $schedule
                    ->end_time
            )
            ->map(
                function (
                    $group
                ): string {
                    $first =
                        $group->first();

                    $days =
                        $group
                            ->map(
                                fn (
                                    $schedule
                                ): string =>
                                self::DAY_NAMES[
                                    (int) $schedule
                                        ->day_of_week
                                ]
                                ?? '?'
                            )
                            ->implode(', ');

                    return $days
                        . ' · '
                        . $this->formatTime(
                            (string) $first
                                ->start_time
                        )
                        . ' – '
                        . $this->formatTime(
                            (string) $first
                                ->end_time
                        );
                }
            )
            ->implode(' | ');
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

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function attendanceWarning(
        array $rows
    ): ?string {
        foreach ($rows as $row) {
            if (
                ($row['meetsMinimum'] ?? null)
                !== false
            ) {
                continue;
            }

            $minimum =
                $row['minimum'];

            $percentage =
                $row['percentage'];

            return sprintf(
                'Your attendance in %s is %s%%. The required minimum is %s%%. Continued absences may affect your enrollment status.',
                $row['title'],
                $percentage,
                rtrim(
                    rtrim(
                        number_format(
                            (float) $minimum,
                            2,
                            '.',
                            ''
                        ),
                        '0'
                    ),
                    '.'
                )
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $financeReport
     * @param array<int, string> $courseNamesByEnrollment
     *
     * @return array<string, mixed>
     */
    private function financialPayload(
        array $financeReport,
        ?string $operatingCurrency,
        array $courseNamesByEnrollment,
        CarbonImmutable $today,
    ): array {
        $data =
            $financeReport['data']
            ?? [];

        $totals =
            is_array(
                $data['totals']
                ?? null
            )
                ? $data['totals']
                : [];

        $currency =
            strtoupper(
                trim(
                    (string) (
                        $operatingCurrency
                        ?: array_key_first(
                            $totals
                        )
                        ?: 'USD'
                    )
                )
            );

        $currencyTotals =
            $totals[$currency]
            ?? [
                'obligation' => '0.00',
                'paid' => '0.00',
                'balance' => '0.00',
            ];

        $installments =
            collect(
                $data['installments']
                ?? []
            )
            ->filter(
                fn ($row): bool =>
                strtoupper(
                    (string) (
                        $row[
                            'currency_code'
                        ] ?? ''
                    )
                ) === $currency
            );

        $overdue =
            $installments
                ->filter(
                    fn ($row): bool =>
                    (bool) (
                        $row[
                            'is_overdue'
                        ] ?? false
                    )
                )
                ->sum(
                    fn ($row): float =>
                    (float) (
                        $row['balance']
                        ?? 0
                    )
                );

        $next =
            $installments
                ->filter(
                    fn ($row): bool =>
                    (float) (
                        $row['balance']
                        ?? 0
                    ) > 0
                    && isset(
                        $row['due_date']
                    )
                    && $row['due_date']
                        >= $today
                            ->toDateString()
                )
                ->sortBy('due_date')
                ->first();

        return [
            'currency' =>
                $currency,

            'totalFees' =>
                (float) (
                    $currencyTotals[
                        'obligation'
                    ] ?? 0
                ),

            'totalPaid' =>
                (float) (
                    $currencyTotals[
                        'paid'
                    ] ?? 0
                ),

            'remaining' =>
                (float) (
                    $currencyTotals[
                        'balance'
                    ] ?? 0
                ),

            'overdue' =>
                (float) $overdue,

            'nextInstallment' =>
                $next !== null
                    ? (float) (
                        $next['balance']
                        ?? 0
                    )
                    : null,

            'nextInstallmentCourse' =>
                $next !== null
                    ? (
                        $courseNamesByEnrollment[
                            (int) (
                                $next[
                                    'enrollment_id'
                                ] ?? 0
                            )
                        ]
                        ?? (
                            $next[
                                'enrollment_number'
                            ] ?? null
                        )
                    )
                    : null,

            'nextInstallmentDue' =>
                $next !== null
                    ? CarbonImmutable::parse(
                        $next['due_date']
                    )->format(
                        'M j, Y'
                    )
                    : null,
        ];
    }

    private function moneyLabel(
        string $currency,
        float|int $amount,
    ): string {
        return $currency
            . ' '
            . number_format(
                (float) $amount,
                0,
                '.',
                ','
            );
    }
}