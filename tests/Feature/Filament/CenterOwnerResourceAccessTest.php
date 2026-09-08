<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CenterOwners\CenterOwnerResource;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CenterOwnerResourceAccessTest
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

    public function test_platform_owner_sees_only_center_owner_accounts_across_centers(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $ownerA =
            $this->centerAccount(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownerB =
            $this->centerAccount(
                SystemRole::CenterOwner,
                $centerB
            );

        $teacher =
            $this->centerAccount(
                SystemRole::Teacher,
                $centerA
            );

        $platformOwner =
            $this->platformOwner();

        $this->actingAs(
            $platformOwner
        );

        $this->establishPlatformScope();

        $visibleIds =
            CenterOwnerResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                $ownerA->id,
                $ownerB->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            CenterOwnerResource
                ::canViewAny()
        );

        $this->assertTrue(
            CenterOwnerResource
                ::canView(
                    $ownerA
                )
        );

        $this->assertFalse(
            CenterOwnerResource
                ::canView(
                    $teacher
                )
        );
    }

    public function test_center_owner_cannot_access_platform_center_owner_resource(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerAccount(
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

        $this->assertFalse(
            CenterOwnerResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CenterOwnerResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_resource_fails_closed_without_platform_tenant_scope(): void
    {
        $platformOwner =
            $this->platformOwner();

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->clear();

        $this->assertFalse(
            CenterOwnerResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CenterOwnerResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_center_owner_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerAccount(
                SystemRole::CenterOwner,
                $center
            );

        $platformOwner =
            $this->platformOwner();

        $this->actingAs(
            $platformOwner
        );

        $this->establishPlatformScope();

        $this->assertFalse(
            CenterOwnerResource
                ::canCreate()
        );

        $this->assertFalse(
            CenterOwnerResource
                ::canEdit(
                    $owner
                )
        );

        $this->assertFalse(
            CenterOwnerResource
                ::canDelete(
                    $owner
                )
        );

        $this->assertFalse(
            CenterOwnerResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            CenterOwnerResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                CenterOwnerResource
                    ::getPages()
            )
        );
    }

    private function establishPlatformScope(): void
    {
        app(TenantContext::class)
            ->establishPlatformScope();
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

    private function centerAccount(
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