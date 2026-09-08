<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Branches\StaffBranchAssignmentService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BranchManagerAssignmentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_assign_branch_manager_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $manager =
            $this->branchManagerAccount(
                $center
            );

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListBranches::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'setBranchManager'
                )->table(
                    $branch
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'endBranchManagerAssignment'
                )->table(
                    $branch
                )
            )
            ->callAction(
                TestAction::make(
                    'setBranchManager'
                )->table(
                    $branch
                ),
                [
                    'manager_user_id' =>
                    $manager->id,
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'branch_manager_assignments',
            [
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'user_id' =>
                $manager->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]
        );
    }

    public function test_center_owner_can_replace_branch_manager_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $oldManager =
            $this->branchManagerAccount(
                $center
            );

        $newManager =
            $this->branchManagerAccount(
                $center
            );

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        $oldAssignment =
            app(
                StaffBranchAssignmentService::class
            )
            ->assignBranchManager(
                $owner,
                $oldManager,
                $branch
            );

        Livewire::test(
            ListBranches::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'setBranchManager'
                )->table(
                    $branch
                )
            )
            ->callAction(
                TestAction::make(
                    'setBranchManager'
                )->table(
                    $branch
                ),
                [
                    'manager_user_id' =>
                    $newManager->id,
                ]
            )
            ->assertHasNoActionErrors();

        $oldAssignment->refresh();

        $this->assertFalse(
            $oldAssignment->isActive()
        );

        $this->assertNotNull(
            $oldAssignment->ended_at
        );

        $activeAssignment =
            BranchManagerAssignment
            ::withoutGlobalScopes()
            ->where(
                'branch_id',
                $branch->id
            )
            ->active()
            ->firstOrFail();

        $this->assertSame(
            $newManager->id,
            $activeAssignment->user_id
        );

        $this->assertSame(
            2,
            BranchManagerAssignment
                ::withoutGlobalScopes()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->count()
        );
    }

    public function test_center_owner_can_end_branch_manager_assignment_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $manager =
            $this->branchManagerAccount(
                $center
            );

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        $assignment =
            app(
                StaffBranchAssignmentService::class
            )
            ->assignBranchManager(
                $owner,
                $manager,
                $branch
            );

        Livewire::test(
            ListBranches::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'endBranchManagerAssignment'
                )->table(
                    $branch
                )
            )
            ->callAction(
                TestAction::make(
                    'endBranchManagerAssignment'
                )->table(
                    $branch
                )
            )
            ->assertHasNoActionErrors();

        $assignment->refresh();

        $this->assertFalse(
            $assignment->isActive()
        );

        $this->assertNotNull(
            $assignment->ended_at
        );

        $this->assertSame(
            0,
            BranchManagerAssignment
                ::withoutGlobalScopes()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->active()
                ->count()
        );
    }

    public function test_deactivated_branch_hides_new_manager_assignment_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $branch =
            Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListBranches::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'setBranchManager'
                )->table(
                    $branch
                )
            );
    }

    private function centerOwner(
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
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function branchManagerAccount(
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        $user =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    SystemRole::BranchManager
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);

        BranchManager::factory()
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        return $user;
    }

    private function establishContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
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
