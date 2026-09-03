<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
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
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassSessionRescheduleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_reschedule_scheduled_session_through_filament_action(): void
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSessions::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $session
                )
            )
            ->callAction(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $session
                ),
                [
                    'session_date' =>
                    '2026-09-08',

                    'start_time' =>
                    '11:00',

                    'end_time' =>
                    '12:30',
                ]
            );

        $session->refresh();

        $this->assertSame(
            '2026-09-08',
            $session
                ->session_date
                ->toDateString()
        );

        $this->assertSame(
            '2026-09-07',
            $session
                ->occurrence_date
                ->toDateString()
        );

        $this->assertSame(
            '11:00:00',
            $session->start_time
        );

        $this->assertSame(
            '12:30:00',
            $session->end_time
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.rescheduled',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_branch_manager_can_reschedule_session_inside_assigned_branch(): void
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListClassSessions::class
        )
            ->callAction(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $session
                ),
                [
                    'session_date' =>
                    '2026-09-08',

                    'start_time' =>
                    '13:00',

                    'end_time' =>
                    '14:00',
                ]
            );

        $session->refresh();

        $this->assertSame(
            '2026-09-08',
            $session
                ->session_date
                ->toDateString()
        );

        $this->assertSame(
            '13:00:00',
            $session->start_time
        );

        $this->assertSame(
            '14:00:00',
            $session->end_time
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSessions::class
        )
            ->callAction(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $session
                ),
                [
                    'session_date' =>
                    '2026-09-08',

                    'start_time' =>
                    '19:00',

                    'end_time' =>
                    '20:00',
                ]
            );

        $session->refresh();

        $this->assertSame(
            '2026-09-07',
            $session
                ->session_date
                ->toDateString()
        );

        $this->assertSame(
            '09:00:00',
            $session->start_time
        );

        $this->assertSame(
            '10:30:00',
            $session->end_time
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_session.rescheduled',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_reschedule_rejects_date_outside_course_class_range(): void
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSessions::class
        )
            ->callAction(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $session
                ),
                [
                    'session_date' =>
                    '2026-10-06',

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',
                ]
            );

        $session->refresh();

        $this->assertSame(
            '2026-09-07',
            $session
                ->session_date
                ->toDateString()
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_session.rescheduled',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_reschedule_action_is_hidden_for_terminal_sessions(): void
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

        $completed =
            $this->createSession(
                $branch
            );

        $completed->forceFill([
            'session_status' =>
            \App\Support\Enums\ClassSessionStatus::Completed,
        ])->save();

        $cancelled =
            $this->createSession(
                $branch
            );

        $cancelled->forceFill([
            'session_status' =>
            \App\Support\Enums\ClassSessionStatus::Cancelled,

            'cancellation_reason' =>
            'Cancelled for testing.',
        ])->save();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListClassSessions::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $completed
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'rescheduleSession'
                )->table(
                    $cancelled
                )
            );
    }

    private function createSession(
        Branch $branch
    ): ClassSession {
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
                '2026-10-05',
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
                '2026-10-05',
            ]);

        return ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create([
                'session_date' =>
                '2026-09-07',

                'occurrence_date' =>
                '2026-09-07',
            ]);
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