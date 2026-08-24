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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BranchContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        /*
         * Test-only route. Production routes will opt into
         * branch.context only when Branch operational scope
         * is actually required.
         */
        Route::middleware([
            'web',
            'auth',
            'tenant.context',
            'branch.context',
        ])->get(
            '/_test/branch-context',
            function (
                Request $request,
                BranchContext $branchContext
            ) {
                return response()->json([
                    'established' => $branchContext
                        ->isEstablished(),

                    'scope' => $branchContext
                        ->isCenterWide()
                        ? 'center'
                        : (
                            $branchContext
                            ->isBranchScoped()
                            ? 'branch'
                            : 'none'
                        ),

                    'branch_id' => $branchContext
                        ->branchId(),

                    /*
                     * Exposed only to prove that request input
                     * cannot control operational Branch scope.
                     */
                    'requested_branch_id' => $request
                        ->input('branch_id'),
                ]);
            }
        );
    }

    public function test_center_owner_receives_center_wide_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createCenterUser(
            SystemRole::CenterOwner,
            $center
        );

        $response = $this
            ->actingAs($owner)
            ->getJson('/_test/branch-context');

        $response
            ->assertOk()
            ->assertJson([
                'established' => true,
                'scope' => 'center',
                'branch_id' => null,
            ]);
    }

    public function test_branch_manager_receives_assigned_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createCenterUser(
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

        $response = $this
            ->actingAs($manager)
            ->getJson('/_test/branch-context');

        $response
            ->assertOk()
            ->assertJson([
                'established' => true,
                'scope' => 'branch',
                'branch_id' => $branch->id,
            ]);
    }

    public function test_finance_employee_receives_assigned_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $finance = $this->createCenterUser(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->assignFinanceEmployee(
            $finance,
            $branch
        );

        $response = $this
            ->actingAs($finance)
            ->getJson('/_test/branch-context');

        $response
            ->assertOk()
            ->assertJson([
                'established' => true,
                'scope' => 'branch',
                'branch_id' => $branch->id,
            ]);
    }

    public function test_request_branch_id_cannot_override_branch_manager_assignment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        $response = $this
            ->actingAs($manager)
            ->getJson(
                '/_test/branch-context?branch_id='
                    . $otherBranch->id
            );

        $response
            ->assertOk()
            ->assertJson([
                'scope' => 'branch',
                'branch_id' => $assignedBranch->id,
                'requested_branch_id' => (string) $otherBranch->id,
            ]);

        $this->assertNotSame(
            $otherBranch->id,
            $response->json('branch_id')
        );
    }

    public function test_branch_manager_without_active_assignment_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $response = $this
            ->actingAs($manager)
            ->getJson('/_test/branch-context');

        $response->assertForbidden();
    }

    public function test_finance_employee_without_active_assignment_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $finance = $this->createCenterUser(
            SystemRole::FinanceEmployee,
            $center
        );

        $response = $this
            ->actingAs($finance)
            ->getJson('/_test/branch-context');

        $response->assertForbidden();
    }

    public function test_ended_assignment_does_not_establish_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        BranchManagerAssignment::query()
            ->create([
                'center_id' => $center->id,
                'user_id' => $manager->id,
                'branch_id' => $branch->id,
                'started_at' => now()
                    ->subDay(),
                'ended_at' => now(),
                'active_marker' => null,
            ]);

        $response = $this
            ->actingAs($manager)
            ->getJson('/_test/branch-context');

        $response->assertForbidden();
    }

    public function test_platform_owner_cannot_use_branch_operational_context(): void
    {
        $platformOwner = User::factory()
            ->create([
                'center_id' => null,
                'person_id' => null,
                'role_id' => $this->role(
                    SystemRole::PlatformOwner
                )->id,
                'status' => AccountStatus::Active,
            ]);

        $response = $this
            ->actingAs($platformOwner)
            ->getJson('/_test/branch-context');

        $response->assertForbidden();
    }

    public function test_teacher_and_student_do_not_receive_branch_context(): void
    {
        foreach (
            [
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $center = Center::factory()
                ->active()
                ->create();

            $user = $this->createCenterUser(
                $role,
                $center
            );

            $response = $this
                ->actingAs($user)
                ->getJson('/_test/branch-context');

            $response->assertForbidden();
        }
    }

    public function test_branch_context_is_reestablished_for_each_request(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $firstResponse = $this
            ->actingAs($manager)
            ->getJson('/_test/branch-context');

        $firstResponse
            ->assertOk()
            ->assertJson([
                'scope' => 'branch',
                'branch_id' => $branch->id,
            ]);

        $owner = $this->createCenterUser(
            SystemRole::CenterOwner,
            $center
        );

        $secondResponse = $this
            ->actingAs($owner)
            ->getJson('/_test/branch-context');

        $secondResponse
            ->assertOk()
            ->assertJson([
                'scope' => 'center',
                'branch_id' => null,
            ]);
    }

    public function test_deactivated_assigned_branch_remains_resolvable_for_historical_scope(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createCenterUser(
            SystemRole::BranchManager,
            $center
        );

        /*
         * Represents an assignment created while the Branch
         * was active, followed later by Branch deactivation.
         */
        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $this->assignManager(
            $manager,
            $branch
        );

        $response = $this
            ->actingAs($manager)
            ->getJson('/_test/branch-context');

        $response
            ->assertOk()
            ->assertJson([
                'scope' => 'branch',
                'branch_id' => $branch->id,
            ]);
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

    private function assignFinanceEmployee(
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

        return User::factory()
            ->create([
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
