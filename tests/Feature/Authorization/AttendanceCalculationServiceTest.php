<?php

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_enrollment_calculation_uses_recorded_status_contributions_only(): void
    {
        [
            $center,,
            $course,,
            $teacherUser,,
            $enrollment,
            $schedule,
        ] = $this->classContext(
            minimumAttendance: 60
        );

        $present =
            $this->attendanceStatus(
                $center,
                'PRESENT',
                100
            );

        $late =
            $this->attendanceStatus(
                $center,
                'LATE',
                75
            );

        $absent =
            $this->attendanceStatus(
                $center,
                'ABSENT',
                0
            );

        $sessions =
            $this->sessions(
                $schedule,
                4
            );

        $this->attendance(
            $sessions[0],
            $enrollment,
            $present,
            $teacherUser
        );

        $this->attendance(
            $sessions[1],
            $enrollment,
            $late,
            $teacherUser
        );

        $this->attendance(
            $sessions[2],
            $enrollment,
            $absent,
            $teacherUser
        );

        /*
         * Session 4 deliberately has no Attendance record.
         * It must not be interpreted as Absence.
         */

        $studentUser =
            Student::query()
            ->withoutGlobalScopes()
            ->findOrFail(
                $enrollment->student_id
            )
            ->user;

        $this->establishCenterContext(
            $center
        );

        $result =
            $this->service()
            ->forEnrollment(
                $studentUser,
                $enrollment
            );

        $this->assertSame(
            3,
            $result['recorded_sessions']
        );

        $this->assertSame(
            '1.75',
            $result['attendance_equivalent']
        );

        $this->assertSame(
            '1.25',
            $result['absence_equivalent']
        );

        $this->assertSame(
            '58.33',
            $result['attendance_percentage']
        );

        $this->assertSame(
            '41.67',
            $result['absence_percentage']
        );

        $this->assertSame(
            '60.00',
            $result['minimum_attendance']
        );

        $this->assertFalse(
            $result['meets_minimum_attendance']
        );

        $this->assertSame(
            $course->id,
            $result['course_id']
        );
    }

    public function test_no_recorded_attendance_returns_null_percentages(): void
    {
        [
            $center,,,,,,
            $enrollment,
        ] = $this->classContext();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $result =
            $this->service()
            ->forEnrollment(
                $owner,
                $enrollment
            );

        $this->assertSame(
            0,
            $result['recorded_sessions']
        );

        $this->assertSame(
            '0.00',
            $result['attendance_equivalent']
        );

        $this->assertSame(
            '0.00',
            $result['absence_equivalent']
        );

        $this->assertNull(
            $result['attendance_percentage']
        );

        $this->assertNull(
            $result['absence_percentage']
        );

        $this->assertNull(
            $result['meets_minimum_attendance']
        );
    }

    public function test_inactive_historical_status_still_contributes_to_calculation(): void
    {
        [
            $center,,,,
            $teacherUser,,
            $enrollment,
            $schedule,
        ] = $this->classContext();

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->inactive()
            ->create([
                'code' =>
                'OLD_LATE',

                'contribution_value' =>
                50,
            ]);

        $session =
            $this->sessions(
                $schedule,
                1
            )[0];

        $this->attendance(
            $session,
            $enrollment,
            $status,
            $teacherUser
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $result =
            $this->service()
            ->forEnrollment(
                $owner,
                $enrollment
            );

        $this->assertSame(
            '50.00',
            $result['attendance_percentage']
        );
    }

    public function test_attendance_attached_to_cancelled_session_is_excluded(): void
    {
        [
            $center,,,,
            $teacherUser,,
            $enrollment,
            $schedule,
        ] = $this->classContext();

        $status =
            $this->attendanceStatus(
                $center,
                'PRESENT',
                100
            );

        $session =
            $this->sessions(
                $schedule,
                1
            )[0];

        $this->attendance(
            $session,
            $enrollment,
            $status,
            $teacherUser
        );

        $session->forceFill([
            'session_status' =>
            ClassSessionStatus::Cancelled,

            'cancellation_reason' =>
            'Cancelled after recording.',
        ])->save();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $result =
            $this->service()
            ->forEnrollment(
                $owner,
                $enrollment
            );

        $this->assertSame(
            0,
            $result['recorded_sessions']
        );

        $this->assertNull(
            $result['attendance_percentage']
        );
    }

    public function test_center_owner_can_calculate_class_course_and_branch_totals(): void
    {
        [
            $center,
            $branch,
            $course,
            $courseClass,
            $teacherUser,,
            $enrollment,
            $schedule,
        ] = $this->classContext();

        $present =
            $this->attendanceStatus(
                $center,
                'PRESENT',
                100
            );

        foreach (
            $this->sessions(
                $schedule,
                2
            )
            as $session
        ) {
            $this->attendance(
                $session,
                $enrollment,
                $present,
                $teacherUser
            );
        }

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $classResult =
            $this->service()
            ->forClass(
                $owner,
                $courseClass
            );

        $courseResult =
            $this->service()
            ->forCourse(
                $owner,
                $course
            );

        $branchResult =
            $this->service()
            ->forBranch(
                $owner,
                $branch
            );

        foreach (
            [
                $classResult,
                $courseResult,
                $branchResult,
            ] as $result
        ) {
            $this->assertSame(
                2,
                $result['recorded_sessions']
            );

            $this->assertSame(
                '100.00',
                $result['attendance_percentage']
            );
        }
    }

    public function test_course_and_branch_aggregates_do_not_include_other_course_or_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $courseA = Course::factory()
            ->for($center)
            ->create();

        $courseB = Course::factory()
            ->for($center)
            ->create();

        [,,,
            $classA,
            $teacherUserA,,
            $enrollmentA,
            $scheduleA,
        ] = $this->classContext(
            $center,
            $branchA,
            $courseA
        );

        [,,,,
            $teacherUserB,,
            $enrollmentB,
            $scheduleB,
        ] = $this->classContext(
            $center,
            $branchB,
            $courseB
        );

        $present =
            $this->attendanceStatus(
                $center,
                'PRESENT_FILTER',
                100
            );

        $this->attendance(
            $this->sessions(
                $scheduleA,
                1
            )[0],
            $enrollmentA,
            $present,
            $teacherUserA
        );

        $this->attendance(
            $this->sessions(
                $scheduleB,
                1,
                20
            )[0],
            $enrollmentB,
            $present,
            $teacherUserB
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $courseResult =
            $this->service()
            ->forCourse(
                $owner,
                $courseA
            );

        $branchResult =
            $this->service()
            ->forBranch(
                $owner,
                $branchA
            );

        $classResult =
            $this->service()
            ->forClass(
                $owner,
                $classA
            );

        $this->assertSame(
            1,
            $courseResult['recorded_sessions']
        );

        $this->assertSame(
            1,
            $branchResult['recorded_sessions']
        );

        $this->assertSame(
            1,
            $classResult['recorded_sessions']
        );
    }

    public function test_branch_manager_can_calculate_only_assigned_branch_scope(): void
    {
        [
            $center,
            $branch,,
            $courseClass,,,
            $enrollment,
        ] = $this->classContext();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->forClass(
                    $manager,
                    $courseClass
                );

            $this->fail(
                'Expected missing Branch context to fail.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );

        $classResult =
            $this->service()
            ->forClass(
                $manager,
                $courseClass
            );

        $branchResult =
            $this->service()
            ->forBranch(
                $manager,
                $branch
            );

        $enrollmentResult =
            $this->service()
            ->forEnrollment(
                $manager,
                $enrollment
            );

        $this->assertArrayHasKey(
            'recorded_sessions',
            $classResult
        );

        $this->assertArrayHasKey(
            'recorded_sessions',
            $branchResult
        );

        $this->assertArrayHasKey(
            'recorded_sessions',
            $enrollmentResult
        );
    }

    public function test_branch_manager_enrollment_calculation_follows_class_branch_not_student_home_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $studentHomeBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [,,,
            $courseClass,,
            $student,
            $enrollment,
        ] = $this->classContext(
            $center,
            $classBranch
        );

        $student->forceFill([
            'branch_id' =>
            $studentHomeBranch->id,
        ])->save();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $classBranch
        );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishBranchScope(
                $classBranch
            );

        $result =
            $this->service()
            ->forEnrollment(
                $manager,
                $enrollment
            );

        $this->assertSame(
            $enrollment->id,
            $result['enrollment_id']
        );

        $this->assertSame(
            $courseClass->id,
            $result['class_id']
        );

        $this->assertArrayHasKey(
            'attendance_percentage',
            $result
        );
    }

    public function test_branch_manager_cannot_receive_center_wide_course_total(): void
    {
        [
            $center,
            $branch,
            $course,
        ] = $this->classContext();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forCourse(
                $manager,
                $course
            );
    }

    public function test_assigned_teacher_can_calculate_class_and_enrollment_without_branch_context(): void
    {
        [
            $center,,,
            $courseClass,
            $teacherUser,,
            $enrollment,
        ] = $this->classContext();

        $this->establishCenterContext(
            $center
        );

        $classResult =
            $this->service()
            ->forClass(
                $teacherUser,
                $courseClass
            );

        $studentResult =
            $this->service()
            ->forEnrollment(
                $teacherUser,
                $enrollment
            );

        $this->assertArrayHasKey(
            'attendance_percentage',
            $classResult
        );

        $this->assertArrayHasKey(
            'attendance_percentage',
            $studentResult
        );
    }

    public function test_teacher_cannot_calculate_another_teacher_class(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [,,,,
            $teacherA,
        ] = $this->classContext(
            $center
        );

        [,,,
            $classB,
        ] = $this->classContext(
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forClass(
                $teacherA,
                $classB
            );
    }

    public function test_student_can_calculate_only_own_enrollment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [,,,,,
            $studentA,
            $enrollmentA,
        ] = $this->classContext(
            $center
        );

        [,,,,,,
            $enrollmentB,
        ] = $this->classContext(
            $center
        );

        $studentUser =
            $studentA->user;

        $this->establishCenterContext(
            $center
        );

        $own =
            $this->service()
            ->forEnrollment(
                $studentUser,
                $enrollmentA
            );

        $this->assertSame(
            $studentA->id,
            $own['student_id']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forEnrollment(
                $studentUser,
                $enrollmentB
            );
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
    {
        [
            $center,,,
            $courseClass,
        ] = $this->classContext();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forClass(
                $owner,
                $courseClass
            );
    }

    /**
     * @return array{
     *     Center,
     *     Branch,
     *     Course,
     *     CourseClass,
     *     User,
     *     Student,
     *     Enrollment,
     *     ClassSchedule
     * }
     */
    private function classContext(
        ?Center $center = null,
        ?Branch $branch = null,
        ?Course $course = null,
        int|float $minimumAttendance = 75
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create();

        $branch ??=
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course ??=
            Course::factory()
            ->for($center)
            ->create([
                'minimum_attendance' =>
                $minimumAttendance,
            ]);

        $teacherUser =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $teacherPerson =
            Person::query()
            ->withoutGlobalScopes()
            ->findOrFail(
                $teacherUser->person_id
            );

        $teacher =
            Teacher::factory()
            ->forPerson(
                $teacherPerson
            )
            ->active()
            ->create([
                'user_id' =>
                $teacherUser->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forTeacher($teacher)
            ->active()
            ->create([
                'course_id' =>
                $course->id,
            ]);

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $studentUser->person_id,

                'user_id' =>
                $studentUser->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'enrollment_date' =>
                '2026-08-01',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        return [
            $center,
            $branch,
            $course,
            $courseClass,
            $teacherUser,
            $student,
            $enrollment,
            $schedule,
        ];
    }

    /**
     * @return array<int, ClassSession>
     */
    private function sessions(
        ClassSchedule $schedule,
        int $count,
        int $startingDay = 1
    ): array {
        $sessions = [];

        for (
            $index = 0;
            $index < $count;
            $index++
        ) {
            $day =
                $startingDay
                + $index;

            $date =
                sprintf(
                    '2026-08-%02d',
                    $day
                );

            $sessions[] =
                ClassSession::factory()
                ->forSchedule(
                    $schedule
                )
                ->scheduled()
                ->create([
                    'occurrence_date' =>
                    $date,

                    'session_date' =>
                    $date,
                ]);
        }

        return $sessions;
    }

    private function attendanceStatus(
        Center $center,
        string $code,
        int|float $contribution
    ): AttendanceStatus {
        return AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create([
                'name' =>
                $code,

                'code' =>
                $code,

                'contribution_value' =>
                $contribution,
            ]);
    }

    private function attendance(
        ClassSession $session,
        Enrollment $enrollment,
        AttendanceStatus $status,
        User $recorder
    ): Attendance {
        return Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function assignManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now()->subDay(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function service(): AttendanceCalculationService
    {
        return app(
            AttendanceCalculationService::class
        );
    }

    private function createUserForRole(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    $role
                )->id,

                'status' =>
                AccountStatus::Active,
            ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}
