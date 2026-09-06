<?php

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AttendanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_attendance_permissions_are_assigned_only_to_documented_roles(): void
    {
        $centerOwner =
            $this->createUserForRole(
                SystemRole::CenterOwner
            );

        $branchManager =
            $this->createUserForRole(
                SystemRole::BranchManager
            );

        $teacher =
            $this->createUserForRole(
                SystemRole::Teacher
            );

        $student =
            $this->createUserForRole(
                SystemRole::Student
            );

        $finance =
            $this->createUserForRole(
                SystemRole::FinanceEmployee
            );

        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->assertFalse(
            $centerOwner->hasPermission(
                SystemPermission::ManageAttendance
            )
        );

        $this->assertTrue(
            $centerOwner->hasPermission(
                SystemPermission::ViewAttendance
            )
        );

        $this->assertTrue(
            $centerOwner->hasPermission(
                SystemPermission::ManageAttendanceSettings
            )
        );

        foreach (
            [
                $branchManager,
                $teacher,
            ] as $user
        ) {
            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ManageAttendance
                )
            );

            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ViewAttendance
                )
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageAttendanceSettings
                )
            );
        }

        $this->assertFalse(
            $student->hasPermission(
                SystemPermission::ManageAttendance
            )
        );

        $this->assertTrue(
            $student->hasPermission(
                SystemPermission::ViewAttendance
            )
        );

        foreach (
            [
                $finance,
                $platformOwner,
            ] as $user
        ) {
            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageAttendance
                )
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ViewAttendance
                )
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageAttendanceSettings
                )
            );
        }
    }

    public function test_center_owner_can_manage_attendance_statuses_only_inside_own_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $statusA =
            AttendanceStatus::factory()
            ->forCenter($centerA)
            ->create();

        $statusB =
            AttendanceStatus::factory()
            ->forCenter($centerB)
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        AttendanceStatus::class,
                        $centerA,
                    ]
                )
        );

        foreach (
            [
                'update',
                'activate',
                'deactivate',
            ] as $ability
        ) {
            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $statusA
                    )
            );

            $this->assertFalse(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $statusB
                    )
            );
        }

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        AttendanceStatus::class,
                        $centerB,
                    ]
                )
        );
    }

    public function test_teacher_can_manage_and_view_attendance_only_for_assigned_session(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $teacherAccount =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $person =
            Person::query()
            ->withoutGlobalScopes()
            ->findOrFail(
                $teacherAccount->person_id
            );

        $teacherRecord =
            Teacher::factory()
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $teacherAccount->id,
            ]);

        [
            $ownSession,
            $ownEnrollment,
            $ownStatus,
        ] = $this->createAttendanceContext(
            $center,
            $branch,
            $teacherRecord
        );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $ownAttendance =
            Attendance::factory()
            ->forSession($ownSession)
            ->forEnrollment($ownEnrollment)
            ->withStatus($ownStatus)
            ->recordedBy($recorder)
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        [
            $otherSession,
            $otherEnrollment,
            $otherStatus,
        ] = $this->createAttendanceContext(
            $center,
            $branch,
            $otherTeacher
        );

        $otherAttendance =
            Attendance::factory()
            ->forSession($otherSession)
            ->forEnrollment($otherEnrollment)
            ->withStatus($otherStatus)
            ->recordedBy($recorder)
            ->create();

        $this->assertTrue(
            Gate::forUser($teacherAccount)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $ownSession,
                        $ownEnrollment,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($teacherAccount)
                ->allows(
                    'update',
                    $ownAttendance
                )
        );

        $this->assertTrue(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $ownAttendance
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $otherSession,
                        $otherEnrollment,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $otherAttendance
                )
        );
    }

    public function test_branch_manager_can_manage_attendance_only_inside_assigned_branch(): void
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

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branchA
        );

        [
            $sessionA,
            $enrollmentA,
            $statusA,
        ] = $this->createAttendanceContext(
            $center,
            $branchA
        );

        [
            $sessionB,
            $enrollmentB,
            $statusB,
        ] = $this->createAttendanceContext(
            $center,
            $branchB
        );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $attendanceA =
            Attendance::factory()
            ->forSession($sessionA)
            ->forEnrollment($enrollmentA)
            ->withStatus($statusA)
            ->recordedBy($recorder)
            ->create();

        $attendanceB =
            Attendance::factory()
            ->forSession($sessionB)
            ->forEnrollment($enrollmentB)
            ->withStatus($statusB)
            ->recordedBy($recorder)
            ->create();

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $sessionA,
                        $enrollmentA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $attendanceA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $attendanceA
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $sessionB,
                        $enrollmentB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $attendanceB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $attendanceB
                )
        );
    }

    public function test_ended_branch_manager_assignment_does_not_grant_attendance_access(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch,
            false
        );

        [
            $session,
            $enrollment,
            $status,
        ] = $this->createAttendanceContext(
            $center,
            $branch
        );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $attendance =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $session,
                        $enrollment,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $attendance
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $attendance
                )
        );
    }

    public function test_center_owner_can_view_but_cannot_record_or_update_attendance(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $session,
            $enrollment,
            $status,
        ] = $this->createAttendanceContext(
            $center,
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $attendance =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($owner)
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'view',
                    $attendance
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $session,
                        $enrollment,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'update',
                    $attendance
                )
        );
    }

    public function test_student_can_view_only_own_attendance(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $studentAccount =
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
                $studentAccount->person_id,

                'user_id' =>
                $studentAccount->id,
            ]);

        [
            $ownSession,
            $ownEnrollment,
            $ownStatus,
        ] = $this->createAttendanceContext(
            $center,
            $branch,
            null,
            $student
        );

        [
            $otherSession,
            $otherEnrollment,
            $otherStatus,
        ] = $this->createAttendanceContext(
            $center,
            $branch
        );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $ownAttendance =
            Attendance::factory()
            ->forSession($ownSession)
            ->forEnrollment($ownEnrollment)
            ->withStatus($ownStatus)
            ->recordedBy($recorder)
            ->create();

        $otherAttendance =
            Attendance::factory()
            ->forSession($otherSession)
            ->forEnrollment($otherEnrollment)
            ->withStatus($otherStatus)
            ->recordedBy($recorder)
            ->create();

        $this->assertTrue(
            Gate::forUser($studentAccount)
                ->allows(
                    'view',
                    $ownAttendance
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'view',
                    $otherAttendance
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'create',
                    [
                        Attendance::class,
                        $ownSession,
                        $ownEnrollment,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'update',
                    $ownAttendance
                )
        );
    }

    public function test_finance_and_platform_owner_cannot_access_attendance(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $session,
            $enrollment,
            $status,
        ] = $this->createAttendanceContext(
            $center,
            $branch
        );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $attendance =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();

        foreach (
            [
                $this->createUserForRole(
                    SystemRole::FinanceEmployee,
                    $center
                ),

                $this->createUserForRole(
                    SystemRole::PlatformOwner
                ),
            ] as $user
        ) {
            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $attendance
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            Attendance::class,
                            $session,
                            $enrollment,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'update',
                        $attendance
                    )
            );
        }
    }

    /**
     * @return array{
     *     ClassSession,
     *     Enrollment,
     *     AttendanceStatus
     * }
     */
    private function createAttendanceContext(
        Center $center,
        Branch $branch,
        ?Teacher $teacher = null,
        ?Student $student = null
    ): array {
        $classroom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacher ??=
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $session =
            ClassSession::factory()
            ->forSchedule($schedule)
            ->scheduled()
            ->create();

        $student ??=
            Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_date' =>
                $session
                    ->session_date
                    ->toDateString(),
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create();

        return [
            $session,
            $enrollment,
            $status,
        ];
    }

    private function assignManager(
        User $manager,
        Branch $branch,
        bool $active = true
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
                $active
                    ? null
                    : now(),

                'active_marker' =>
                $active
                    ? 1
                    : null,
            ]);
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role ===
            SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this->role($role)->id,

                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??=
            Center::factory()
            ->active()
            ->create();

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
                $this->role($role)->id,

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
