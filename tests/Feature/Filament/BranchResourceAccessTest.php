<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_branches_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $visibleIds =
            BranchResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $branchA->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            BranchResource
                ::canViewAny()
        );

        $this->assertTrue(
            BranchResource
                ::canView(
                    $branchA
                )
        );

        $this->assertFalse(
            BranchResource
                ::canView(
                    $branchB
                )
        );

        $this->assertNull(
            BranchResource
                ::resolveRecordRouteBinding(
                    $branchB->id
                )
        );
    }

    public function test_branch_manager_and_finance_employee_cannot_manage_branches(): void
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

        foreach (
            [
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
            ] as $role
        ) {
            $actor =
                $this->centerUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $actor
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            app(BranchContext::class)
                ->establishBranchScope(
                    $branch
                );

            $this->assertFalse(
                BranchResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                BranchResource
                    ::getEloquentQuery()
                    ->count()
            );
        }
    }

    public function test_platform_owner_cannot_manage_center_branches(): void
    {
        $actor =
            User::factory()
            ->create([
                'center_id' => null,

                'person_id' => null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);

        $this->actingAs(
            $actor
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            BranchResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            BranchResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_center_owner_requires_matching_center_wide_context(): void
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
            $this->centerUser(
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
            ->establishBranchScope(
                $branch
            );

        $this->assertFalse(
            BranchResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            BranchResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_branch_crud_is_disabled(): void
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
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            BranchResource
                ::canCreate()
        );

        $this->assertFalse(
            BranchResource
                ::canEdit(
                    $branch
                )
        );

        $this->assertFalse(
            BranchResource
                ::canDelete(
                    $branch
                )
        );

        $this->assertFalse(
            BranchResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            BranchResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                BranchResource
                    ::getPages()
            )
        );
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function centerUser(
        SystemRole $role,
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
