<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSchedules\Pages\ListClassSchedules;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassScheduleUpdateResourcesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_update_schedule_resources_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            $this->createBranch(
                $center
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $newRoom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $newTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                )
            )
            ->callAction(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                ),
                [
                    'classroom_id' =>
                    $newRoom->id,

                    'teacher_id' =>
                    $newTeacher->id,
                ]
            );

        $schedule->refresh();

        $this->assertSame(
            $newRoom->id,
            $schedule->classroom_id
        );

        $this->assertSame(
            $newTeacher->id,
            $schedule->teacher_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.updated',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_branch_manager_can_update_resources_inside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            $this->createBranch(
                $center
            );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $newRoom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $newTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->callAction(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                ),
                [
                    'classroom_id' =>
                    $newRoom->id,

                    'teacher_id' =>
                    $newTeacher->id,
                ]
            );

        $schedule->refresh();

        $this->assertSame(
            $newRoom->id,
            $schedule->classroom_id
        );

        $this->assertSame(
            $newTeacher->id,
            $schedule->teacher_id
        );
    }

    public function test_branch_manager_cannot_tamper_classroom_from_another_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            $this->createBranch(
                $center
            );

        $otherBranch =
            $this->createBranch(
                $center
            );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $ownBranch
            )
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $originalRoomId =
            $schedule->classroom_id;

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->available()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->callAction(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                ),
                [
                    'classroom_id' =>
                    $otherRoom->id,

                    'teacher_id' =>
                    $schedule->teacher_id,
                ]
            );

        $schedule->refresh();

        $this->assertSame(
            $originalRoomId,
            $schedule->classroom_id
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.updated',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_center_owner_cannot_tamper_teacher_from_another_center(): void
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
            $this->createBranch(
                $centerA
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branchA
            )
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $originalTeacherId =
            $schedule->teacher_id;

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->callAction(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                ),
                [
                    'classroom_id' =>
                    $schedule->classroom_id,

                    'teacher_id' =>
                    $otherTeacher->id,
                ]
            );

        $schedule->refresh();

        $this->assertSame(
            $originalTeacherId,
            $schedule->teacher_id
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.updated',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_update_resources_action_is_hidden_for_cancelled_schedule(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            $this->createBranch(
                $center
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->cancelled()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'updateResources'
                )->table(
                    $schedule
                )
            );
    }

    private function createBranch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->for($center)
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