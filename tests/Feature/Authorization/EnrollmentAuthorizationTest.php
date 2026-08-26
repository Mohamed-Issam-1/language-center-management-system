<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
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

class EnrollmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_only_center_owner_and_branch_manager_receive_enrollment_management_permission(): void
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
                    SystemPermission::ManageEnrollments
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
            $user =
                $this->createUserForRole(
                    $role
                );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageEnrollments
                )
            );
        }
    }

    public function test_center_owner_can_manage_enrollment_inside_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
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

        $student = Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $sourceClass =
            CourseClass::factory()
            ->forBranch($branchA)
            ->create();

        $targetClass =
            CourseClass::factory()
            ->forBranch($branchB)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Enrollment::class,
                        $student,
                        $sourceClass,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'updateStatus',
                    $enrollment
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'withdraw',
                    $enrollment
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'transfer',
                    [
                        $enrollment,
                        $targetClass,
                    ]
                )
        );
    }

    public function test_center_owner_cannot_manage_enrollment_from_another_center(): void
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

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $studentB =
            Student::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $classB =
            CourseClass::factory()
            ->forBranch($branchB)
            ->create();

        $enrollmentB =
            Enrollment::factory()
            ->forStudent($studentB)
            ->forCourseClass($classB)
            ->create();

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'create',
                    [
                        Enrollment::class,
                        $studentB,
                        $classB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'updateStatus',
                    $enrollmentB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'withdraw',
                    $enrollmentB
                )
        );
    }

    public function test_branch_manager_can_manage_enrollment_inside_exact_assigned_branch(): void
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
            $branch
        );

        $student =
            Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $sourceClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $targetClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->create();

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Enrollment::class,
                        $student,
                        $sourceClass,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'updateStatus',
                    $enrollment
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'withdraw',
                    $enrollment
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'transfer',
                    [
                        $enrollment,
                        $targetClass,
                    ]
                )
        );
    }

    public function test_branch_manager_cannot_manage_student_and_class_from_different_branches(): void
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

        $studentA =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->forBranch($branchB)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Enrollment::class,
                        $studentA,
                        $classB,
                    ]
                )
        );
    }

    public function test_branch_manager_cannot_transfer_to_another_branch(): void
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

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $sourceClass =
            CourseClass::factory()
            ->forBranch($branchA)
            ->create();

        $targetClass =
            CourseClass::factory()
            ->forBranch($branchB)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'transfer',
                    [
                        $enrollment,
                        $targetClass,
                    ]
                )
        );
    }

    public function test_ended_branch_manager_assignment_does_not_grant_enrollment_management_scope(): void
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

        $assignment =
            $this->assignManager(
                $manager,
                $branch
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->create();

        $assignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Enrollment::class,
                        $student,
                        $courseClass,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'updateStatus',
                    $enrollment
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'withdraw',
                    $enrollment
                )
        );
    }

    public function test_other_roles_cannot_manage_enrollments(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->create();

        foreach (
            [
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user =
                $this->createUserForRole(
                    $role,
                    $center
                );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            Enrollment::class,
                            $student,
                            $courseClass,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'updateStatus',
                        $enrollment
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'withdraw',
                        $enrollment
                    )
            );
        }

        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'updateStatus',
                    $enrollment
                )
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

                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
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

        $center ??= Center::factory()
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
