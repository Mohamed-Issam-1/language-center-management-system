<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
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

class ClassroomResourceAccessTest
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

    public function test_center_owner_sees_all_classrooms_in_own_center_only(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA1 =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchA2 =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $classroomA1 =
            Classroom::factory()
            ->forBranch($branchA1)
            ->create();

        $classroomA2 =
            Classroom::factory()
            ->forBranch($branchA2)
            ->create();

        $classroomB =
            Classroom::factory()
            ->forBranch($branchB)
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
            ClassroomResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                $classroomA1->id,
                $classroomA2->id,
            ],
            $ids
        );

        $this->assertTrue(
            ClassroomResource
                ::canViewAny()
        );

        $this->assertTrue(
            ClassroomResource
                ::canView(
                    $classroomA1
                )
        );

        $this->assertFalse(
            ClassroomResource
                ::canView(
                    $classroomB
                )
        );
    }

    public function test_branch_manager_sees_only_classrooms_in_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroomA =
            Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $classroomB =
            Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branchA->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this->actingAs(
            $manager
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $ids =
            ClassroomResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $classroomA->id,
            ],
            $ids
        );

        $this->assertTrue(
            ClassroomResource
                ::canView(
                    $classroomA
                )
        );

        $this->assertFalse(
            ClassroomResource
                ::canView(
                    $classroomB
                )
        );
    }

    public function test_branch_manager_with_mismatched_context_fails_closed(): void
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

        $wrongBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $assignedBranch
                    ->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this->actingAs(
            $manager
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $wrongBranch
            );

        $this->assertFalse(
            ClassroomResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            ClassroomResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_other_roles_cannot_manage_classroom_resource(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        foreach (
            [
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

            $this->establishCenterWideContext(
                $center
            );

            $this->assertFalse(
                ClassroomResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                ClassroomResource
                    ::getEloquentQuery()
                    ->count()
            );
        }
    }

    public function test_platform_owner_cannot_manage_center_classrooms(): void
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
            ClassroomResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            ClassroomResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_classroom_crud_is_disabled(): void
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

        $classroom =
            Classroom::factory()
            ->forBranch($branch)
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
            ClassroomResource
                ::canCreate()
        );

        $this->assertFalse(
            ClassroomResource
                ::canEdit(
                    $classroom
                )
        );

        $this->assertFalse(
            ClassroomResource
                ::canDelete(
                    $classroom
                )
        );

        $this->assertFalse(
            ClassroomResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            ClassroomResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                ClassroomResource
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
