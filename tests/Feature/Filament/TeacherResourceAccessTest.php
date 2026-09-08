<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherResourceAccessTest
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

    public function test_center_owner_sees_teachers_from_own_center_only(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $teacherA =
            $this->teacher(
                $centerA
            );

        $teacherB =
            $this->teacher(
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

        $this->centerWide(
            $centerA
        );

        $ids =
            TeacherResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $teacherA->id,
            ],
            $ids
        );

        $this->assertTrue(
            TeacherResource
                ::canViewAny()
        );

        $this->assertTrue(
            TeacherResource
                ::canView(
                    $teacherA
                )
        );

        $this->assertFalse(
            TeacherResource
                ::canView(
                    $teacherB
                )
        );
    }

    public function test_teacher_without_user_account_remains_visible(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                null,

                'status' =>
                StaffStatus::Active,

                'deactivated_at' =>
                null,
            ]);

        $owner =
            $this->centerUser(
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
            TeacherResource
                ::getEloquentQuery()
                ->whereKey(
                    $teacher->id
                )
                ->exists()
        );
    }

    public function test_center_owner_requires_center_wide_context(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $this->teacher(
            $center
        );

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
            TeacherResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            TeacherResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_other_roles_cannot_access_teacher_resource(): void
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
                $this->centerUser(
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
                TeacherResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                TeacherResource
                    ::getEloquentQuery()
                    ->count()
            );
        }

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
            TeacherResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            TeacherResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_teacher_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $teacher =
            $this->teacher(
                $center
            );

        $owner =
            $this->centerUser(
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
            TeacherResource
                ::canCreate()
        );

        $this->assertFalse(
            TeacherResource
                ::canEdit(
                    $teacher
                )
        );

        $this->assertFalse(
            TeacherResource
                ::canDelete(
                    $teacher
                )
        );

        $this->assertFalse(
            TeacherResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            TeacherResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                TeacherResource
                    ::getPages()
            )
        );
    }

    private function teacher(
        Center $center
    ): Teacher {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->centerUser(
                SystemRole::Teacher,
                $center,
                $person
            );

        return Teacher::factory()
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

    private function centerUser(
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