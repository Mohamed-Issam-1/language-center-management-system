<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DashboardOverviewWidget;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_admin_panel_registers_lcms_dashboard_widget_without_filament_info_widget(): void
    {
        $widgets =
            Filament::getPanel(
                'admin'
            )->getWidgets();

        $this->assertContains(
            DashboardOverviewWidget::class,
            $widgets
        );

        $this->assertContains(
            AccountWidget::class,
            $widgets
        );

        $this->assertNotContains(
            FilamentInfoWidget::class,
            $widgets
        );
    }

    public function test_platform_owner_can_render_actual_admin_dashboard(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk();
    }

    public function test_center_owner_can_render_actual_admin_dashboard(): void
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

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin'
            )
            ->assertOk();
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