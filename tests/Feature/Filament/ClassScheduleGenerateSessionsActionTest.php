<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSchedules\ClassScheduleResource;
use App\Filament\Resources\ClassSchedules\Pages\ListClassSchedules;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassScheduleGenerateSessionsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_generate_sessions_through_filament_action(): void
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
            ->forBranch(
                $branch
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-07',

                'end_date' =>
                '2026-09-28',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',

                'effective_from' =>
                '2026-09-07',

                'effective_until' =>
                '2026-09-28',
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
                    'generateSessions'
                )->table(
                    $schedule
                )
            )
            ->callAction(
                TestAction::make(
                    'generateSessions'
                )->table(
                    $schedule
                )
            );

        $sessions =
            ClassSession::withoutGlobalScopes()
            ->where(
                'schedule_id',
                $schedule->id
            )
            ->orderBy(
                'occurrence_date'
            )
            ->get();

        $this->assertCount(
            4,
            $sessions
        );

        $this->assertSame(
            [
                '2026-09-07',
                '2026-09-14',
                '2026-09-21',
                '2026-09-28',
            ],
            $sessions
                ->pluck(
                    'occurrence_date'
                )
                ->map(
                    fn(
                        $date
                    ): string =>
                    $date->toDateString()
                )
                ->all()
        );

        foreach (
            $sessions as $session
        ) {
            $this->assertSame(
                $center->id,
                $session->center_id
            );

            $this->assertSame(
                $schedule->id,
                $session->schedule_id
            );

            $this->assertSame(
                $courseClass->id,
                $session->class_id
            );

            $this->assertSame(
                ClassSessionStatus::Scheduled,
                $session->session_status
            );
        }

        $this->assertSame(
            4,
            \App\Models\AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.generated'
                )
                ->whereIn(
                    'subject_id',
                    $sessions->pluck('id')
                )
                ->count()
        );
    }

    public function test_generation_is_idempotent_through_filament_action(): void
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
            ->forBranch(
                $branch
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-07',

                'end_date' =>
                '2026-09-21',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'effective_from' =>
                '2026-09-07',

                'effective_until' =>
                '2026-09-21',
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
                    'generateSessions'
                )->table(
                    $schedule
                )
            );

        $firstCount =
            ClassSession::withoutGlobalScopes()
            ->where(
                'schedule_id',
                $schedule->id
            )
            ->count();

        Livewire::test(
            ListClassSchedules::class
        )
            ->callAction(
                TestAction::make(
                    'generateSessions'
                )->table(
                    $schedule
                )
            );

        $secondCount =
            ClassSession::withoutGlobalScopes()
            ->where(
                'schedule_id',
                $schedule->id
            )
            ->count();

        $this->assertSame(
            3,
            $firstCount
        );

        $this->assertSame(
            $firstCount,
            $secondCount
        );

        $this->assertSame(
            3,
            \App\Models\AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.generated'
                )
                ->count()
        );
    }

    public function test_branch_manager_can_generate_sessions_inside_assigned_branch(): void
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
            ->forBranch(
                $branch
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-07',

                'end_date' =>
                '2026-09-14',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'effective_from' =>
                '2026-09-07',

                'effective_until' =>
                '2026-09-14',
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
                    'generateSessions'
                )->table(
                    $schedule
                )
            );

        $this->assertSame(
            2,
            ClassSession::withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $schedule->id
                )
                ->count()
        );
    }

    public function test_generate_sessions_action_is_hidden_for_cancelled_schedule(): void
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
            ->forBranch(
                $branch
            )
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
                    'generateSessions'
                )->table(
                    $schedule
                )
            );
    }

    public function test_branch_manager_cannot_generate_sessions_outside_assigned_branch(): void
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

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-07',

                'end_date' =>
                '2026-09-14',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'effective_from' =>
                '2026-09-07',

                'effective_until' =>
                '2026-09-14',
            ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $this->assertFalse(
            ClassScheduleResource::canView(
                $schedule
            )
        );

        $this->assertNull(
            ClassScheduleResource
                ::resolveRecordRouteBinding(
                    $schedule->getKey()
                )
        );

        $this->assertSame(
            0,
            ClassSession::withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $schedule->id
                )
                ->count()
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