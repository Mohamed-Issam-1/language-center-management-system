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
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassScheduleCancelActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_cancel_active_schedule_through_filament_action(): void
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
                    'cancelSchedule'
                )->table(
                    $schedule
                )
            )
            ->callAction(
                TestAction::make(
                    'cancelSchedule'
                )->table(
                    $schedule
                )
            );

        $schedule->refresh();

        $this->assertSame(
            ClassScheduleStatus::Cancelled,
            $schedule->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.cancelled',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_branch_manager_can_cancel_schedule_inside_assigned_branch(): void
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
                    'cancelSchedule'
                )->table(
                    $schedule
                )
            );

        $schedule->refresh();

        $this->assertSame(
            ClassScheduleStatus::Cancelled,
            $schedule->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.cancelled',

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_cancel_action_is_hidden_for_already_cancelled_schedule(): void
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
                    'cancelSchedule'
                )->table(
                    $schedule
                )
            );
    }

    public function test_branch_manager_cannot_cancel_schedule_outside_assigned_branch(): void
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

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $this->assertFalse(
            \App\Filament\Resources\ClassSchedules\ClassScheduleResource
                ::canView(
                    $schedule
                )
        );

        $this->assertNull(
            \App\Filament\Resources\ClassSchedules\ClassScheduleResource
                ::resolveRecordRouteBinding(
                    $schedule->getKey()
                )
        );

        $schedule->refresh();

        $this->assertSame(
            ClassScheduleStatus::Active,
            $schedule->status
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.cancelled',

                'subject_id' =>
                $schedule->id,
            ]
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