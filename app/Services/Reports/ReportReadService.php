<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Finance\FinanceReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ReportReadService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AttendanceCalculationService $attendance,
        private readonly FinanceReadService $finance
    ) {}

    /**
     * Resolve the authoritative reporting scope for the
     * authenticated account.
     *
     * This method intentionally returns scope metadata only.
     * Report aggregation is added on top of this contract so
     * Filament and Inertia cannot implement different tenancy
     * rules.
     *
     * @return array{
     *     actor_id: int,
     *     role: string,
     *     scope_type: string,
     *     center_id: int|null,
     *     branch_id: int|null,
     *     subject_id: int|null
     * }
     */
    public function scope(
        User $actor
    ): array {
        $actor =
            $this->persistedActor(
                $actor
            );

        if (
            $actor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active User Account can view reports.'
            );
        }

        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ViewReports->value
            );

        return match ($actor->systemRole()) {
            SystemRole::PlatformOwner =>
            $this->platformScope(
                $actor
            ),

            SystemRole::CenterOwner =>
            $this->centerOwnerScope(
                $actor
            ),

            SystemRole::BranchManager =>
            $this->branchManagerScope(
                $actor
            ),

            SystemRole::FinanceEmployee =>
            $this->financeEmployeeScope(
                $actor
            ),

            SystemRole::Teacher =>
            $this->teacherScope(
                $actor
            ),

            SystemRole::Student =>
            $this->studentScope(
                $actor
            ),

            default =>
            throw new AuthorizationException(
                'The account cannot resolve a reporting scope.'
            ),
        };
    }

    /**
     * Return Enrollment aggregates limited to the reporting scope
     * that belongs to the authenticated account.
     *
     * Finance Employees intentionally do not receive Enrollment
     * reporting data because their role has no Student-record
     * permission. Their reports are added through the Finance
     * reporting surface instead.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     total: int,
     *     by_status: array<string, int>
     * }
     */
    public function enrollmentSummary(
        User $actor
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            $scope['role']
            === SystemRole::FinanceEmployee->value
        ) {
            throw new AuthorizationException(
                'Finance Employee cannot view Enrollment reports.'
            );
        }

        $query =
            $this->enrollmentQueryForScope(
                $scope
            );

        $statusCounts =
            (clone $query)
            ->selectRaw(
                'enrollment_status, COUNT(*) as aggregate'
            )
            ->groupBy(
                'enrollment_status'
            )
            ->pluck(
                'aggregate',
                'enrollment_status'
            );

        $byStatus = [];

        foreach (
            EnrollmentStatus::cases()
            as $status
        ) {
            $byStatus[$status->value] = (int) (
                $statusCounts[$status->value] ?? 0
            );
        }

        return [
            'scope' =>
            $scope,

            'total' => (clone $query)
                ->count(),

            'by_status' =>
            $byStatus,
        ];
    }

    /**
     * Return the authoritative Enrollment report dataset after
     * applying reusable report filters inside the already-authorized
     * reporting scope.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function enrollmentDataset(
        User $actor,
        ReportFilters $filters
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            $scope['role']
            === SystemRole::FinanceEmployee->value
        ) {
            throw new AuthorizationException(
                'Finance Employee cannot view Enrollment reports.'
            );
        }

        if (
            $filters->status !== null
            && ! in_array(
                $filters->status,
                array_map(
                    static fn(
                        EnrollmentStatus $status
                    ): string =>
                    $status->value,
                    EnrollmentStatus::cases()
                ),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid report filter value.'
            );
        }

        $query =
            $this->enrollmentQueryForScope(
                $scope
            );

        if ($filters->dateFrom !== null) {
            $query->whereDate(
                'enrollment_date',
                '>=',
                $filters->dateFrom
            );
        }

        if ($filters->dateTo !== null) {
            $query->whereDate(
                'enrollment_date',
                '<=',
                $filters->dateTo
            );
        }

        if ($filters->studentId !== null) {
            $query->where(
                'student_id',
                $filters->studentId
            );
        }

        if ($filters->classId !== null) {
            $query->where(
                'class_id',
                $filters->classId
            );
        }

        if ($filters->status !== null) {
            $query->where(
                'enrollment_status',
                $filters->status
            );
        }

        if ($filters->branchId !== null) {
            $query->whereHas(
                'courseClass',
                function (
                    Builder $classQuery
                ) use ($filters): void {
                    $classQuery
                        ->withoutGlobalScopes()
                        ->where(
                            'branch_id',
                            $filters->branchId
                        );
                }
            );
        }

        $enrollments =
            $query
            ->with([
                'courseClass' =>
                function (
                    BelongsTo $classQuery
                ): void {
                    $classQuery
                        ->withoutGlobalScopes()
                        ->select([
                            'id',
                            'center_id',
                            'branch_id',
                            'course_id',
                            'class_code',
                            'name',
                        ]);
                },
            ])
            ->orderBy(
                'enrollment_date'
            )
            ->orderBy(
                'id'
            )
            ->get();

        return [
            'scope' =>
            $scope,

            'rows' =>
            $enrollments
                ->map(
                    static function (
                        Enrollment $enrollment
                    ): array {
                        $courseClass =
                            $enrollment->courseClass;

                        return [
                            'enrollment_id' =>
                            (int) $enrollment->id,

                            'enrollment_number' =>
                            $enrollment->enrollment_number,

                            'enrollment_date' =>
                            $enrollment
                                ->enrollment_date
                                ?->format('Y-m-d'),

                            'status' =>
                            $enrollment
                                ->enrollment_status
                                ->value,

                            'student_id' =>
                            (int) $enrollment->student_id,

                            'class_id' =>
                            (int) $enrollment->class_id,

                            'branch_id' =>
                            $courseClass !== null
                                ? (int) $courseClass->branch_id
                                : null,

                            'class_code' =>
                            $courseClass?->class_code,

                            'class_name' =>
                            $courseClass?->name,
                        ];
                    }
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return Course Class aggregates limited to the authenticated
     * account's reporting scope.
     *
     * Finance Employees intentionally do not receive operational
     * Class reports. Their reporting surface is financial only.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     total: int,
     *     by_status: array<string, int>
     * }
     */
    public function classSummary(
        User $actor
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            $scope['role']
            === SystemRole::FinanceEmployee->value
        ) {
            throw new AuthorizationException(
                'Finance Employee cannot view Class reports.'
            );
        }

        $query =
            $this->classQueryForScope(
                $scope
            );

        $statusCounts =
            (clone $query)
            ->selectRaw(
                'class_status, COUNT(*) as aggregate'
            )
            ->groupBy(
                'class_status'
            )
            ->pluck(
                'aggregate',
                'class_status'
            );

        $byStatus = [];

        foreach (
            CourseClassStatus::cases()
            as $status
        ) {
            $byStatus[$status->value] = (int) (
                $statusCounts[$status->value] ?? 0
            );
        }

        return [
            'scope' =>
            $scope,

            'total' => (clone $query)
                ->count(),

            'by_status' =>
            $byStatus,
        ];
    }

    /**
     * Return the authoritative Course Class report dataset after
     * applying report filters inside the authorized reporting scope.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function classDataset(
        User $actor,
        ReportFilters $filters
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            $scope['role']
            === SystemRole::FinanceEmployee->value
        ) {
            throw new AuthorizationException(
                'Finance Employee cannot view Class reports.'
            );
        }

        if (
            $filters->status !== null
            && ! in_array(
                $filters->status,
                array_map(
                    static fn(
                        CourseClassStatus $status
                    ): string =>
                    $status->value,
                    CourseClassStatus::cases()
                ),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid report filter value.'
            );
        }

        $query =
            $this->classQueryForScope(
                $scope
            );

        if ($filters->branchId !== null) {
            $query->where(
                'branch_id',
                $filters->branchId
            );
        }

        if ($filters->courseId !== null) {
            $query->where(
                'course_id',
                $filters->courseId
            );
        }

        if ($filters->status !== null) {
            $query->where(
                'class_status',
                $filters->status
            );
        }

        $classes =
            $query
            ->orderBy(
                'start_date'
            )
            ->orderBy(
                'id'
            )
            ->get();

        return [
            'scope' =>
            $scope,

            'rows' =>
            $classes
                ->map(
                    static function (
                        CourseClass $courseClass
                    ): array {
                        return [
                            'class_id' =>
                            (int) $courseClass->id,

                            'class_code' =>
                            $courseClass->class_code,

                            'class_name' =>
                            $courseClass->name,

                            'branch_id' =>
                            (int) $courseClass->branch_id,

                            'course_id' =>
                            (int) $courseClass->course_id,

                            'assigned_teacher_id' =>
                            $courseClass->assigned_teacher_id !== null
                                ? (int) $courseClass->assigned_teacher_id
                                : null,

                            'start_date' =>
                            $courseClass
                                ->start_date
                                ?->format('Y-m-d'),

                            'end_date' =>
                            $courseClass
                                ->end_date
                                ?->format('Y-m-d'),

                            'capacity' =>
                            (int) $courseClass->capacity,

                            'delivery_mode' =>
                            $courseClass->delivery_mode,

                            'status' =>
                            $courseClass
                                ->class_status
                                ->value,
                        ];
                    }
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return the authoritative Attendance report dataset.
     *
     * Enrollment selection and report filters are applied inside the
     * authorized reporting scope. Attendance metrics themselves remain
     * authoritative inside AttendanceCalculationService.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function attendanceDataset(
        User $actor,
        ReportFilters $filters
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            in_array(
                $scope['role'],
                [
                    SystemRole::PlatformOwner->value,
                    SystemRole::FinanceEmployee->value,
                ],
                true
            )
        ) {
            throw new AuthorizationException(
                'The account cannot view Attendance reports.'
            );
        }

        $query =
            $this->enrollmentQueryForScope(
                $scope
            );

        if ($filters->studentId !== null) {
            $query->where(
                'student_id',
                $filters->studentId
            );
        }

        if ($filters->classId !== null) {
            $query->where(
                'class_id',
                $filters->classId
            );
        }

        if (
            $filters->branchId !== null
            || $filters->courseId !== null
        ) {
            $query->whereHas(
                'courseClass',
                function (
                    Builder $classQuery
                ) use (
                    $scope,
                    $filters
                ): void {
                    $classQuery
                        ->withoutGlobalScopes();

                    if ($scope['center_id'] !== null) {
                        $classQuery->where(
                            'center_id',
                            $scope['center_id']
                        );
                    }

                    if ($filters->branchId !== null) {
                        $classQuery->where(
                            'branch_id',
                            $filters->branchId
                        );
                    }

                    if ($filters->courseId !== null) {
                        $classQuery->where(
                            'course_id',
                            $filters->courseId
                        );
                    }
                }
            );
        }

        $enrollments =
            $query
            ->with([
                'courseClass' =>
                function (
                    BelongsTo $classQuery
                ): void {
                    $classQuery
                        ->withoutGlobalScopes();
                },
            ])
            ->orderBy(
                'class_id'
            )
            ->orderBy(
                'student_id'
            )
            ->orderBy(
                'id'
            )
            ->get();

        $rows = [];

        foreach ($enrollments as $enrollment) {
            $summary =
                $this->attendance
                ->forEnrollment(
                    $actor,
                    $enrollment
                );

            $courseClass =
                $enrollment->courseClass;

            $rows[] = [
                'enrollment_id' =>
                (int) $enrollment->id,

                'student_id' =>
                (int) $enrollment->student_id,

                'class_id' =>
                (int) $enrollment->class_id,

                'branch_id' =>
                $courseClass !== null
                    ? (int) $courseClass->branch_id
                    : null,

                'course_id' =>
                $courseClass !== null
                    ? (int) $courseClass->course_id
                    : null,

                'recorded_sessions' =>
                (int) $summary['recorded_sessions'],

                'attendance_equivalent' =>
                $summary['attendance_equivalent'],

                'absence_equivalent' =>
                $summary['absence_equivalent'],

                'attendance_percentage' =>
                $summary['attendance_percentage'],

                'absence_percentage' =>
                $summary['absence_percentage'],
            ];
        }

        return [
            'scope' =>
            $scope,

            'rows' =>
            $rows,
        ];
    }

    /**
     * Return Attendance aggregates for the authenticated
     * reporting scope.
     *
     * Attendance calculations themselves remain authoritative
     * inside AttendanceCalculationService. This service only
     * combines already-calculated scoped summaries.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     breakdown_count: int,
     *     recorded_sessions: int,
     *     attendance_equivalent: string,
     *     absence_equivalent: string,
     *     attendance_percentage: string|null,
     *     absence_percentage: string|null
     * }
     */
    public function attendanceSummary(
        User $actor
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        if (
            in_array(
                $scope['role'],
                [
                    SystemRole::PlatformOwner->value,
                    SystemRole::FinanceEmployee->value,
                ],
                true
            )
        ) {
            throw new AuthorizationException(
                'The account cannot view Attendance reports.'
            );
        }

        $summaries =
            match ($scope['scope_type']) {
                'center' =>
                Branch::query()
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $scope['center_id']
                    )
                    ->get()
                    ->map(
                        fn(Branch $branch): array =>
                        $this->attendance
                            ->forBranch(
                                $actor,
                                $branch
                            )
                    )
                    ->all(),

                'branch' => [
                    $this->attendance
                        ->forBranch(
                            $actor,
                            $this->authorizedBranch(
                                (int) $scope['center_id']
                            )
                        ),
                ],

                'teacher' =>
                $this->classQueryForScope(
                    $scope
                )
                    ->get()
                    ->map(
                        fn(CourseClass $courseClass): array =>
                        $this->attendance
                            ->forClass(
                                $actor,
                                $courseClass
                            )
                    )
                    ->all(),

                'student' =>
                $this->enrollmentQueryForScope(
                    $scope
                )
                    ->get()
                    ->map(
                        fn(Enrollment $enrollment): array =>
                        $this->attendance
                            ->forEnrollment(
                                $actor,
                                $enrollment
                            )
                    )
                    ->all(),

                default =>
                throw new AuthorizationException(
                    'The reporting scope cannot access Attendance reports.'
                ),
            };

        return [
            'scope' =>
            $scope,

            'breakdown_count' =>
            count(
                $summaries
            ),

            ...$this->combineAttendanceSummaries(
                $summaries
            ),
        ];
    }

    /**
     * Return financial reporting data for the authenticated
     * reporting scope.
     *
     * Operational financial aggregation remains authoritative in
     * FinanceReadService::operationalSummary().
     *
     * Student financial reporting remains authoritative in
     * FinanceReadService::studentBalance().
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     report_type: string,
     *     data: array<string, mixed>
     * }
     */
    public function financeSummary(
        User $actor
    ): array {
        $scope =
            $this->scope(
                $actor
            );

        return match ($scope['role']) {
            SystemRole::CenterOwner->value,
            SystemRole::BranchManager->value,
            SystemRole::FinanceEmployee->value => [
                'scope' =>
                $scope,

                'report_type' =>
                'operational',

                'data' =>
                $this->finance
                    ->operationalSummary(
                        $actor
                    ),
            ],

            SystemRole::Student->value => [
                'scope' =>
                $scope,

                'report_type' =>
                'student_balance',

                'data' =>
                $this->finance
                    ->studentBalance(
                        $actor,
                        $this->studentForReportScope(
                            $scope
                        )
                    ),
            ],

            default =>
            throw new AuthorizationException(
                'The account cannot view financial reports.'
            ),
        };
    }

    /**
     * @param array{
     *     actor_id: int,
     *     role: string,
     *     scope_type: string,
     *     center_id: int|null,
     *     branch_id: int|null,
     *     subject_id: int|null
     * } $scope
     */
    private function studentForReportScope(
        array $scope
    ): Student {
        if (
            $scope['scope_type']
            !== 'student'
            || $scope['center_id'] === null
            || $scope['subject_id'] === null
        ) {
            throw new AuthorizationException(
                'Student financial reports require Student reporting scope.'
            );
        }

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $scope['subject_id']
            )
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'The Student is outside the authorized reporting scope.'
            );
        }

        return $student;
    }

    /**
     * Combine authoritative AttendanceCalculationService
     * summaries without re-querying or re-interpreting
     * Attendance statuses.
     *
     * @param array<int, array<string, mixed>> $summaries
     *
     * @return array{
     *     recorded_sessions: int,
     *     attendance_equivalent: string,
     *     absence_equivalent: string,
     *     attendance_percentage: string|null,
     *     absence_percentage: string|null
     * }
     */
    private function combineAttendanceSummaries(
        array $summaries
    ): array {
        $recordedSessions = 0;
        $attendanceEquivalent = 0.0;
        $absenceEquivalent = 0.0;

        foreach ($summaries as $summary) {
            $recordedSessions +=
                (int) (
                    $summary['recorded_sessions'] ?? 0
                );

            $attendanceEquivalent +=
                (float) (
                    $summary['attendance_equivalent'] ?? 0
                );

            $absenceEquivalent +=
                (float) (
                    $summary['absence_equivalent'] ?? 0
                );
        }

        if ($recordedSessions === 0) {
            return [
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
            round(
                (
                    $attendanceEquivalent
                    / $recordedSessions
                ) * 100,
                2
            );

        $absencePercentage =
            round(
                100 - $attendancePercentage,
                2
            );

        return [
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
     * @param array{
     *     actor_id: int,
     *     role: string,
     *     scope_type: string,
     *     center_id: int|null,
     *     branch_id: int|null,
     *     subject_id: int|null
     * } $scope
     */
    private function classQueryForScope(
        array $scope
    ): Builder {
        $query =
            CourseClass::query()
            ->withoutGlobalScopes();

        return match ($scope['scope_type']) {
            'platform' =>
            $query,

            'center' =>
            $query->where(
                'center_id',
                $scope['center_id']
            ),

            'branch' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->where(
                    'branch_id',
                    $scope['branch_id']
                ),

            'teacher' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->where(
                    'assigned_teacher_id',
                    $scope['subject_id']
                ),

            'student' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->whereHas(
                    'enrollments',
                    function (
                        Builder $enrollmentQuery
                    ) use ($scope): void {
                        $enrollmentQuery
                            ->withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $scope['center_id']
                            )
                            ->where(
                                'student_id',
                                $scope['subject_id']
                            );
                    }
                ),

            default =>
            throw new AuthorizationException(
                'The reporting scope cannot access Class reports.'
            ),
        };
    }

    /**
     * @param array{
     *     actor_id: int,
     *     role: string,
     *     scope_type: string,
     *     center_id: int|null,
     *     branch_id: int|null,
     *     subject_id: int|null
     * } $scope
     */
    private function enrollmentQueryForScope(
        array $scope
    ): Builder {
        $query =
            Enrollment::query()
            ->withoutGlobalScopes();

        return match ($scope['scope_type']) {
            'platform' =>
            $query,

            'center' =>
            $query->where(
                'center_id',
                $scope['center_id']
            ),

            'branch' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->whereHas(
                    'courseClass',
                    function (
                        Builder $classQuery
                    ) use ($scope): void {
                        $classQuery
                            ->withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $scope['center_id']
                            )
                            ->where(
                                'branch_id',
                                $scope['branch_id']
                            );
                    }
                ),

            'teacher' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->whereHas(
                    'courseClass',
                    function (
                        Builder $classQuery
                    ) use ($scope): void {
                        $classQuery
                            ->withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $scope['center_id']
                            )
                            ->where(
                                'assigned_teacher_id',
                                $scope['subject_id']
                            );
                    }
                ),

            'student' =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->where(
                    'student_id',
                    $scope['subject_id']
                ),

            default =>
            throw new AuthorizationException(
                'The reporting scope cannot access Enrollment reports.'
            ),
        };
    }

    private function platformScope(
        User $actor
    ): array {
        if (
            ! $this->tenant
                ->isEstablished()
            || ! $this->tenant
                ->isPlatformScoped()
        ) {
            throw new AuthorizationException(
                'Platform reports require platform tenant context.'
            );
        }

        if ($actor->center_id !== null) {
            throw new AuthorizationException(
                'Platform Owner reporting scope cannot belong to a Center.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'platform',
            centerId: null,
            branchId: null,
            subjectId: null
        );
    }

    private function centerOwnerScope(
        User $actor
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        if (
            ! $this->branchContext
                ->isEstablished()
            || ! $this->branchContext
                ->isCenterWide()
        ) {
            throw new AuthorizationException(
                'Center Owner reports require center-wide Branch context.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'center',
            centerId: $centerId,
            branchId: null,
            subjectId: null
        );
    }

    private function branchManagerScope(
        User $actor
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branch =
            $this->authorizedBranch(
                $centerId
            );

        $hasAssignment =
            BranchManagerAssignment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $actor->id
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->where(
                'active_marker',
                1
            )
            ->whereNull(
                'ended_at'
            )
            ->exists();

        if (! $hasAssignment) {
            throw new AuthorizationException(
                'Branch Manager reports require an active persisted Branch assignment.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'branch',
            centerId: $centerId,
            branchId: (int) $branch->id,
            subjectId: null
        );
    }

    private function financeEmployeeScope(
        User $actor
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branch =
            $this->authorizedBranch(
                $centerId
            );

        $hasAssignment =
            FinanceEmployeeAssignment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $actor->id
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->where(
                'active_marker',
                1
            )
            ->whereNull(
                'ended_at'
            )
            ->exists();

        if (! $hasAssignment) {
            throw new AuthorizationException(
                'Finance Employee reports require an active persisted Branch assignment.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'branch',
            centerId: $centerId,
            branchId: (int) $branch->id,
            subjectId: null
        );
    }

    private function teacherScope(
        User $actor
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $teacher =
            Teacher::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $actor->id
            )
            ->first();

        if ($teacher === null) {
            throw new AuthorizationException(
                'Teacher reports require a linked Teacher record.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'teacher',
            centerId: $centerId,
            branchId: null,
            subjectId: (int) $teacher->id
        );
    }

    private function studentScope(
        User $actor
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $actor->id
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'Student reports require a linked Student record.'
            );
        }

        return $this->scopePayload(
            actor: $actor,
            scopeType: 'student',
            centerId: $centerId,
            branchId: null,
            subjectId: (int) $student->id
        );
    }

    private function persistedActor(
        User $actor
    ): User {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Reports require a persisted User Account.'
            );
        }

        $persisted =
            User::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $actor->getKey()
            )
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The User Account could not be resolved.'
            );
        }

        return $persisted;
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center =
            $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return (int) $center->id;
    }

    private function authorizedBranch(
        int $centerId
    ): Branch {
        if (
            ! $this->branchContext
                ->isEstablished()
            || ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch reports require an assigned Branch context.'
            );
        }

        $contextBranch =
            $this->branchContext
            ->branch();

        if ($contextBranch === null) {
            throw new AuthorizationException(
                'The Branch context could not be resolved.'
            );
        }

        $branch =
            Branch::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $contextBranch->id
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($branch === null) {
            throw new AuthorizationException(
                'The reporting Branch is outside the current Center scope.'
            );
        }

        return $branch;
    }

    /**
     * @return array{
     *     actor_id: int,
     *     role: string,
     *     scope_type: string,
     *     center_id: int|null,
     *     branch_id: int|null,
     *     subject_id: int|null
     * }
     */
    private function scopePayload(
        User $actor,
        string $scopeType,
        ?int $centerId,
        ?int $branchId,
        ?int $subjectId
    ): array {
        $role =
            $actor->systemRole();

        if ($role === null) {
            throw new AuthorizationException(
                'The User Account does not have a recognized system role.'
            );
        }

        return [
            'actor_id' =>
            (int) $actor->id,

            'role' =>
            $role->value,

            'scope_type' =>
            $scopeType,

            'center_id' =>
            $centerId,

            'branch_id' =>
            $branchId,

            'subject_id' =>
            $subjectId,
        ];
    }
}
