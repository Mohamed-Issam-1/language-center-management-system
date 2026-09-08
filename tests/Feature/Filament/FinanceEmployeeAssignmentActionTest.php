<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\FinanceEmployeeAssignment;
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

class FinanceEmployeeAssignmentActionTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_assign_finance_employee_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $employee =
            $this->financeEmployeeAccount(
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
                    'assignFinanceEmployee'
                )->table(
                    $branch
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'endFinanceEmployeeAssignment'
                )->table(
                    $branch
                )
            )
            ->callAction(
                TestAction::make(
                    'assignFinanceEmployee'
                )->table(
                    $branch
                ),
                [
                    'finance_employee_user_id' =>
                    $employee->id,
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'finance_employee_assignments',
            [
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'user_id' =>
                $employee->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]
        );
    }

    public function test_branch_can_have_multiple_finance_employees_through_filament_actions(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $employeeA =
            $this->financeEmployeeAccount(
                $center
            );

        $employeeB =
            $this->financeEmployeeAccount(
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
            ->callAction(
                TestAction::make(
                    'assignFinanceEmployee'
                )->table(
                    $branch
                ),
                [
                    'finance_employee_user_id' =>
                    $employeeA->id,
                ]
            )
            ->assertHasNoActionErrors();

        Livewire::test(
            ListBranches::class
        )
            ->callAction(
                TestAction::make(
                    'assignFinanceEmployee'
                )->table(
                    $branch
                ),
                [
                    'finance_employee_user_id' =>
                    $employeeB->id,
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertSame(
            2,
            FinanceEmployeeAssignment
                ::withoutGlobalScopes()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->active()
                ->count()
        );
    }

    public function test_center_owner_can_end_one_finance_assignment_without_ending_others(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $employeeA =
            $this->financeEmployeeAccount(
                $center
            );

        $employeeB =
            $this->financeEmployeeAccount(
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

        $service =
            app(
                StaffBranchAssignmentService::class
            );

        $assignmentA =
            $service
            ->assignFinanceEmployee(
                $owner,
                $employeeA,
                $branch
            );

        $assignmentB =
            $service
            ->assignFinanceEmployee(
                $owner,
                $employeeB,
                $branch
            );

        Livewire::test(
            ListBranches::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'endFinanceEmployeeAssignment'
                )->table(
                    $branch
                )
            )
            ->callAction(
                TestAction::make(
                    'endFinanceEmployeeAssignment'
                )->table(
                    $branch
                ),
                [
                    'finance_employee_user_id' =>
                    $employeeA->id,
                ]
            )
            ->assertHasNoActionErrors();

        $assignmentA->refresh();
        $assignmentB->refresh();

        $this->assertFalse(
            $assignmentA->isActive()
        );

        $this->assertTrue(
            $assignmentB->isActive()
        );

        $this->assertNotNull(
            $assignmentA->ended_at
        );

        $this->assertNull(
            $assignmentB->ended_at
        );

        $this->assertSame(
            1,
            FinanceEmployeeAssignment
                ::withoutGlobalScopes()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->active()
                ->count()
        );
    }

    public function test_finance_employee_assigned_to_another_branch_cannot_be_reassigned_implicitly(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->centerOwner(
                $center
            );

        $employee =
            $this->financeEmployeeAccount(
                $center
            );

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
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

        app(
            StaffBranchAssignmentService::class
        )->assignFinanceEmployee(
            $owner,
            $employee,
            $branchA
        );

        /*
         * The Filament helper intentionally removes employees
         * with an existing active assignment from the selectable
         * candidate set.
         *
         * Calling the domain service directly verifies the same
         * rule remains authoritative even if UI filtering is
         * bypassed.
         */
        try {
            app(
                StaffBranchAssignmentService::class
            )->assignFinanceEmployee(
                $owner,
                $employee,
                $branchB
            );

            $this->fail(
                'Expected implicit Finance Employee reassignment to be rejected.'
            );
        } catch (
            \DomainException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas(
            'finance_employee_assignments',
            [
                'user_id' =>
                $employee->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]
        );

        $this->assertDatabaseMissing(
            'finance_employee_assignments',
            [
                'user_id' =>
                $employee->id,

                'branch_id' =>
                $branchB->id,

                'active_marker' =>
                1,
            ]
        );
    }

    public function test_deactivated_branch_hides_finance_assignment_action(): void
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
                    'assignFinanceEmployee'
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

    private function financeEmployeeAccount(
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
                    SystemRole::FinanceEmployee
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);

        FinanceEmployee::factory()
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
