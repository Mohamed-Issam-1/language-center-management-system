<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Languages\LanguageResource;
use App\Models\Center;
use App\Models\Language;
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

class LanguageResourceAccessTest
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

    public function test_center_owner_sees_only_languages_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $languageA =
            Language::factory()
            ->for($centerA)
            ->create();

        $languageB =
            Language::factory()
            ->for($centerB)
            ->create();

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterWideContext(
            $centerA
        );

        $ids =
            LanguageResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $languageA->id,
            ],
            $ids
        );

        $this->assertTrue(
            LanguageResource
                ::canViewAny()
        );

        $this->assertTrue(
            LanguageResource
                ::canView(
                    $languageA
                )
        );

        $this->assertFalse(
            LanguageResource
                ::canView(
                    $languageB
                )
        );
    }

    public function test_branch_manager_cannot_manage_academic_languages(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $this->actingAs(
            $manager
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->assertFalse(
            LanguageResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            LanguageResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_platform_owner_cannot_manage_center_academic_languages(): void
    {
        $platformOwner =
            User::factory()
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

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            LanguageResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            LanguageResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
    {
        $center =
            Center::factory()
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
            ->clear();

        $this->assertFalse(
            LanguageResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            LanguageResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_language_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $language =
            Language::factory()
            ->for($center)
            ->create();

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->assertFalse(
            LanguageResource
                ::canCreate()
        );

        $this->assertFalse(
            LanguageResource
                ::canEdit(
                    $language
                )
        );

        $this->assertFalse(
            LanguageResource
                ::canDelete(
                    $language
                )
        );

        $this->assertFalse(
            LanguageResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            LanguageResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                LanguageResource
                    ::getPages()
            )
        );
    }

    private function establishCenterWideContext(
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
