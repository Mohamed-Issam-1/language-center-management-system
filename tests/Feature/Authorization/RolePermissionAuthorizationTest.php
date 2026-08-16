<?php

namespace Tests\Feature\Authorization;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolePermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_platform_owner_has_only_documented_platform_capabilities(): void
    {
        $user = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageCenters
            )
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageCenterOwnerAccounts
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageStudentRecords
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );
    }

    public function test_center_owner_has_center_administration_and_financial_capabilities(): void
    {
        $user = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageStaffAccounts
            )
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageStudentAccounts
            )
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageCenters
            )
        );
    }

    public function test_branch_manager_includes_finance_employee_financial_capabilities(): void
    {
        $branchManager = $this->createUserForRole(
            SystemRole::BranchManager
        );

        $financeEmployee = $this->createUserForRole(
            SystemRole::FinanceEmployee
        );

        foreach (
            [
                SystemPermission::ViewFinancialData,
                SystemPermission::ManageFinancialOperations,
                SystemPermission::ViewReports,
            ] as $permission
        ) {
            $this->assertTrue(
                $financeEmployee->hasPermission(
                    $permission
                )
            );

            $this->assertTrue(
                $branchManager->hasPermission(
                    $permission
                )
            );
        }

        $this->assertFalse(
            $branchManager->hasPermission(
                SystemPermission::ManageStaffAccounts
            )
        );
    }

    public function test_finance_employee_is_not_granted_student_or_staff_management_permissions(): void
    {
        $user = $this->createUserForRole(
            SystemRole::FinanceEmployee
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageStudentRecords
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageStaffAccounts
            )
        );
    }

    public function test_teacher_has_no_financial_permission(): void
    {
        $user = $this->createUserForRole(
            SystemRole::Teacher
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );
    }

    public function test_student_has_self_facing_capabilities_without_management_permissions(): void
    {
        $user = $this->createUserForRole(
            SystemRole::Student
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        );

        $this->assertTrue(
            $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageStudentRecords
            )
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );
    }

    public function test_accounts_for_same_person_do_not_combine_permissions(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = $this->createUserForRole(
            SystemRole::Teacher,
            $center,
            $person
        );

        $branchManager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center,
            $person
        );

        $this->assertFalse(
            Gate::forUser($teacher)->allows(
                SystemPermission::ManageFinancialOperations->value
            )
        );

        $this->assertTrue(
            Gate::forUser($branchManager)->allows(
                SystemPermission::ManageFinancialOperations->value
            )
        );
    }

    public function test_registered_permission_gates_use_current_account_role(): void
    {
        $teacher = $this->createUserForRole(
            SystemRole::Teacher
        );

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertFalse(
            Gate::forUser($teacher)->allows(
                SystemPermission::ManageStudentAccounts->value
            )
        );

        $this->assertTrue(
            Gate::forUser($centerOwner)->allows(
                SystemPermission::ManageStudentAccounts->value
            )
        );
    }

    public function test_center_policy_allows_only_platform_owner_to_manage_centers(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertTrue(
            Gate::forUser($platformOwner)
                ->allows('create', Center::class)
        );

        $this->assertTrue(
            Gate::forUser($platformOwner)
                ->allows('update', $center)
        );

        $this->assertTrue(
            Gate::forUser($platformOwner)
                ->allows('activate', $center)
        );

        $this->assertTrue(
            Gate::forUser($platformOwner)
                ->allows('suspend', $center)
        );

        $this->assertFalse(
            Gate::forUser($centerOwner)
                ->allows('update', $center)
        );
    }

    public function test_unknown_role_is_denied_system_permissions(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $unknownRole = Role::query()->create([
            'code' => 'unknown_role',
            'name' => 'Unknown Role',
        ]);

        $user = User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $unknownRole->id,
        ]);

        $this->assertNull(
            $user->systemRole()
        );

        $this->assertFalse(
            $user->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        );

        $this->assertFalse(
            Gate::forUser($user)->allows(
                SystemPermission::ViewStudentRecords->value
            )
        );
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null,
        ?Person $person = null
    ): User {
        if ($role === SystemRole::PlatformOwner) {
            return User::factory()->create([
                'center_id' => null,
                'person_id' => null,
                'role_id' => $this->role($role)->id,
            ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person ??= Person::factory()
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
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}
