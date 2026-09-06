<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Branches\StaffBranchAssignmentService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\AuditRecord;
use App\Services\Audit\AuditRecorder;
use LogicException;

class StaffBranchAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_center_owner_can_assign_branch_manager(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $assignment = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $this->assertTrue(
            $assignment->isActive()
        );

        $this->assertSame(
            $manager->id,
            $assignment->user_id
        );

        $this->assertSame(
            $branch->id,
            $assignment->branch_id
        );
    }

    public function test_branch_manager_with_active_assignment_cannot_be_assigned_to_second_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $oldAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branchA
            );

        try {
            $this->service()
                ->assignBranchManager(
                    $actor,
                    $manager,
                    $branchB
                );

            $this->fail(
                'Expected second active Branch Manager assignment to be rejected.'
            );
        } catch (DomainException) {
            // Expected.
        }

        $oldAssignment->refresh();

        $this->assertTrue(
            $oldAssignment->isActive()
        );

        $this->assertNull(
            $oldAssignment->ended_at
        );

        $this->assertSame(
            1,
            BranchManagerAssignment::query()
                ->where(
                    'user_id',
                    $manager->id
                )
                ->count()
        );

        $this->assertDatabaseMissing(
            'branch_manager_assignments',
            [
                'user_id' => $manager->id,
                'branch_id' => $branchB->id,
                'active_marker' => 1,
            ]
        );
    }

    public function test_branch_manager_cannot_be_assigned_to_branch_with_another_active_manager(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $managerA = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $managerB = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $this->service()
            ->assignBranchManager(
                $actor,
                $managerA,
                $branch
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->assignBranchManager(
                $actor,
                $managerB,
                $branch
            );
    }

    public function test_center_owner_can_replace_branch_manager_and_preserve_history(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $oldManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $newManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $oldAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $oldManager,
                $branch
            );

        $newAssignment = $this->service()
            ->replaceBranchManager(
                $actor,
                $branch,
                $newManager
            );

        $oldAssignment->refresh();

        $this->assertFalse(
            $oldAssignment->isActive()
        );

        $this->assertNotNull(
            $oldAssignment->ended_at
        );

        $this->assertTrue(
            $newAssignment->isActive()
        );

        $this->assertSame(
            $newManager->id,
            $newAssignment->user_id
        );

        $this->assertSame(
            2,
            BranchManagerAssignment::query()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->count()
        );
    }

    public function test_wrong_role_cannot_receive_branch_manager_assignment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $teacher = $this->createRoleAccount(
            SystemRole::Teacher,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->assignBranchManager(
                $actor,
                $teacher,
                $branch
            );
    }

    public function test_cross_center_staff_assignment_is_rejected(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $actorA = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $centerA
        );

        $managerA = $this->createRoleAccount(
            SystemRole::BranchManager,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $this->establishCenterContext($centerA);

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->assignBranchManager(
                $actorA,
                $managerA,
                $branchB
            );
    }

    public function test_deactivated_branch_rejects_new_staff_assignment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $this->establishCenterContext($center);

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );
    }

    public function test_deactivated_account_cannot_receive_new_staff_assignment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center,
            AccountStatus::Deactivated
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );
    }

    public function test_multiple_finance_employees_can_share_the_same_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $financeA = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $financeB = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $this->service()
            ->assignFinanceEmployee(
                $actor,
                $financeA,
                $branch
            );

        $this->service()
            ->assignFinanceEmployee(
                $actor,
                $financeB,
                $branch
            );

        $this->assertSame(
            2,
            FinanceEmployeeAssignment::query()
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->active()
                ->count()
        );
    }

    public function test_finance_employee_with_active_assignment_cannot_be_assigned_to_second_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $oldAssignment = $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branchA
            );

        try {
            $this->service()
                ->assignFinanceEmployee(
                    $actor,
                    $finance,
                    $branchB
                );

            $this->fail(
                'Expected second active Finance Employee assignment to be rejected.'
            );
        } catch (DomainException) {
            // Expected.
        }

        $oldAssignment->refresh();

        $this->assertTrue(
            $oldAssignment->isActive()
        );

        $this->assertNull(
            $oldAssignment->ended_at
        );

        $this->assertSame(
            1,
            FinanceEmployeeAssignment::query()
                ->where(
                    'user_id',
                    $finance->id
                )
                ->count()
        );
    }

    public function test_non_center_owner_cannot_manage_staff_assignments(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branch
            );
    }

    public function test_ending_staff_assignments_preserves_historical_rows(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $managerAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $financeAssignment = $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branch
            );

        $endedManager = $this->service()
            ->endBranchManagerAssignment(
                $actor,
                $manager
            );

        $endedFinance = $this->service()
            ->endFinanceEmployeeAssignment(
                $actor,
                $finance
            );

        $this->assertNotNull(
            $endedManager
        );

        $this->assertNotNull(
            $endedFinance
        );

        $this->assertFalse(
            $endedManager->isActive()
        );

        $this->assertFalse(
            $endedFinance->isActive()
        );

        $this->assertDatabaseHas(
            'branch_manager_assignments',
            [
                'id' => $managerAssignment->id,
                'active_marker' => null,
            ]
        );

        $this->assertDatabaseHas(
            'finance_employee_assignments',
            [
                'id' => $financeAssignment->id,
                'active_marker' => null,
            ]
        );
    }

    public function test_repeating_same_branch_manager_assignment_is_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $first = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $second = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            1,
            BranchManagerAssignment::query()
                ->where(
                    'user_id',
                    $manager->id
                )
                ->count()
        );
    }

    public function test_repeating_same_finance_employee_assignment_is_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $first = $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branch
            );

        $second = $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branch
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            1,
            FinanceEmployeeAssignment::query()
                ->where(
                    'user_id',
                    $finance->id
                )
                ->count()
        );
    }

    public function test_replace_branch_manager_does_not_take_manager_from_another_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $oldManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $otherManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext($center);

        $branchAAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $oldManager,
                $branchA
            );

        $branchBAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $otherManager,
                $branchB
            );

        try {
            $this->service()
                ->replaceBranchManager(
                    $actor,
                    $branchA,
                    $otherManager
                );

            $this->fail(
                'Expected replacement with an already assigned Manager to be rejected.'
            );
        } catch (DomainException) {
            // Expected.
        }

        $branchAAssignment->refresh();
        $branchBAssignment->refresh();

        $this->assertTrue(
            $branchAAssignment->isActive()
        );

        $this->assertTrue(
            $branchBAssignment->isActive()
        );

        $this->assertSame(
            $oldManager->id,
            $branchAAssignment->user_id
        );

        $this->assertSame(
            $otherManager->id,
            $branchBAssignment->user_id
        );

        $this->assertSame(
            2,
            BranchManagerAssignment::query()
                ->active()
                ->count()
        );
    }

    public function test_assigning_branch_manager_creates_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $assignment = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'branch_manager.assigned'
            )
            ->firstOrFail();

        $this->assertSame(
            $actor->id,
            $record->actor_user_id
        );

        $this->assertSame(
            'branch_manager_assignments',
            $record->subject_type
        );

        $this->assertSame(
            $assignment->id,
            $record->subject_id
        );

        $this->assertSame(
            $manager->id,
            $record->after_values['user_id']
        );

        $this->assertSame(
            $branch->id,
            $record->after_values['branch_id']
        );

        $this->assertSame(
            1,
            $record->after_values['active_marker']
        );
    }

    public function test_replacing_branch_manager_creates_single_replacement_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $oldManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $newManager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $oldAssignment = $this->service()
            ->assignBranchManager(
                $actor,
                $oldManager,
                $branch
            );

        $newAssignment = $this->service()
            ->replaceBranchManager(
                $actor,
                $branch,
                $newManager
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'branch_manager.replaced'
            )
            ->firstOrFail();

        $this->assertSame(
            $newAssignment->id,
            $record->subject_id
        );

        $this->assertSame(
            $oldAssignment->id,
            $record->before_values['assignment_id']
        );

        $this->assertSame(
            $oldManager->id,
            $record->before_values['user_id']
        );

        $this->assertSame(
            $newManager->id,
            $record->after_values['user_id']
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'branch_manager.replaced'
                )
                ->count()
        );
    }

    public function test_ending_branch_manager_assignment_creates_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $assignment = $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $this->service()
            ->endBranchManagerAssignment(
                $actor,
                $manager
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'branch_manager.assignment_ended'
            )
            ->firstOrFail();

        $this->assertSame(
            $assignment->id,
            $record->subject_id
        );

        $this->assertSame(
            1,
            $record->before_values['active_marker']
        );

        $this->assertNull(
            $record->after_values['active_marker']
        );

        $this->assertNotNull(
            $record->after_values['ended_at']
        );
    }

    public function test_finance_employee_assignment_and_end_are_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $finance = $this->createRoleAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $assignment = $this->service()
            ->assignFinanceEmployee(
                $actor,
                $finance,
                $branch
            );

        $this->service()
            ->endFinanceEmployeeAssignment(
                $actor,
                $finance
            );

        $assigned = AuditRecord::query()
            ->where(
                'action_type',
                'finance_employee.assigned'
            )
            ->firstOrFail();

        $ended = AuditRecord::query()
            ->where(
                'action_type',
                'finance_employee.assignment_ended'
            )
            ->firstOrFail();

        $this->assertSame(
            $assignment->id,
            $assigned->subject_id
        );

        $this->assertSame(
            $assignment->id,
            $ended->subject_id
        );

        $this->assertSame(
            $finance->id,
            $assigned->after_values['user_id']
        );

        $this->assertNull(
            $ended->after_values['active_marker']
        );
    }

    public function test_idempotent_branch_manager_assignment_does_not_duplicate_audit_history(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $this->service()
            ->assignBranchManager(
                $actor,
                $manager,
                $branch
            );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'branch_manager.assigned'
                )
                ->count()
        );
    }

    public function test_assignment_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createRoleAccount(
            SystemRole::CenterOwner,
            $center
        );

        $manager = $this->createRoleAccount(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $failingAudit =
            \Mockery::mock(
                AuditRecorder::class
            );

        $failingAudit
            ->shouldReceive('record')
            ->once()
            ->andThrow(
                new LogicException(
                    'Simulated audit failure.'
                )
            );

        $this->app->instance(
            AuditRecorder::class,
            $failingAudit
        );

        try {
            $this->service()
                ->assignBranchManager(
                    $actor,
                    $manager,
                    $branch
                );

            $this->fail(
                'Expected audit failure to abort the assignment transaction.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'branch_manager_assignments',
            [
                'user_id' => $manager->id,
                'branch_id' => $branch->id,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    private function service(): StaffBranchAssignmentService
    {
        return app(
            StaffBranchAssignmentService::class
        );
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function createRoleAccount(
        SystemRole $role,
        Center $center,
        AccountStatus $status = AccountStatus::Active
    ): User {
        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role($role)->id,
            'status' => $status,
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
