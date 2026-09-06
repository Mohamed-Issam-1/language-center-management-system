<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AttendanceCalculationService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext
    ) {}

    /**
     * Student Attendance inside one Enrollment/Class.
     *
     * @return array<string, mixed>
     */
    public function forEnrollment(
        User $actor,
        Enrollment $enrollment
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $enrollment =
            $this->persistedEnrollment(
                $enrollment,
                $centerId
            );

        $courseClass =
            $this->persistedCourseClassById(
                $enrollment->class_id,
                $centerId
            );

        Gate::forUser($actor)
            ->authorize(
                'viewEnrollmentSummary',
                [
                    Attendance::class,
                    $enrollment,
                ]
            );

        $this->ensureOperationalReadScope(
            $actor,
            $centerId,
            $courseClass->branch_id
        );

        $course =
            $this->persistedCourseById(
                $courseClass->course_id,
                $centerId
            );

        $query =
            $this->baseQuery(
                $centerId
            )
            ->where(
                'a.enrollment_id',
                $enrollment->id
            );

        $metrics =
            $this->metrics(
                $query
            );

        $minimumAttendance =
            $course->minimum_attendance;

        $meetsMinimum =
            $metrics['attendance_percentage'] === null
            || $minimumAttendance === null
            ? null
            : (float) $metrics['attendance_percentage']
            >= (float) $minimumAttendance;

        return [
            'enrollment_id' =>
            $enrollment->id,

            'student_id' =>
            $enrollment->student_id,

            'class_id' =>
            $courseClass->id,

            'course_id' =>
            $course->id,

            ...$metrics,

            'minimum_attendance' =>
            $minimumAttendance === null
                ? null
                : number_format(
                    (float) $minimumAttendance,
                    2,
                    '.',
                    ''
                ),

            'meets_minimum_attendance' =>
            $meetsMinimum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forClass(
        User $actor,
        CourseClass $courseClass
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $courseClass =
            $this->persistedCourseClass(
                $courseClass,
                $centerId
            );

        Gate::forUser($actor)
            ->authorize(
                'viewClassSummary',
                [
                    Attendance::class,
                    $courseClass,
                ]
            );

        $this->ensureOperationalReadScope(
            $actor,
            $centerId,
            $courseClass->branch_id
        );

        $metrics =
            $this->metrics(
                $this->baseQuery(
                    $centerId
                )->where(
                    'cc.id',
                    $courseClass->id
                )
            );

        return [
            'class_id' =>
            $courseClass->id,

            'course_id' =>
            $courseClass->course_id,

            'branch_id' =>
            $courseClass->branch_id,

            ...$metrics,
        ];
    }

    /**
     * Course aggregate is intentionally Center-wide.
     *
     * Branch Managers and Teachers must not receive totals
     * containing records outside their operational scope.
     *
     * @return array<string, mixed>
     */
    public function forCourse(
        User $actor,
        Course $course
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $course =
            $this->persistedCourse(
                $course,
                $centerId
            );

        Gate::forUser($actor)
            ->authorize(
                'viewCourseSummary',
                [
                    Attendance::class,
                    $course,
                ]
            );

        $this->ensureOperationalReadScope(
            $actor,
            $centerId,
            null
        );

        $metrics =
            $this->metrics(
                $this->baseQuery(
                    $centerId
                )->where(
                    'cc.course_id',
                    $course->id
                )
            );

        return [
            'course_id' =>
            $course->id,

            ...$metrics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forBranch(
        User $actor,
        Branch $branch
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branch =
            $this->persistedBranch(
                $branch,
                $centerId
            );

        Gate::forUser($actor)
            ->authorize(
                'viewBranchSummary',
                [
                    Attendance::class,
                    $branch,
                ]
            );

        $this->ensureOperationalReadScope(
            $actor,
            $centerId,
            $branch->id
        );

        $metrics =
            $this->metrics(
                $this->baseQuery(
                    $centerId
                )->where(
                    'cc.branch_id',
                    $branch->id
                )
            );

        return [
            'branch_id' =>
            $branch->id,

            ...$metrics,
        ];
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

        return $center->id;
    }

    private function persistedEnrollment(
        Enrollment $enrollment,
        int $centerId
    ): Enrollment {
        $persisted =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Enrollment is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function persistedCourseClass(
        CourseClass $courseClass,
        int $centerId
    ): CourseClass {
        return $this->persistedCourseClassById(
            $courseClass->getKey(),
            $centerId
        );
    }

    private function persistedCourseClassById(
        int $courseClassId,
        int $centerId
    ): CourseClass {
        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseClassId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($courseClass === null) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        return $courseClass;
    }

    private function persistedCourse(
        Course $course,
        int $centerId
    ): Course {
        return $this->persistedCourseById(
            $course->getKey(),
            $centerId
        );
    }

    private function persistedCourseById(
        int $courseId,
        int $centerId
    ): Course {
        $course =
            Course::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($course === null) {
            throw new AuthorizationException(
                'The Course is outside the authorized Center scope.'
            );
        }

        return $course;
    }

    private function persistedBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        $persisted =
            Branch::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $branch->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Branch is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function ensureOperationalReadScope(
        User $actor,
        int $centerId,
        ?int $branchId
    ): void {
        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $this->branchContext
                    ->isEstablished()
                || ! $this->branchContext
                    ->isCenterWide()
            ) {
                throw new AuthorizationException(
                    'Center Owner Attendance calculations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            if ($branchId === null) {
                throw new AuthorizationException(
                    'Branch Manager cannot access center-wide Attendance calculations.'
                );
            }

            if (
                ! $this->branchContext
                    ->isEstablished()
                || ! $this->branchContext
                    ->isBranchScoped()
            ) {
                throw new AuthorizationException(
                    'Branch Manager Attendance calculations require an assigned Branch context.'
                );
            }

            $branch =
                $this->branchContext
                ->branch();

            if (
                $branch === null
                || $branch->center_id
                !== $centerId
                || $branch->id
                !== $branchId
            ) {
                throw new AuthorizationException(
                    'The Attendance calculation is outside the assigned Branch scope.'
                );
            }

            return;
        }

        /*
         * Teacher scope is resolved by persisted Teacher/Class
         * assignment in AttendancePolicy.
         *
         * Student scope is resolved by persisted
         * Enrollment/Student ownership.
         */
        if (
            in_array(
                $actor->systemRole(),
                [
                    SystemRole::Teacher,
                    SystemRole::Student,
                ],
                true
            )
        ) {
            return;
        }

        throw new AuthorizationException(
            'The account cannot view Attendance calculations.'
        );
    }

    private function baseQuery(
        int $centerId
    ): Builder {
        return DB::table(
            'attendances as a'
        )
            ->join(
                'attendance_statuses as ast',
                function ($join) {
                    $join
                        ->on(
                            'ast.id',
                            '=',
                            'a.attendance_status_id'
                        )
                        ->on(
                            'ast.center_id',
                            '=',
                            'a.center_id'
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
                            'a.session_id'
                        )
                        ->on(
                            'cs.center_id',
                            '=',
                            'a.center_id'
                        );
                }
            )
            ->join(
                'enrollments as e',
                function ($join) {
                    $join
                        ->on(
                            'e.id',
                            '=',
                            'a.enrollment_id'
                        )
                        ->on(
                            'e.center_id',
                            '=',
                            'a.center_id'
                        );
                }
            )
            ->join(
                'course_classes as cc',
                function ($join) {
                    $join
                        ->on(
                            'cc.id',
                            '=',
                            'cs.class_id'
                        )
                        ->on(
                            'cc.center_id',
                            '=',
                            'a.center_id'
                        );
                }
            )
            ->where(
                'a.center_id',
                $centerId
            )
            ->whereColumn(
                'e.class_id',
                'cs.class_id'
            )
            ->where(
                'cs.session_status',
                '!=',
                ClassSessionStatus::Cancelled->value
            );
    }

    /**
     * recorded_sessions means recorded Student/Session
     * observations, not all scheduled Sessions.
     *
     * A missing Attendance record is intentionally not
     * interpreted as Absence.
     *
     * @return array{
     *     recorded_sessions:int,
     *     attendance_equivalent:string,
     *     absence_equivalent:string,
     *     attendance_percentage:?string,
     *     absence_percentage:?string
     * }
     */
    private function metrics(
        Builder $query
    ): array {
        $result =
            $query
            ->selectRaw(
                'COUNT(*) as recorded_sessions'
            )
            ->selectRaw(
                'COALESCE(SUM(ast.contribution_value), 0) as contribution_sum'
            )
            ->first();

        $recordedSessions =
            (int) (
                $result
                ?->recorded_sessions
                ?? 0
            );

        $contributionSum =
            (float) (
                $result
                ?->contribution_sum
                ?? 0
            );

        $attendanceEquivalent =
            $contributionSum / 100;

        $absenceEquivalent =
            $recordedSessions
            - $attendanceEquivalent;

        if ($recordedSessions === 0) {
            return [
                'recorded_sessions' =>
                0,

                'attendance_equivalent' =>
                '0.00',

                'absence_equivalent' =>
                '0.00',

                /*
                 * null means no recorded observations.
                 *
                 * Returning 0% here would incorrectly imply
                 * that the Student was absent.
                 */
                'attendance_percentage' =>
                null,

                'absence_percentage' =>
                null,
            ];
        }

        $attendancePercentage =
            $contributionSum
            / $recordedSessions;

        $absencePercentage =
            100
            - $attendancePercentage;

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
}
