<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Courses\CourseResource;
use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Course;
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

class CourseResourceAccessTest
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

    public function test_center_owner_sees_only_courses_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $levelA =
            $this->academicLevel(
                $centerA
            );

        $levelB =
            $this->academicLevel(
                $centerB
            );

        $courseA =
            Course::factory()
            ->forAcademicLevel(
                $levelA
            )
            ->create();

        $courseB =
            Course::factory()
            ->forAcademicLevel(
                $levelB
            )
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
            CourseResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $courseA->id,
            ],
            $ids
        );

        $this->assertTrue(
            CourseResource
                ::canViewAny()
        );

        $this->assertTrue(
            CourseResource
                ::canView(
                    $courseA
                )
        );

        $this->assertFalse(
            CourseResource
                ::canView(
                    $courseB
                )
        );
    }

    public function test_branch_manager_cannot_manage_courses(): void
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
            CourseResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_platform_owner_cannot_manage_center_courses(): void
    {
        $platformOwner =
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
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            CourseResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseResource
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
            CourseResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_creation_level_options_include_only_current_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $levelA =
            $this->academicLevel(
                $centerA
            );

        $levelB =
            $this->academicLevel(
                $centerB
            );

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

        $options =
            CourseResource
            ::academicLevelOptions(
                true
            );

        $this->assertArrayHasKey(
            $levelA->id,
            $options
        );

        $this->assertArrayNotHasKey(
            $levelB->id,
            $options
        );
    }

    public function test_native_course_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $level =
            $this->academicLevel(
                $center
            );

        $course =
            Course::factory()
            ->forAcademicLevel(
                $level
            )
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
            CourseResource
                ::canCreate()
        );

        $this->assertFalse(
            CourseResource
                ::canEdit(
                    $course
                )
        );

        $this->assertFalse(
            CourseResource
                ::canDelete(
                    $course
                )
        );

        $this->assertFalse(
            CourseResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            CourseResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                CourseResource
                    ::getPages()
            )
        );
    }

    private function academicLevel(
        Center $center
    ): AcademicLevel {
        $language =
            Language::factory()
            ->for($center)
            ->active()
            ->create();

        return AcademicLevel
            ::factory()
            ->forLanguage(
                $language
            )
            ->active()
            ->create();
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
