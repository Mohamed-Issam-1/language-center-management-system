<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AttendanceStatuses\AttendanceStatusResource;
use App\Models\AttendanceStatus;
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

class AttendanceStatusResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_attendance_statuses_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $centerA
            )
            ->create();

        $otherStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $centerB
            )
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            AttendanceStatusResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownStatus->id,
            $ids
        );

        $this->assertNotContains(
            $otherStatus->id,
            $ids
        );

        $this->assertTrue(
            AttendanceStatusResource
                ::canViewAny()
        );

        $this->assertTrue(
            AttendanceStatusResource
                ::canView(
                    $ownStatus
                )
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canView(
                    $otherStatus
                )
        );

        $this->assertNull(
            AttendanceStatusResource
                ::resolveRecordRouteBinding(
                    $otherStatus
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_cannot_access_attendance_status_resource(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->actingAs(
            $manager
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $this->assertFalse(
            AttendanceStatusResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            AttendanceStatusResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_other_roles_cannot_access_attendance_status_resource(): void
    {
        $roles = [
            SystemRole::PlatformOwner,
            SystemRole::FinanceEmployee,
            SystemRole::Teacher,
            SystemRole::Student,
        ];

        foreach (
            $roles as $role
        ) {
            $center =
                Center::factory()
                ->active()
                ->create();

            $user =
                $this->createCenterUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $user
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            app(BranchContext::class)
                ->establishCenterWideScope();

            $this->assertFalse(
                AttendanceStatusResource
                    ::canViewAny(),
                "Role {$role->value} unexpectedly accessed Attendance Statuses."
            );
        }
    }

    public function test_center_owner_requires_matching_center_and_center_wide_branch_context(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $owner
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $centerB
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $this->assertFalse(
            AttendanceStatusResource
                ::canViewAny()
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        /*
         * No Center-wide Branch context should fail closed.
         */
        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            AttendanceStatusResource
                ::canViewAny()
        );
    }

    public function test_native_attendance_status_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canCreate()
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canEdit(
                    $status
                )
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canDelete(
                    $status
                )
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            AttendanceStatusResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                AttendanceStatusResource
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

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for(
                $center
            )
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