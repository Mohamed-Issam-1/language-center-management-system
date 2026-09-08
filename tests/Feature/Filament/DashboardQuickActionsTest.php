<?php

namespace Tests\Feature\Filament;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_receives_platform_dashboard_actions(): void
    {
        $actor =
            $this->createPlatformOwner();

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk()
            ->assertSee(
                'Manage Centers'
            )
            ->assertSee(
                'Manage Center Owners'
            )
            ->assertSee(
                'View Reports'
            )
            ->assertDontSee(
                'Manage Enrollments'
            );
    }

    public function test_center_owner_receives_center_dashboard_actions(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk()
            ->assertSee(
                'Review Registrations'
            )
            ->assertSee(
                'Manage Enrollments'
            )
            ->assertSee(
                'Manage Classes'
            )
            ->assertSee(
                'Financial Operations'
            )
            ->assertDontSee(
                'Manage Centers'
            );
    }

    public function test_branch_manager_receives_branch_dashboard_actions(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for(
                $center
            )
            ->active()
            ->create();

        $actor =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk()
            ->assertSee(
                'Record Attendance'
            )
            ->assertSee(
                'Manage Sessions'
            )
            ->assertSee(
                'Manage Enrollments'
            )
            ->assertSee(
                'Financial Operations'
            )
            ->assertDontSee(
                'Review Registrations'
            );
    }

    public function test_finance_employee_receives_finance_dashboard_actions(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for(
                $center
            )
            ->active()
            ->create();

        $actor =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk()
            ->assertSee(
                'Record Payments'
            )
            ->assertSee(
                'Manage Fees'
            )
            ->assertSee(
                'Student Balances'
            )
            ->assertSee(
                'Financial Reports'
            )
            ->assertDontSee(
                'Manage Enrollments'
            );
    }

    private function createPlatformOwner(): User
    {
        return User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for(
                $center
            )
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

                'must_change_password' =>
                false,
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
