<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSchedules\ClassScheduleResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\ClassSchedule;
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

class ClassScheduleResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_schedules_from_own_center(): void
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
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownSchedule =
            $this->createSchedule(
                $branchA
            );

        $otherSchedule =
            $this->createSchedule(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            ClassScheduleResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownSchedule->id,
            $ids
        );

        $this->assertNotContains(
            $otherSchedule->id,
            $ids
        );

        $this->assertTrue(
            ClassScheduleResource
                ::canViewAny()
        );

        $this->assertTrue(
            ClassScheduleResource
                ::canView(
                    $ownSchedule
                )
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canView(
                    $otherSchedule
                )
        );

        $this->assertNull(
            ClassScheduleResource
                ::resolveRecordRouteBinding(
                    $otherSchedule
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_only_schedules_from_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $ownSchedule =
            $this->createSchedule(
                $ownBranch
            );

        $otherSchedule =
            $this->createSchedule(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $ids =
            ClassScheduleResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownSchedule->id,
            $ids
        );

        $this->assertNotContains(
            $otherSchedule->id,
            $ids
        );

        $this->assertTrue(
            ClassScheduleResource
                ::canViewAny()
        );

        $this->assertTrue(
            ClassScheduleResource
                ::canView(
                    $ownSchedule
                )
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canView(
                    $otherSchedule
                )
        );

        $this->assertNull(
            ClassScheduleResource
                ::resolveRecordRouteBinding(
                    $otherSchedule
                        ->getKey()
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed_even_with_stale_branch_context(): void
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
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $assignment =
            $this->assignBranchManager(
                $manager,
                $branch
            );

        $schedule =
            $this->createSchedule(
                $branch
            );

        $this->actingAs(
            $manager
        );

        /*
         * Establish the original context first, then terminate
         * the persisted assignment to simulate stale request
         * context.
         */
        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            ClassScheduleResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            ClassScheduleResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canView(
                    $schedule
                )
        );

        $this->assertNull(
            ClassScheduleResource
                ::resolveRecordRouteBinding(
                    $schedule
                        ->getKey()
                )
        );
    }

    public function test_other_roles_cannot_access_class_schedule_resource(): void
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
                ClassScheduleResource
                    ::canViewAny(),
                "Role {$role->value} unexpectedly accessed Class Schedules."
            );
        }
    }

    public function test_native_class_schedule_crud_is_disabled(): void
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
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $schedule =
            $this->createSchedule(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canCreate()
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canEdit(
                    $schedule
                )
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canDelete(
                    $schedule
                )
        );

        $this->assertFalse(
            ClassScheduleResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            ClassScheduleResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                ClassScheduleResource
                    ::getPages()
            )
        );
    }

    private function createSchedule(
        Branch $branch
    ): ClassSchedule {
        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        return ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();
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

    private function establishBranchManagerContext(
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

    private function assignBranchManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

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

    private function createCenterUser(
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