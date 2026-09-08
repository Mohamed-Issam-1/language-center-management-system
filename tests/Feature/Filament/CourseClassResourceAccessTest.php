<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CourseClasses\CourseClassResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
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

class CourseClassResourceAccessTest
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

    public function test_center_owner_sees_course_classes_from_own_center_only(): void
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

        $classA1 =
            CourseClass::factory()
            ->forBranch(
                $branchA1
            )
            ->create();

        $classA2 =
            CourseClass::factory()
            ->forBranch(
                $branchA2
            )
            ->create();

        $classB =
            CourseClass::factory()
            ->forBranch(
                $branchB
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
            CourseClassResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                $classA1->id,
                $classA2->id,
            ],
            $ids
        );

        $this->assertTrue(
            CourseClassResource
                ::canViewAny()
        );

        $this->assertTrue(
            CourseClassResource
                ::canView(
                    $classA1
                )
        );

        $this->assertFalse(
            CourseClassResource
                ::canView(
                    $classB
                )
        );
    }

    public function test_branch_manager_sees_only_course_classes_in_assigned_branch(): void
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

        $classA =
            CourseClass::factory()
            ->forBranch(
                $branchA
            )
            ->create();

        $classB =
            CourseClass::factory()
            ->forBranch(
                $branchB
            )
            ->create();

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branchA
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $ids =
            CourseClassResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $classA->id,
            ],
            $ids
        );

        $this->assertTrue(
            CourseClassResource
                ::canView(
                    $classA
                )
        );

        $this->assertFalse(
            CourseClassResource
                ::canView(
                    $classB
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed(): void
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
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $assignment =
            $this->assignManager(
                $manager,
                $branch
            );

        $assignment->update([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $this->assertFalse(
            CourseClassResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseClassResource
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
            CourseClassResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseClassResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_other_roles_cannot_manage_course_class_resource(): void
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
                CourseClassResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                CourseClassResource
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
            CourseClassResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            CourseClassResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_course_class_crud_is_disabled(): void
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

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
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
            CourseClassResource
                ::canCreate()
        );

        $this->assertFalse(
            CourseClassResource
                ::canEdit(
                    $courseClass
                )
        );

        $this->assertFalse(
            CourseClassResource
                ::canDelete(
                    $courseClass
                )
        );

        $this->assertFalse(
            CourseClassResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            CourseClassResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                CourseClassResource
                    ::getPages()
            )
        );
    }

    private function assignManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment
            ::query()
            ->create([
                'center_id' =>
                $branch
                    ->center_id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
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

    private function establishBranchContext(
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
