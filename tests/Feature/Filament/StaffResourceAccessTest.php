<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BranchManagers\BranchManagerResource;
use App\Filament\Resources\FinanceEmployees\FinanceEmployeeResource;
use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffResourceAccessTest
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

    public function test_center_owner_sees_only_own_center_staff_records(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $managerA =
            $this->branchManager(
                $centerA
            );

        $this->branchManager(
            $centerB
        );

        $financeA =
            $this->financeEmployee(
                $centerA
            );

        $this->financeEmployee(
            $centerB
        );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $centerA
        );

        $this->assertSame(
            [
                $managerA->id,
            ],
            BranchManagerResource
                ::getEloquentQuery()
                ->pluck('id')
                ->all()
        );

        $this->assertSame(
            [
                $financeA->id,
            ],
            FinanceEmployeeResource
                ::getEloquentQuery()
                ->pluck('id')
                ->all()
        );
    }

    public function test_staff_without_linked_account_remains_visible(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $personA =
            Person::factory()
            ->for($center)
            ->create();

        $manager =
            BranchManager::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $personA->id,

                'user_id' =>
                null,

                'status' =>
                StaffStatus::Active,
            ]);

        $personB =
            Person::factory()
            ->for($center)
            ->create();

        $finance =
            FinanceEmployee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $personB->id,

                'user_id' =>
                null,

                'status' =>
                StaffStatus::Active,
            ]);

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        $this->assertTrue(
            BranchManagerResource
                ::getEloquentQuery()
                ->whereKey(
                    $manager->id
                )
                ->exists()
        );

        $this->assertTrue(
            FinanceEmployeeResource
                ::getEloquentQuery()
                ->whereKey(
                    $finance->id
                )
                ->exists()
        );
    }

    public function test_current_branch_assignments_are_available_to_resources(): void
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
            $this->branchManager(
                $center
            );

        $finance =
            $this->financeEmployee(
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $manager->user_id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $finance->user_id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        $loadedManager =
            BranchManagerResource
            ::getEloquentQuery()
            ->findOrFail(
                $manager->id
            );

        $loadedFinance =
            FinanceEmployeeResource
            ::getEloquentQuery()
            ->findOrFail(
                $finance->id
            );

        $this->assertSame(
            $branch->id,
            $loadedManager
                ->user
                ->activeBranchManagerAssignment
                ->branch_id
        );

        $this->assertSame(
            $branch->id,
            $loadedFinance
                ->user
                ->activeFinanceEmployeeAssignment
                ->branch_id
        );
    }

    public function test_center_owner_requires_center_wide_context(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            BranchManagerResource
                ::canViewAny()
        );

        $this->assertFalse(
            FinanceEmployeeResource
                ::canViewAny()
        );
    }

    public function test_non_center_owner_roles_cannot_access_staff_management_resources(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        foreach (
            [
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $actor =
                $this->user(
                    $role,
                    $center
                );

            $this->actingAs(
                $actor
            );

            $this->centerWide(
                $center
            );

            $this->assertFalse(
                BranchManagerResource
                    ::canViewAny()
            );

            $this->assertFalse(
                FinanceEmployeeResource
                    ::canViewAny()
            );
        }
    }

    public function test_native_staff_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $manager =
            $this->branchManager(
                $center
            );

        $finance =
            $this->financeEmployee(
                $center
            );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        $this->assertFalse(
            BranchManagerResource
                ::canCreate()
        );

        $this->assertFalse(
            BranchManagerResource
                ::canEdit(
                    $manager
                )
        );

        $this->assertFalse(
            BranchManagerResource
                ::canDelete(
                    $manager
                )
        );

        $this->assertFalse(
            FinanceEmployeeResource
                ::canCreate()
        );

        $this->assertFalse(
            FinanceEmployeeResource
                ::canEdit(
                    $finance
                )
        );

        $this->assertFalse(
            FinanceEmployeeResource
                ::canDelete(
                    $finance
                )
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                BranchManagerResource
                    ::getPages()
            )
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                FinanceEmployeeResource
                    ::getPages()
            )
        );
    }

    private function branchManager(
        Center $center
    ): BranchManager {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->user(
                SystemRole::BranchManager,
                $center,
                $person
            );

        return BranchManager::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $account->id,

                'status' =>
                StaffStatus::Active,

                'deactivated_at' =>
                null,
            ]);
    }

    private function financeEmployee(
        Center $center
    ): FinanceEmployee {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->user(
                SystemRole::FinanceEmployee,
                $center,
                $person
            );

        return FinanceEmployee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $account->id,

                'status' =>
                StaffStatus::Active,

                'deactivated_at' =>
                null,
            ]);
    }

    private function user(
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