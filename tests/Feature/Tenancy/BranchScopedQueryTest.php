<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class BranchScopedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware([
            'web',
            'auth',
            'tenant.context',
            'branch.context',
        ])->group(function (): void {
            Route::get(
                '/_test/branch-manager-assignments',
                function () {
                    return response()->json([
                        'ids' => BranchManagerAssignment::query()
                            ->forCurrentBranch()
                            ->orderBy('id')
                            ->pluck('id')
                            ->all(),
                    ]);
                }
            );

            Route::get(
                '/_test/branch-manager-assignments/{assignmentId}',
                function (string $assignmentId) {
                    $assignment =
                        BranchManagerAssignment::query()
                        ->forCurrentBranch()
                        ->findOrFail($assignmentId);

                    return response()->json([
                        'id' => $assignment->id,
                        'center_id' => $assignment->center_id,
                        'branch_id' => $assignment->branch_id,
                        'user_id' => $assignment->user_id,
                    ]);
                }
            );

            Route::get(
                '/_test/finance-employee-assignments',
                function () {
                    return response()->json([
                        'ids' => FinanceEmployeeAssignment::query()
                            ->forCurrentBranch()
                            ->orderBy('id')
                            ->pluck('id')
                            ->all(),
                    ]);
                }
            );
        });
    }

    public function test_center_owner_branch_scoped_query_is_center_wide_but_never_cross_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createCenterUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchA1 = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchA2 = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $managerA1 = $this->createCenterUser(
            SystemRole::BranchManager,
            $centerA
        );

        $managerA2 = $this->createCenterUser(
            SystemRole::BranchManager,
            $centerA
        );

        $assignmentA1 = $this->assignManager(
            $managerA1,
            $branchA1
        );

        $assignmentA2 = $this->assignManager(
            $managerA2,
            $branchA2
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $managerB = $this->createCenterUser(
            SystemRole::BranchManager,
            $centerB
        );

        $assignmentB = $this->assignManager(
            $managerB,
            $branchB
        );

        $response = $this
            ->actingAs($ownerA)
            ->getJson(
                '/_test/branch-manager-assignments'
            );

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertContains(
            $assignmentA1->id,
            $ids
        );

        $this->assertContains(
            $assignmentA2->id,
            $ids
        );

        $this->assertNotContains(
            $assignmentB->id,
            $ids
        );

        $this->assertCount(
            2,
            $ids
        );
    }

    public function test_branch_manager_query_is_restricted_to_assigned_branch(): void
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

        $managerA = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $assignmentA = $this->assignManager(
            $managerA,
            $branchA
        );

        $assignmentB = $this->assignManager(
            $managerB,
            $branchB
        );

        $response = $this
            ->actingAs($managerA)
            ->getJson(
                '/_test/branch-manager-assignments'
            );

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertSame(
            [$assignmentA->id],
            $ids
        );

        $this->assertNotContains(
            $assignmentB->id,
            $ids
        );
    }

    public function test_finance_employee_query_is_restricted_to_assigned_branch(): void
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

        $financeA = $this->createCenterUser(
            SystemRole::FinanceEmployee,
            $center
        );

        $financeColleagueA = $this->createCenterUser(
            SystemRole::FinanceEmployee,
            $center
        );

        $financeB = $this->createCenterUser(
            SystemRole::FinanceEmployee,
            $center
        );

        $assignmentA = $this->assignFinance(
            $financeA,
            $branchA
        );

        $colleagueAssignmentA = $this->assignFinance(
            $financeColleagueA,
            $branchA
        );

        $assignmentB = $this->assignFinance(
            $financeB,
            $branchB
        );

        $response = $this
            ->actingAs($financeA)
            ->getJson(
                '/_test/finance-employee-assignments'
            );

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertContains(
            $assignmentA->id,
            $ids
        );

        $this->assertContains(
            $colleagueAssignmentA->id,
            $ids
        );

        $this->assertNotContains(
            $assignmentB->id,
            $ids
        );

        $this->assertCount(
            2,
            $ids
        );
    }

    public function test_request_branch_id_cannot_override_assigned_branch_query_scope(): void
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

        $managerA = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $assignmentA = $this->assignManager(
            $managerA,
            $branchA
        );

        $assignmentB = $this->assignManager(
            $managerB,
            $branchB
        );

        $response = $this
            ->actingAs($managerA)
            ->getJson(
                '/_test/branch-manager-assignments?branch_id='
                    . $branchB->id
            );

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertSame(
            [$assignmentA->id],
            $ids
        );

        $this->assertNotContains(
            $assignmentB->id,
            $ids
        );
    }

    public function test_direct_read_of_other_branch_record_returns_not_found(): void
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

        $managerA = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $managerA,
            $branchA
        );

        $assignmentB = $this->assignManager(
            $managerB,
            $branchB
        );

        $response = $this
            ->actingAs($managerA)
            ->getJson(
                '/_test/branch-manager-assignments/'
                    . $assignmentB->id
            );

        $response->assertNotFound();
    }

    public function test_center_owner_cannot_directly_read_assignment_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createCenterUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $managerB = $this->createCenterUser(
            SystemRole::BranchManager,
            $centerB
        );

        $assignmentB = $this->assignManager(
            $managerB,
            $branchB
        );

        $response = $this
            ->actingAs($ownerA)
            ->getJson(
                '/_test/branch-manager-assignments/'
                    . $assignmentB->id
            );

        $response->assertNotFound();
    }

    public function test_branch_scoped_query_fails_without_established_contexts(): void
    {
        app(TenantContext::class)
            ->clear();

        app(BranchContext::class)
            ->clear();

        $this->expectException(
            LogicException::class
        );

        BranchManagerAssignment::query()
            ->forCurrentBranch()
            ->get();
    }

    public function test_branch_scoped_query_fails_when_branch_context_is_missing(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope($center);

        app(BranchContext::class)
            ->clear();

        $this->expectException(
            LogicException::class
        );

        BranchManagerAssignment::query()
            ->forCurrentBranch()
            ->get();
    }

    public function test_inconsistent_branch_and_tenant_contexts_are_rejected(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope($centerA);

        app(BranchContext::class)
            ->establishBranchScope($branchB);

        $this->expectException(
            AuthorizationException::class
        );

        BranchManagerAssignment::query()
            ->forCurrentBranch()
            ->get();
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

    private function assignFinance(
        User $finance,
        Branch $branch
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment::query()
            ->create([
                'center_id' => $branch->center_id,
                'user_id' => $finance->id,
                'branch_id' => $branch->id,
                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
            ]);
    }

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
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
