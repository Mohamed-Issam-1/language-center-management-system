<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
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
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassSessionCompleteActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_complete_scheduled_session_through_filament_action(): void
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
                    'completeSession'
                )->table(
                    $session
                )
            )
            ->callAction(
                TestAction::make(
                    'completeSession'
                )->table(
                    $session
                )
            );

        $session->refresh();

        $this->assertSame(
            ClassSessionStatus::Completed,
            $session->session_status
        );

        $this->assertNull(
            $session->cancellation_reason
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.completed',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_branch_manager_can_complete_session_inside_assigned_branch(): void
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
                    'completeSession'
                )->table(
                    $session
                )
            );

        $session->refresh();

        $this->assertSame(
            ClassSessionStatus::Completed,
            $session->session_status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.completed',

                'subject_id' =>
                $session->id,
            ]
        );
    }

    public function test_complete_action_is_hidden_for_terminal_sessions(): void
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

        $completed =
            $this->createSession(
                $branch
            );

        $completed->forceFill([
            'session_status' =>
            ClassSessionStatus::Completed,

            'cancellation_reason' =>
            null,
        ])->save();

        $cancelled =
            $this->createSession(
                $branch
            );

        $cancelled->forceFill([
            'session_status' =>
            ClassSessionStatus::Cancelled,

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
                    'completeSession'
                )->table(
                    $completed
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'completeSession'
                )->table(
                    $cancelled
                )
            );
    }

    public function test_branch_manager_cannot_complete_session_outside_assigned_branch(): void
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

        $session =
            $this->createSession(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $this->assertFalse(
            ClassSessionResource::canView(
                $session
            )
        );

        $this->assertNull(
            ClassSessionResource
                ::resolveRecordRouteBinding(
                    $session->getKey()
                )
        );

        $session->refresh();

        $this->assertSame(
            ClassSessionStatus::Scheduled,
            $session->session_status
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'class_session.completed',

                'subject_id' =>
                $session->id,
            ]
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
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        return ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
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