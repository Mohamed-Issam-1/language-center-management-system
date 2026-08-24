<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class StudentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_only_center_owner_and_branch_manager_receive_student_management_permission(): void
    {
        foreach (
            [
                SystemRole::CenterOwner,
                SystemRole::BranchManager,
            ] as $role
        ) {
            $user = $this->createUserForRole(
                $role
            );

            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ManageStudentRecords
                )
            );
        }

        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user = $this->createUserForRole(
                $role
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageStudentRecords
                )
            );
        }
    }

    public function test_center_owner_can_manage_students_inside_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'view',
                    $student
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Student::class,
                        $branch,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'update',
                    $student
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'archive',
                    $student
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'restore',
                    $student
                )
        );
    }

    public function test_center_owner_cannot_manage_student_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->create();

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'view',
                    $studentB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'create',
                    [
                        Student::class,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $studentB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'archive',
                    $studentB
                )
        );
    }

    public function test_branch_manager_can_manage_students_only_in_assigned_branch(): void
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

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branchA
        );

        $studentA = Student::factory()
            ->forBranch($branchA)
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->create();

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $studentA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Student::class,
                        $branchA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $studentA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'archive',
                    $studentA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'restore',
                    $studentA
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $studentB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Student::class,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $studentB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'archive',
                    $studentB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'restore',
                    $studentB
                )
        );
    }

    public function test_branch_manager_without_active_assignment_cannot_manage_students(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $student = Student::factory()
            ->forBranch($branch)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $student
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Student::class,
                        $branch,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $student
                )
        );
    }

    public function test_ended_branch_manager_assignment_revokes_student_access(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $assignment = $this->assignManager(
            $manager,
            $branch
        );

        $student = Student::factory()
            ->forBranch($branch)
            ->create();

        $assignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $student
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $student
                )
        );
    }

    public function test_student_account_can_view_only_own_student_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $studentAccount = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $ownStudent = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->create([
                'user_id' => $studentAccount->id,
            ]);

        $otherStudent = Student::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            Gate::forUser($studentAccount)
                ->allows(
                    'view',
                    $ownStudent
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'view',
                    $otherStudent
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'update',
                    $ownStudent
                )
        );

        $this->assertFalse(
            Gate::forUser($studentAccount)
                ->allows(
                    'archive',
                    $ownStudent
                )
        );
    }

    public function test_teacher_student_record_access_remains_fail_closed_until_class_scope_exists(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $teacher = $this->createUserForRole(
            SystemRole::Teacher,
            $center
        );

        $student = Student::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            $teacher->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        );

        $this->assertFalse(
            Gate::forUser($teacher)
                ->allows(
                    'viewAny',
                    Student::class
                )
        );

        $this->assertFalse(
            Gate::forUser($teacher)
                ->allows(
                    'view',
                    $student
                )
        );

        $this->assertFalse(
            Gate::forUser($teacher)
                ->allows(
                    'update',
                    $student
                )
        );
    }

    public function test_finance_employee_and_platform_owner_cannot_manage_student_records(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->create();

        $financeEmployee =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        foreach (
            [
                $financeEmployee,
                $platformOwner,
            ] as $user
        ) {
            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'viewAny',
                        Student::class
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $student
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            Student::class,
                            $branch,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'update',
                        $student
                    )
            );
        }
    }

    private function assignManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' => $branch->center_id,
                'user_id' => $manager->id,
                'branch_id' => $branch->id,
                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
            ]);
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null,
        ?Person $person = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role($role)->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person ??= Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
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
