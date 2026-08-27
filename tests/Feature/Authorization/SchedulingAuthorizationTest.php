<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SchedulingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_only_center_owner_and_branch_manager_receive_schedule_management_permission(): void
    {
        foreach (
            [
                SystemRole::CenterOwner,
                SystemRole::BranchManager,
            ] as $role
        ) {
            $user =
                $this->createUserForRole(
                    $role
                );

            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ManageSchedules
                )
            );

            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ViewSchedules
                )
            );
        }

        $teacher =
            $this->createUserForRole(
                SystemRole::Teacher
            );

        $this->assertFalse(
            $teacher->hasPermission(
                SystemPermission::ManageSchedules
            )
        );

        $this->assertTrue(
            $teacher->hasPermission(
                SystemPermission::ViewSchedules
            )
        );

        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::FinanceEmployee,
                SystemRole::Student,
            ] as $role
        ) {
            $user =
                $this->createUserForRole(
                    $role
                );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageSchedules
                )
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ViewSchedules
                )
            );
        }
    }

    public function test_center_owner_can_manage_and_view_schedule_and_session_in_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        [
            $courseClass,
            $schedule,
            $session,
        ] = $this->createSchedulingRecords(
            $center
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        ClassSchedule::class,
                        $courseClass,
                    ]
                )
        );

        foreach (
            [
                'view',
                'update',
                'reschedule',
                'cancel',
            ] as $ability
        ) {
            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $schedule
                    )
            );
        }

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        ClassSession::class,
                        $schedule,
                    ]
                )
        );

        foreach (
            [
                'view',
                'update',
                'reschedule',
                'cancel',
                'complete',
            ] as $ability
        ) {
            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $session
                    )
            );
        }
    }

    public function test_center_owner_cannot_manage_or_view_schedule_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        [
            $courseClassB,
            $scheduleB,
            $sessionB,
        ] = $this->createSchedulingRecords(
            $centerB
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'create',
                    [
                        ClassSchedule::class,
                        $courseClassB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'view',
                    $scheduleB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $scheduleB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'complete',
                    $sessionB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'view',
                    $sessionB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $sessionB
                )
        );
    }

    public function test_branch_manager_can_manage_schedule_and_session_only_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->assignManager(
            $manager,
            $branchA
        );

        [
            $classA,
            $scheduleA,
            $sessionA,
        ] = $this->createSchedulingRecords(
            $center,
            $branchA
        );

        [
            $classB,
            $scheduleB,
            $sessionB,
        ] = $this->createSchedulingRecords(
            $center,
            $branchB
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        ClassSchedule::class,
                        $classA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $scheduleA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        ClassSession::class,
                        $scheduleA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $sessionA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'complete',
                    $sessionA
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        ClassSchedule::class,
                        $classB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $scheduleB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $sessionB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'complete',
                    $sessionB
                )
        );
    }

    public function test_ended_branch_manager_assignment_does_not_grant_scheduling_access(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->assignManager(
            $manager,
            $branch,
            false
        );

        [
            $courseClass,
            $schedule,
            $session,
        ] = $this->createSchedulingRecords(
            $center,
            $branch
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        ClassSchedule::class,
                        $courseClass,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $schedule
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $session
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'complete',
                    $session
                )
        );
    }

    public function test_teacher_can_view_only_schedule_and_session_assigned_to_linked_teacher_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $teacherAccount =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $person = Person::query()
            ->withoutGlobalScopes()
            ->findOrFail(
                $teacherAccount->person_id
            );

        $teacherRecord = Teacher::factory()
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $teacherAccount->id,
            ]);

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [,
            $ownSchedule,
            $ownSession,
        ] = $this->createSchedulingRecords(
            $center,
            $branch,
            $teacherRecord
        );

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        [,
            $otherSchedule,
            $otherSession,
        ] = $this->createSchedulingRecords(
            $center,
            $branch,
            $otherTeacher
        );

        $this->assertTrue(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $ownSchedule
                )
        );

        $this->assertTrue(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $ownSession
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $otherSchedule
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'view',
                    $otherSession
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'update',
                    $ownSchedule
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'reschedule',
                    $ownSession
                )
        );

        $this->assertFalse(
            Gate::forUser($teacherAccount)
                ->allows(
                    'complete',
                    $ownSession
                )
        );
    }

    public function test_finance_student_and_platform_roles_cannot_access_scheduling_records(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [
            $courseClass,
            $schedule,
            $session,
        ] = $this->createSchedulingRecords(
            $center
        );

        foreach (
            [
                SystemRole::FinanceEmployee,
                SystemRole::Student,
                SystemRole::PlatformOwner,
            ] as $role
        ) {
            $user =
                $this->createUserForRole(
                    $role,
                    $role === SystemRole::PlatformOwner
                        ? null
                        : $center
                );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            ClassSchedule::class,
                            $courseClass,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $schedule
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $session
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'complete',
                        $session
                    )
            );
        }
    }

    /**
     * @return array{
     *     CourseClass,
     *     ClassSchedule,
     *     ClassSession
     * }
     */
    private function createSchedulingRecords(
        Center $center,
        ?Branch $branch = null,
        ?Teacher $teacher = null
    ): array {
        $branch ??= Branch::factory()
            ->for($center)
            ->active()
            ->create();

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
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create();

        return [
            $courseClass,
            $schedule,
            $session,
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
                now()
                    ->subDay(),

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
            $role
            === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,

                    'role_id' =>
                    $this->role(
                        $role
                    )->id,

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
