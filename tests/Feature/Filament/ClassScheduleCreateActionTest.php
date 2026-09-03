<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSchedules\Pages\ListClassSchedules;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
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

class ClassScheduleCreateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_schedule_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            $this->createWorkingBranch(
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

        $room =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacher =
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
                    'createSchedule'
                )
            )
            ->callAction(
                TestAction::make(
                    'createSchedule'
                ),
                [
                    'course_class_id' =>
                    $courseClass->id,

                    'classroom_id' =>
                    $room->id,

                    'teacher_id' =>
                    $teacher->id,

                    'day_of_week' =>
                    1,

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',

                    'effective_from' =>
                    $courseClass
                        ->start_date
                        ->toDateString(),

                    'effective_until' =>
                    $courseClass
                        ->end_date
                        ->toDateString(),
                ]
            );

        $schedule =
            \App\Models\ClassSchedule
            ::withoutGlobalScopes()
            ->where(
                'class_id',
                $courseClass->id
            )
            ->where(
                'classroom_id',
                $room->id
            )
            ->where(
                'teacher_id',
                $teacher->id
            )
            ->firstOrFail();

        $this->assertSame(
            $center->id,
            $schedule->center_id
        );

        $this->assertSame(
            ClassScheduleStatus::Active,
            $schedule->status
        );

        $this->assertSame(
            1,
            $schedule->day_of_week
        );

        $this->assertSame(
            '09:00:00',
            $schedule->start_time
        );

        $this->assertSame(
            '10:30:00',
            $schedule->end_time
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.created',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_branch_manager_can_create_schedule_inside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            $this->createWorkingBranch(
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

        $room =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacher =
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
                    'createSchedule'
                ),
                [
                    'course_class_id' =>
                    $courseClass->id,

                    'classroom_id' =>
                    $room->id,

                    'teacher_id' =>
                    $teacher->id,

                    'day_of_week' =>
                    1,

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',

                    'effective_from' =>
                    $courseClass
                        ->start_date
                        ->toDateString(),

                    'effective_until' =>
                    $courseClass
                        ->end_date
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseHas(
            'class_schedules',
            [
                'center_id' =>
                $center->id,

                'class_id' =>
                $courseClass->id,

                'classroom_id' =>
                $room->id,

                'teacher_id' =>
                $teacher->id,

                'status' =>
                ClassScheduleStatus::Active
                    ->value,
            ]
        );
    }

    public function test_branch_manager_cannot_tamper_course_class_outside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            $this->createWorkingBranch(
                $center
            );

        $otherBranch =
            $this->createWorkingBranch(
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

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->create();

        $ownRoom =
            Classroom::factory()
            ->forBranch(
                $ownBranch
            )
            ->active()
            ->available()
            ->create();

        $teacher =
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
            $ownBranch
        );

        Livewire::test(
            ListClassSchedules::class
        )
            ->callAction(
                TestAction::make(
                    'createSchedule'
                ),
                [
                    'course_class_id' =>
                    $otherClass->id,

                    'classroom_id' =>
                    $ownRoom->id,

                    'teacher_id' =>
                    $teacher->id,

                    'day_of_week' =>
                    1,

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',

                    'effective_from' =>
                    $otherClass
                        ->start_date
                        ->toDateString(),

                    'effective_until' =>
                    $otherClass
                        ->end_date
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseMissing(
            'class_schedules',
            [
                'class_id' =>
                $otherClass->id,

                'classroom_id' =>
                $ownRoom->id,
            ]
        );
    }

    public function test_center_owner_cannot_tamper_scheduling_resources_from_another_center(): void
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
            $this->createWorkingBranch(
                $centerA
            );

        $branchB =
            $this->createWorkingBranch(
                $centerB
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branchA)
            ->active()
            ->create();

        $otherRoom =
            Classroom::factory()
            ->forBranch($branchB)
            ->active()
            ->available()
            ->create();

        $teacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $centerA->id,
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
                    'createSchedule'
                ),
                [
                    'course_class_id' =>
                    $courseClass->id,

                    'classroom_id' =>
                    $otherRoom->id,

                    'teacher_id' =>
                    $teacher->id,

                    'day_of_week' =>
                    1,

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',

                    'effective_from' =>
                    $courseClass
                        ->start_date
                        ->toDateString(),

                    'effective_until' =>
                    $courseClass
                        ->end_date
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseMissing(
            'class_schedules',
            [
                'class_id' =>
                $courseClass->id,

                'classroom_id' =>
                $otherRoom->id,
            ]
        );
    }

    private function createWorkingBranch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->for($center)
            ->active()
            ->create([
                'working_hours' => [
                    'monday' => [
                        'opens_at' =>
                        '08:00',

                        'closes_at' =>
                        '18:00',
                    ],
                ],
            ]);
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