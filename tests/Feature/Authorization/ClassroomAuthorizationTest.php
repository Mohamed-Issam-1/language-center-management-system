<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ClassroomAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_only_center_owner_and_branch_manager_receive_classroom_management_permission(): void
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
                    SystemPermission::ManageClassrooms
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
                    SystemPermission::ManageClassrooms
                )
            );
        }
    }

    public function test_center_owner_can_manage_classroom_inside_own_center(): void
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

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Classroom::class,
                        $branch,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'update',
                    $classroom
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'activate',
                    $classroom
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'deactivate',
                    $classroom
                )
        );
    }

    public function test_center_owner_cannot_manage_classroom_from_another_center(): void
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

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'create',
                    [
                        Classroom::class,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $classroomB
                )
        );
    }

    public function test_branch_manager_can_manage_classroom_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->assignManager(
            $manager,
            $branch
        );

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Classroom::class,
                        $branch,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $classroom
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'activate',
                    $classroom
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'deactivate',
                    $classroom
                )
        );
    }

    public function test_branch_manager_cannot_manage_classroom_in_another_branch_of_same_center(): void
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

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Classroom::class,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $classroomB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'deactivate',
                    $classroomB
                )
        );
    }

    public function test_ended_branch_manager_assignment_does_not_grant_classroom_management_scope(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $assignment = $this->assignManager(
            $manager,
            $branch
        );

        $classroom = Classroom::factory()
            ->forBranch($branch)
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
                        Classroom::class,
                        $branch,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $classroom
                )
        );
    }

    public function test_other_roles_cannot_manage_classrooms(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        foreach (
            [
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user = $this->createUserForRole(
                $role,
                $center
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            Classroom::class,
                            $branch,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'update',
                        $classroom
                    )
            );
        }

        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'update',
                    $classroom
                )
        );
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
        ?Center $center = null
    ): User {
        if ($role === SystemRole::PlatformOwner) {
            return User::factory()->create([
                'center_id' => null,
                'person_id' => null,
                'role_id' => $this->role(
                    $role
                )->id,
                'status' => AccountStatus::Active,
            ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(
                $role
            )->id,
            'status' => AccountStatus::Active,
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
