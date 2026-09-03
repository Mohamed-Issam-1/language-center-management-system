<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSchedules\Pages\ListClassSchedules;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassScheduleRescheduleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_reschedule_active_schedule_through_filament_action(): void
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

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',
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
                    'reschedule'
                )->table(
                    $schedule
                )
            )
            ->callAction(
                TestAction::make(
                    'reschedule'
                )->table(
                    $schedule
                ),
                [
                    'day_of_week' =>
                    2,

                    'start_time' =>
                    '11:00',

                    'end_time' =>
                    '12:30',

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

        $schedule->refresh();

        $this->assertSame(
            2,
            $schedule->day_of_week
        );

        $this->assertSame(
            '11:00:00',
            $schedule->start_time
        );

        $this->assertSame(
            '12:30:00',
            $schedule->end_time
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.rescheduled',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_branch_manager_can_reschedule_schedule_inside_assigned_branch(): void
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

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',
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
                    'reschedule'
                )->table(
                    $schedule
                ),
                [
                    'day_of_week' =>
                    2,

                    'start_time' =>
                    '13:00',

                    'end_time' =>
                    '14:00',

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

        $schedule->refresh();

        $this->assertSame(
            2,
            $schedule->day_of_week
        );

        $this->assertSame(
            '13:00:00',
            $schedule->start_time
        );

        $this->assertSame(
            '14:00:00',
            $schedule->end_time
        );
    }

    public function test_reschedule_rejects_time_outside_branch_working_hours(): void
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

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',
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
            ->callAction(
                TestAction::make(
                    'reschedule'
                )->table(
                    $schedule
                ),
                [
                    'day_of_week' =>
                    2,

                    'start_time' =>
                    '19:00',

                    'end_time' =>
                    '20:00',

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

        $schedule->refresh();

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

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.rescheduled',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_reschedule_action_is_hidden_for_cancelled_schedule(): void
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
                    'reschedule'
                )->table(
                    $schedule
                )
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

                    'tuesday' => [
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