<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
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

class ClassSessionUpdateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_update_scheduled_session_through_filament_action(): void
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

        $newRoom =
            Classroom::factory()
            ->forBranch(
                $branch
            )
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
            ListClassSessions::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'updateSession'
                )->table(
                    $session
                )
            )
            ->callAction(
                TestAction::make(
                    'updateSession'
                )->table(
                    $session
                ),
                [
                    'topic' =>
                    'Introduction to Grammar',

                    'classroom_id' =>
                    $newRoom->id,

                    'teacher_id' =>
                    $newTeacher->id,
                ]
            );

        $session->refresh();

        $this->assertSame(
            'Introduction to Grammar',
            $session->topic
        );

        $this->assertSame(
            $newRoom->id,
            $session->classroom_id
        );

        $this->assertSame(
            $newTeacher->id,
            $session->teacher_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.updated',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_branch_manager_can_update_session_inside_assigned_branch(): void
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
                    'updateSession'
                )->table(
                    $session
                ),
                [
                    'topic' =>
                    'Updated Topic',

                    'classroom_id' =>
                    $session->classroom_id,

                    'teacher_id' =>
                    $session->teacher_id,
                ]
            );

        $session->refresh();

        $this->assertSame(
            'Updated Topic',
            $session->topic
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.updated',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_branch_manager_cannot_tamper_classroom_from_another_branch(): void
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

        $session =
            $this->createSession(
                $ownBranch
            );

        $originalRoomId =
            $session->classroom_id;

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
            ListClassSessions::class
        )
            ->callAction(
                TestAction::make(
                    'updateSession'
                )->table(
                    $session
                ),
                [
                    'topic' =>
                    'Tampered Topic',

                    'classroom_id' =>
                    $otherRoom->id,

                    'teacher_id' =>
                    $session->teacher_id,
                ]
            );

        $session->refresh();

        $this->assertSame(
            $originalRoomId,
            $session->classroom_id
        );

        $this->assertNull(
            $session->topic
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_session.updated',

                'subject_id' =>
                $session->id,
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

        $branch =
            $this->createWorkingBranch(
                $centerA
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $session =
            $this->createSession(
                $branch
            );

        $originalTeacherId =
            $session->teacher_id;

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
            ListClassSessions::class
        )
            ->callAction(
                TestAction::make(
                    'updateSession'
                )->table(
                    $session
                ),
                [
                    'topic' =>
                    'Tampered Topic',

                    'classroom_id' =>
                    $session->classroom_id,

                    'teacher_id' =>
                    $otherTeacher->id,
                ]
            );

        $session->refresh();

        $this->assertSame(
            $originalTeacherId,
            $session->teacher_id
        );

        $this->assertNull(
            $session->topic
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_session.updated',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_update_action_is_hidden_for_terminal_sessions(): void
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

        $scheduled =
            $this->createSession(
                $branch
            );

        $completed =
            ClassSession::factory()
            ->forSchedule(
                $scheduled->schedule
            )
            ->completed()
            ->create([
                'session_date' =>
                $scheduled
                    ->session_date
                    ->copy()
                    ->addDays(7)
                    ->toDateString(),

                'occurrence_date' =>
                $scheduled
                    ->occurrence_date
                    ->copy()
                    ->addDays(7)
                    ->toDateString(),
            ]);

        $cancelled =
            ClassSession::factory()
            ->forSchedule(
                $scheduled->schedule
            )
            ->cancelled()
            ->create([
                'session_date' =>
                $scheduled
                    ->session_date
                    ->copy()
                    ->addDays(14)
                    ->toDateString(),

                'occurrence_date' =>
                $scheduled
                    ->occurrence_date
                    ->copy()
                    ->addDays(14)
                    ->toDateString(),
            ]);

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
                    'updateSession'
                )->table(
                    $completed
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'updateSession'
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