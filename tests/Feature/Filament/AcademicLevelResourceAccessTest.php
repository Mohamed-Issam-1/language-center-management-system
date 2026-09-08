<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AcademicLevels\AcademicLevelResource;
use App\Models\AcademicLevel;
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

class AcademicLevelResourceAccessTest
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

    public function test_center_owner_sees_only_academic_levels_from_own_center(): void
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
            ->active()
            ->create();

        $languageB =
            Language::factory()
            ->for($centerB)
            ->active()
            ->create();

        $levelA =
            AcademicLevel::factory()
            ->forLanguage(
                $languageA
            )
            ->active()
            ->create();

        $levelB =
            AcademicLevel::factory()
            ->forLanguage(
                $languageB
            )
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

        $this->establishCenterWideContext(
            $centerA
        );

        $ids =
            AcademicLevelResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $levelA->id,
            ],
            $ids
        );

        $this->assertTrue(
            AcademicLevelResource
                ::canViewAny()
        );

        $this->assertTrue(
            AcademicLevelResource
                ::canView(
                    $levelA
                )
        );

        $this->assertFalse(
            AcademicLevelResource
                ::canView(
                    $levelB
                )
        );
    }

    public function test_branch_manager_cannot_manage_academic_levels(): void
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
            AcademicLevelResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            AcademicLevelResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_platform_owner_cannot_manage_center_academic_levels(): void
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
            AcademicLevelResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            AcademicLevelResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_center_owner_requires_center_wide_context(): void
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
            AcademicLevelResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            AcademicLevelResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_academic_level_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $language =
            Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level =
            AcademicLevel::factory()
            ->forLanguage(
                $language
            )
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

        $this->establishCenterWideContext(
            $center
        );

        $this->assertFalse(
            AcademicLevelResource
                ::canCreate()
        );

        $this->assertFalse(
            AcademicLevelResource
                ::canEdit(
                    $level
                )
        );

        $this->assertFalse(
            AcademicLevelResource
                ::canDelete(
                    $level
                )
        );

        $this->assertFalse(
            AcademicLevelResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            AcademicLevelResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                AcademicLevelResource
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
