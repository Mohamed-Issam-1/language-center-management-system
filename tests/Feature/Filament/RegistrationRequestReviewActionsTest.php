<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RegistrationRequests\Pages\ListRegistrationRequests;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationRequestReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_select_role_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->callAction(
                TestAction::make(
                    'selectRole'
                )->table(
                    $request
                ),
                [
                    'role' =>
                    SystemRole::Student
                        ->value,
                ]
            );

        $request->refresh();

        $this->assertSame(
            $this->role(
                SystemRole::Student
            )->id,
            $request->selected_role_id
        );

        $this->assertNull(
            $request->selected_branch_id
        );

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.role_selected',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_center_owner_can_select_active_branch_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->callAction(
                TestAction::make(
                    'selectBranch'
                )->table(
                    $request
                ),
                [
                    'branch_id' =>
                    $branch->id,
                ]
            );

        $request->refresh();

        $this->assertSame(
            $branch->id,
            $request->selected_branch_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.branch_selected',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_center_owner_can_reject_unclassified_request_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->callAction(
                TestAction::make(
                    'reject'
                )->table(
                    $request
                ),
                [
                    'reason' =>
                    'Applicant identity could not be verified.',
                ]
            );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Rejected,
            $request->status
        );

        $this->assertSame(
            $owner->id,
            $request->reviewed_by_user_id
        );

        $this->assertSame(
            'Applicant identity could not be verified.',
            $request->rejection_reason
        );

        $this->assertNull(
            $request->pending_marker
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.rejected',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_branch_manager_can_reject_student_request_assigned_to_own_branch(): void
    {
        $center =
            Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
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

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,

                'selected_branch_id' =>
                $branch->id,
            ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->callAction(
                TestAction::make(
                    'reject'
                )->table(
                    $request
                ),
                [
                    'reason' =>
                    'Student information could not be verified.',
                ]
            );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Rejected,
            $request->status
        );

        $this->assertSame(
            $manager->id,
            $request->reviewed_by_user_id
        );
    }

    public function test_branch_manager_cannot_use_role_or_branch_selection_actions(): void
    {
        $center =
            Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
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

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,

                'selected_branch_id' =>
                $branch->id,
            ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'selectRole'
                )->table(
                    $request
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'selectBranch'
                )->table(
                    $request
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reject'
                )->table(
                    $request
                )
            );
    }

    public function test_teacher_request_does_not_show_branch_selection_action(): void
    {
        $center =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'selectRole'
                )->table(
                    $request
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'selectBranch'
                )->table(
                    $request
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reject'
                )->table(
                    $request
                )
            );
    }

    public function test_terminal_registration_request_hides_review_actions(): void
    {
        $center =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'status' =>
                RegistrationRequestStatus::Approved,

                'pending_marker' =>
                null,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'selectRole'
                )->table(
                    $request
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'selectBranch'
                )->table(
                    $request
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reject'
                )->table(
                    $request
                )
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
