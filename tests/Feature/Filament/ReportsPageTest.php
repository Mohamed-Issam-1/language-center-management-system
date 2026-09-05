<?php

namespace Tests\Feature\Filament;

use App\Models\Branch;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Filament\Pages\Reports;
use App\Models\CourseClass;
use App\Services\Reports\ReportDatasetService;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_render_reports_page_with_all_operational_report_types(): void
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
                '/admin/reports'
            )
            ->assertOk()
            ->assertSee(
                'Reports'
            )
            ->assertSee(
                'Academic'
            )
            ->assertSee(
                'Enrollment'
            )
            ->assertSee(
                'Attendance'
            )
            ->assertSee(
                'Financial'
            )
            ->assertSee(
                'No report records match the current authorized scope and filters.'
            )
            ->assertSee(
                'Export CSV'
            )
            ->assertSee(
                'Export PDF'
            );
    }

    public function test_platform_owner_reports_page_exposes_only_supported_report_types(): void
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
                '/admin/reports'
            )
            ->assertOk()
            ->assertSee(
                'Academic'
            )
            ->assertSee(
                'Enrollment'
            )
            ->assertDontSee(
                '>Attendance<',
                false
            )
            ->assertDontSee(
                '>Financial<',
                false
            );
    }

    public function test_finance_employee_reports_page_exposes_only_financial_report(): void
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

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/admin/reports'
            )
            ->assertOk()
            ->assertSee(
                'Financial'
            )
            ->assertDontSee(
                '>Academic<',
                false
            )
            ->assertDontSee(
                '>Enrollment<',
                false
            )
            ->assertDontSee(
                '>Attendance<',
                false
            );
    }

    public function test_center_owner_can_apply_academic_branch_filter_through_livewire_page(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

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

        Livewire::test(
            Reports::class
        )
            ->set(
                'filters.branch_id',
                $branchA->id
            )
            ->call(
                'applyFilters'
            )
            ->assertSet(
                'dataset.row_count',
                1
            )
            ->assertSet(
                'dataset.rows.0.class_id',
                $classA->id
            )
            ->assertSet(
                'dataset.filters.branch_id',
                $branchA->id
            );
    }

    public function test_switching_report_type_clears_previous_filter_state(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
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

        Livewire::test(
            Reports::class
        )
            ->set(
                'filters.branch_id',
                123
            )
            ->call(
                'selectReport',
                ReportDatasetService::ENROLLMENT
            )
            ->assertSet(
                'reportType',
                ReportDatasetService::ENROLLMENT
            )
            ->assertSet(
                'filters',
                []
            )
            ->assertSet(
                'filterError',
                null
            )
            ->assertSet(
                'dataset.report_type',
                ReportDatasetService::ENROLLMENT
            );
    }

    public function test_center_owner_can_download_current_academic_report_as_csv(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

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

        Livewire::test(
            Reports::class
        )
            ->set(
                'filters.branch_id',
                $branch->id
            )
            ->call(
                'applyFilters'
            )
            ->assertSet(
                'dataset.row_count',
                1
            )
            ->assertSet(
                'dataset.rows.0.class_id',
                $courseClass->id
            )
            ->call(
                'exportCsv'
            )
            ->assertFileDownloaded(
                'lcms-academic-report.csv'
            );
    }

    public function test_finance_employee_can_download_only_current_financial_report_csv(): void
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
            ->establishBranchScope(
                $branch
            );

        Livewire::test(
            Reports::class
        )
            ->assertSet(
                'reportType',
                ReportDatasetService::FINANCIAL
            )
            ->call(
                'exportCsv'
            )
            ->assertFileDownloaded(
                'lcms-financial-report.csv'
            );
    }

    public function test_center_owner_can_download_filtered_academic_report_as_pdf(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

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

        Livewire::test(
            Reports::class
        )
            ->set(
                'filters.branch_id',
                $branchA->id
            )
            ->call(
                'applyFilters'
            )
            ->assertSet(
                'filterError',
                null
            )
            ->assertSet(
                'dataset.row_count',
                1
            )
            ->assertSet(
                'dataset.rows.0.class_id',
                $classA->id
            )
            ->assertSet(
                'dataset.filters.branch_id',
                $branchA->id
            )
            ->call(
                'exportPdf'
            )
            ->assertFileDownloaded(
                'lcms-academic-report.pdf'
            );
    }

    public function test_finance_employee_can_download_filtered_financial_report_as_pdf(): void
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
            ->establishBranchScope(
                $branch
            );

        Livewire::test(
            Reports::class
        )
            ->set(
                'filters.currency',
                'USD'
            )
            ->call(
                'applyFilters'
            )
            ->assertSet(
                'filterError',
                null
            )
            ->assertSet(
                'dataset.filters.currency',
                'USD'
            )
            ->call(
                'exportPdf'
            )
            ->assertFileDownloaded(
                'lcms-financial-report.pdf'
            );
    }

    public function test_csv_values_are_hardened_against_spreadsheet_formula_injection(): void
    {
        $reflection =
            new \ReflectionClass(
                Reports::class
            );

        $page =
            $reflection
            ->newInstanceWithoutConstructor();

        $method =
            $reflection
            ->getMethod(
                'csvValue'
            );

        $this->assertSame(
            '',
            $method->invoke(
                $page,
                null
            )
        );

        $this->assertSame(
            125,
            $method->invoke(
                $page,
                125
            )
        );

        $this->assertSame(
            -125.50,
            $method->invoke(
                $page,
                -125.50
            )
        );

        $this->assertSame(
            'Normal value',
            $method->invoke(
                $page,
                'Normal value'
            )
        );

        $this->assertSame(
            "'=SUM(A1:A10)",
            $method->invoke(
                $page,
                '=SUM(A1:A10)'
            )
        );

        $this->assertSame(
            "'+SUM(A1:A10)",
            $method->invoke(
                $page,
                '+SUM(A1:A10)'
            )
        );

        $this->assertSame(
            "'-SUM(A1:A10)",
            $method->invoke(
                $page,
                '-SUM(A1:A10)'
            )
        );

        $this->assertSame(
            "'@SUM(A1:A10)",
            $method->invoke(
                $page,
                '@SUM(A1:A10)'
            )
        );

        $this->assertSame(
            "'\t=SUM(A1:A10)",
            $method->invoke(
                $page,
                "\t=SUM(A1:A10)"
            )
        );

        $this->assertSame(
            "'\r=SUM(A1:A10)",
            $method->invoke(
                $page,
                "\r=SUM(A1:A10)"
            )
        );

        $this->assertSame(
            "'   =SUM(A1:A10)",
            $method->invoke(
                $page,
                '   =SUM(A1:A10)'
            )
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