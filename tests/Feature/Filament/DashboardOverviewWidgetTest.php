<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DashboardOverviewWidget;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_sees_platform_overview_stats(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->actingAs(
            $actor
        );

        $this->tenant()
            ->establishPlatformScope();

        Livewire::test(
            DashboardOverviewWidget::class
        )
            ->assertSuccessful()
            ->assertSee(
                'Total Enrollments'
            )
            ->assertSee(
                'Active Enrollments'
            )
            ->assertSee(
                'Total Classes'
            )
            ->assertSee(
                'Active Classes'
            )
            ->assertDontSee(
                'Attendance'
            )
            ->assertDontSee(
                'Financial Summary'
            );
    }

    public function test_center_owner_sees_academic_attendance_and_finance_stats(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $actor
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        Livewire::test(
            DashboardOverviewWidget::class
        )
            ->assertSuccessful()
            ->assertSee(
                'Total Enrollments'
            )
            ->assertSee(
                'Total Classes'
            )
            ->assertSee(
                'Attendance'
            )
            ->assertSee(
                'Financial Summary'
            );
    }

    public function test_branch_manager_sees_assigned_branch_overview(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
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

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->actingAs(
            $actor
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        Livewire::test(
            DashboardOverviewWidget::class
        )
            ->assertSuccessful()
            ->assertSee(
                'Total Enrollments'
            )
            ->assertSee(
                'Total Classes'
            )
            ->assertSee(
                'Attendance'
            )
            ->assertSee(
                'Financial Summary'
            );
    }

    public function test_finance_employee_sees_only_finance_overview(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
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

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->actingAs(
            $actor
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        Livewire::test(
            DashboardOverviewWidget::class
        )
            ->assertSuccessful()
            ->assertSee(
                'Financial Summary'
            )
            ->assertDontSee(
                'Total Enrollments'
            )
            ->assertDontSee(
                'Total Classes'
            )
            ->assertDontSee(
                'Attendance'
            );
    }

    private function tenant(): TenantContext
    {
        return app(
            TenantContext::class
        );
    }

    private function branchContext(): BranchContext
    {
        return app(
            BranchContext::class
        );
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
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this->role(
                        $role
                    )->id,
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
            ]);
    }
}