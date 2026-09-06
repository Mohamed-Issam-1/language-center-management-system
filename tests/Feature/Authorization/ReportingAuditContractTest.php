<?php

namespace Tests\Feature\Authorization;

use App\Filament\Pages\Reports;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\DashboardReadService;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportingAuditContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_dashboard_reads_do_not_create_audit_records(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $actor =
            $this->createCenterOwner(
                $center
            );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $before =
            DB::table(
                'audit_records'
            )->count();

        $dashboard =
            app(
                DashboardReadService::class
            )->forUser(
                $actor
            );

        $this->assertSame(
            SystemRole::CenterOwner->value,
            $dashboard['scope']['role']
        );

        $after =
            DB::table(
                'audit_records'
            )->count();

        $this->assertSame(
            $before,
            $after
        );
    }

    public function test_report_views_filters_and_exports_do_not_create_audit_records(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $actor =
            $this->createCenterOwner(
                $center
            );

        $this->actingAs(
            $actor
        );

        Filament::setCurrentPanel(
            Filament::getPanel(
                'admin'
            )
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $before =
            DB::table(
                'audit_records'
            )->count();

        /*
         * Mounting the page loads the current authorized
         * report dataset.
         */
        Livewire::test(
            Reports::class
        )
            ->call(
                'refreshReport'
            )
            ->call(
                'applyFilters'
            );

        /*
         * File extraction remains a read operation under
         * the current LCMS audit policy.
         */
        Livewire::test(
            Reports::class
        )
            ->call(
                'exportCsv'
            )
            ->assertFileDownloaded(
                'lcms-academic-report.csv'
            );

        Livewire::test(
            Reports::class
        )
            ->call(
                'exportPdf'
            )
            ->assertFileDownloaded(
                'lcms-academic-report.pdf'
            );

        $after =
            DB::table(
                'audit_records'
            )->count();

        $this->assertSame(
            $before,
            $after
        );
    }

    private function createCenterOwner(
        Center $center
    ): User {
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
                    SystemRole::CenterOwner
                )->id,
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