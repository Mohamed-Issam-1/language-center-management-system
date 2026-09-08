<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserAccounts\UserAccountResource;
use App\Filament\Resources\UserAccounts\Pages\ListUserAccounts;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserAccountResourceAccessTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_sees_only_center_owner_accounts(): void
    {
        $platformOwner =
            $this->platformOwner();

        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $centerOwnerA =
            $this->account(
                SystemRole::CenterOwner,
                $centerA
            );

        $centerOwnerB =
            $this->account(
                SystemRole::CenterOwner,
                $centerB
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $centerA
            );

        $student =
            $this->account(
                SystemRole::Student,
                $centerB
            );

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertCanSeeTableRecords([
                $centerOwnerA,
                $centerOwnerB,
            ])
            ->assertCanNotSeeTableRecords([
                $platformOwner,
                $teacher,
                $student,
            ]);
    }

    public function test_center_owner_sees_only_manageable_accounts_in_own_center(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $student =
            $this->account(
                SystemRole::Student,
                $center
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $center
            );

        $manager =
            $this->account(
                SystemRole::BranchManager,
                $center
            );

        $finance =
            $this->account(
                SystemRole::FinanceEmployee,
                $center
            );

        $otherOwner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $crossCenterTeacher =
            $this->account(
                SystemRole::Teacher,
                $otherCenter
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertCanSeeTableRecords([
                $student,
                $teacher,
                $manager,
                $finance,
            ])
            ->assertCanNotSeeTableRecords([
                $owner,
                $otherOwner,
                $crossCenterTeacher,
            ]);
    }

    public function test_branch_manager_sees_only_linked_student_accounts_in_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $assignedBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->account(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment
            ::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $assignedBranch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $visiblePerson =
            Person::factory()
            ->for($center)
            ->create();

        $visibleStudentAccount =
            $this->account(
                SystemRole::Student,
                $center,
                $visiblePerson
            );

        Student::factory()
            ->forBranch(
                $assignedBranch
            )
            ->forPerson(
                $visiblePerson
            )
            ->active()
            ->create([
                'user_id' =>
                $visibleStudentAccount->id,
            ]);

        $otherPerson =
            Person::factory()
            ->for($center)
            ->create();

        $otherBranchStudentAccount =
            $this->account(
                SystemRole::Student,
                $center,
                $otherPerson
            );

        Student::factory()
            ->forBranch(
                $otherBranch
            )
            ->forPerson(
                $otherPerson
            )
            ->active()
            ->create([
                'user_id' =>
                $otherBranchStudentAccount
                    ->id,
            ]);

        $unlinkedStudentAccount =
            $this->account(
                SystemRole::Student,
                $center
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $center
            );

        $this->actingAs(
            $manager
        );

        $this->branchScope(
            $center,
            $assignedBranch
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertCanSeeTableRecords([
                $visibleStudentAccount,
            ])
            ->assertCanNotSeeTableRecords([
                $otherBranchStudentAccount,
                $unlinkedStudentAccount,
                $teacher,
                $manager,
            ]);
    }

    public function test_center_owner_requires_center_wide_context(): void
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

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->branchScope(
            $center,
            $branch
        );

        $this->assertFalse(
            UserAccountResource
                ::canViewAny()
        );
    }

    public function test_branch_manager_requires_active_assignment_matching_current_branch(): void
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

        $manager =
            $this->account(
                SystemRole::BranchManager,
                $center
            );

        $this->actingAs(
            $manager
        );

        $this->branchScope(
            $center,
            $branch
        );

        $this->assertFalse(
            UserAccountResource
                ::canViewAny()
        );
    }

    public function test_finance_employee_cannot_access_user_accounts_resource(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $finance =
            $this->account(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->actingAs(
            $finance
        );

        $this->centerWide(
            $center
        );

        $this->assertFalse(
            UserAccountResource
                ::canViewAny()
        );
    }

    public function test_native_user_account_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        $this->assertFalse(
            UserAccountResource
                ::canCreate()
        );

        $this->assertFalse(
            UserAccountResource
                ::canEdit(
                    $teacher
                )
        );

        $this->assertFalse(
            UserAccountResource
                ::canDelete(
                    $teacher
                )
        );

        $this->assertFalse(
            UserAccountResource
                ::canDeleteAny()
        );

        $this->assertTrue(
            UserAccountResource
                ::canView(
                    $teacher
                )
        );
    }

    private function platformOwner(): User
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

    private function account(
        SystemRole $role,
        Center $center,
        ?Person $person = null
    ): User {
        $person ??=
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

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function centerWide(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function branchScope(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
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
}