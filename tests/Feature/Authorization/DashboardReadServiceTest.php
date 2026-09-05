<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Reports\DashboardReadService;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_dashboard_contains_only_platform_sections(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'platform',
            $dashboard['scope']['scope_type']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNull(
            $dashboard['attendance']
        );

        $this->assertNull(
            $dashboard['finance']
        );

        $this->assertArrayNotHasKey(
            'scope',
            $dashboard['enrollments']
        );

        $this->assertArrayNotHasKey(
            'scope',
            $dashboard['classes']
        );
    }

    public function test_center_owner_dashboard_contains_all_center_sections(): void
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

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'center',
            $dashboard['scope']['scope_type']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNotNull(
            $dashboard['attendance']
        );

        $this->assertNotNull(
            $dashboard['finance']
        );

        $this->assertSame(
            'operational',
            $dashboard['finance']['report_type']
        );
    }

    public function test_branch_manager_dashboard_is_branch_scoped_and_contains_operational_sections(): void
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

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'branch',
            $dashboard['scope']['scope_type']
        );

        $this->assertSame(
            $branch->id,
            $dashboard['scope']['branch_id']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNotNull(
            $dashboard['attendance']
        );

        $this->assertNotNull(
            $dashboard['finance']
        );
    }

    public function test_finance_employee_dashboard_contains_only_financial_section(): void
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

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'branch',
            $dashboard['scope']['scope_type']
        );

        $this->assertNull(
            $dashboard['enrollments']
        );

        $this->assertNull(
            $dashboard['classes']
        );

        $this->assertNull(
            $dashboard['attendance']
        );

        $this->assertNotNull(
            $dashboard['finance']
        );

        $this->assertSame(
            'operational',
            $dashboard['finance']['report_type']
        );
    }

    public function test_teacher_dashboard_contains_academic_and_attendance_sections_only(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'teacher',
            $dashboard['scope']['scope_type']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNotNull(
            $dashboard['attendance']
        );

        $this->assertNull(
            $dashboard['finance']
        );
    }

    public function test_student_dashboard_contains_self_facing_sections(): void
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
                SystemRole::Student,
                $center
            );

        Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $dashboard =
            $this->service()
            ->forUser(
                $actor
            );

        $this->assertSame(
            'student',
            $dashboard['scope']['scope_type']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNotNull(
            $dashboard['attendance']
        );

        $this->assertNotNull(
            $dashboard['finance']
        );

        $this->assertSame(
            'student_balance',
            $dashboard['finance']['report_type']
        );
    }

    public function test_dashboard_fails_closed_when_tenant_context_does_not_match_actor(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->tenant()
            ->establishCenterScope(
                $centerB
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forUser(
                $actor
            );
    }

    private function service(): DashboardReadService
    {
        return app(
            DashboardReadService::class
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