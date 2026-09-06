<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffBranchAssignmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_branch_manager_account_can_have_one_active_branch_assignment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $assignment = $this->createManagerAssignment(
            $manager,
            $branch
        );

        $this->assertTrue(
            $assignment->isActive()
        );

        $this->assertTrue(
            $assignment->branch->is($branch)
        );

        $this->assertTrue(
            $assignment->user->is($manager)
        );
    }

    public function test_branch_manager_account_cannot_have_two_active_assignments(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->create();

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $this->createManagerAssignment(
            $manager,
            $branchA
        );

        $this->expectException(
            QueryException::class
        );

        $this->createManagerAssignment(
            $manager,
            $branchB
        );
    }

    public function test_branch_cannot_have_two_active_branch_managers(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $managerA = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $this->createManagerAssignment(
            $managerA,
            $branch
        );

        $this->expectException(
            QueryException::class
        );

        $this->createManagerAssignment(
            $managerB,
            $branch
        );
    }

    public function test_ended_branch_manager_assignment_remains_as_history_and_releases_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $managerA = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $oldAssignment = $this->createManagerAssignment(
            $managerA,
            $branch
        );

        $oldAssignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);

        $newAssignment = $this->createManagerAssignment(
            $managerB,
            $branch
        );

        $this->assertDatabaseHas(
            'branch_manager_assignments',
            [
                'id' => $oldAssignment->id,
                'user_id' => $managerA->id,
                'branch_id' => $branch->id,
                'active_marker' => null,
            ]
        );

        $this->assertTrue(
            $newAssignment->isActive()
        );

        $this->assertSame(
            2,
            BranchManagerAssignment::query()
                ->where('branch_id', $branch->id)
                ->count()
        );
    }

    public function test_finance_employee_account_cannot_have_two_active_branch_assignments(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->create();

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $this->createFinanceAssignment(
            $finance,
            $branchA
        );

        $this->expectException(
            QueryException::class
        );

        $this->createFinanceAssignment(
            $finance,
            $branchB
        );
    }

    public function test_branch_can_have_multiple_active_finance_employees(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $financeA = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $financeB = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $assignmentA = $this->createFinanceAssignment(
            $financeA,
            $branch
        );

        $assignmentB = $this->createFinanceAssignment(
            $financeB,
            $branch
        );

        $this->assertTrue(
            $assignmentA->isActive()
        );

        $this->assertTrue(
            $assignmentB->isActive()
        );

        $this->assertSame(
            2,
            $branch
                ->activeFinanceEmployeeAssignments()
                ->count()
        );
    }

    public function test_assignment_database_constraints_prevent_cross_center_branch_reference(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $managerA = $this->createRoleAccount(
            SystemRole::BranchManager,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        BranchManagerAssignment::query()->create([
            'center_id' => $centerA->id,
            'user_id' => $managerA->id,
            'branch_id' => $branchB->id,
            'started_at' => now(),
            'ended_at' => null,
            'active_marker' => 1,
        ]);
    }

    public function test_user_and_branch_expose_current_assignment_relationships(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $managerAssignment = $this->createManagerAssignment(
            $manager,
            $branch
        );

        $financeAssignment = $this->createFinanceAssignment(
            $finance,
            $branch
        );

        $this->assertTrue(
            $manager
                ->activeBranchManagerAssignment
                ->is($managerAssignment)
        );

        $this->assertTrue(
            $branch
                ->activeBranchManagerAssignment
                ->is($managerAssignment)
        );

        $this->assertTrue(
            $finance
                ->activeFinanceEmployeeAssignment
                ->is($financeAssignment)
        );

        $this->assertTrue(
            $branch
                ->activeFinanceEmployeeAssignments
                ->contains($financeAssignment)
        );
    }

    private function createManagerAssignment(
        User $user,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()->create([
            'center_id' => $branch->center_id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'started_at' => now(),
            'ended_at' => null,
            'active_marker' => 1,
        ]);
    }

    private function createFinanceAssignment(
        User $user,
        Branch $branch
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment::query()->create([
            'center_id' => $branch->center_id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'started_at' => now(),
            'ended_at' => null,
            'active_marker' => 1,
        ]);
    }

    private function createRoleAccount(
        SystemRole $role,
        Center $center
    ): User {
        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role($role)->id,
        ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where('code', $role->value)
            ->firstOrFail();
    }
}
