<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Classrooms\ClassroomManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ClassroomManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_center_owner_can_create_classroom_in_own_active_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $classroom = $this->service()
            ->create(
                $owner,
                $branch,
                [
                    'name' => 'Classroom 101',
                    'code' => 'CR-101',
                    'capacity' => 30,
                    'location' => 'First Floor',
                    'availability_status' =>
                    ClassroomAvailabilityStatus::Available,
                    'status' =>
                    ClassroomStatus::Active,
                ]
            );

        $this->assertSame(
            $center->id,
            $classroom->center_id
        );

        $this->assertSame(
            $branch->id,
            $classroom->branch_id
        );

        $this->assertSame(
            30,
            $classroom->capacity
        );

        $this->assertSame(
            ClassroomStatus::Active,
            $classroom->status
        );
    }

    public function test_classroom_creation_derives_center_and_branch_and_ignores_supplied_scope_ids(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $centerA
        );

        $classroom = $this->service()
            ->create(
                $ownerA,
                $branchA,
                [
                    /*
                     * These must never control persisted scope.
                     */
                    'center_id' => $centerB->id,
                    'branch_id' => $branchB->id,

                    'name' => 'Safe Classroom',
                    'code' => 'SAFE-1',
                    'capacity' => 20,
                    'location' => 'Ground Floor',
                    'availability_status' =>
                    ClassroomAvailabilityStatus::Available,
                    'status' =>
                    ClassroomStatus::Active,
                ]
            );

        $this->assertSame(
            $centerA->id,
            $classroom->center_id
        );

        $this->assertSame(
            $branchA->id,
            $classroom->branch_id
        );

        $this->assertNotSame(
            $branchB->id,
            $classroom->branch_id
        );
    }

    public function test_branch_manager_can_create_classroom_only_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branchA
        );

        $this->establishBranchManagerContext(
            $center,
            $branchA
        );

        $classroom = $this->service()
            ->create(
                $manager,
                $branchA,
                $this->validClassroomAttributes()
            );

        $this->assertSame(
            $branchA->id,
            $classroom->branch_id
        );
    }

    public function test_branch_manager_cannot_create_classroom_in_another_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branchA
        );

        $this->establishBranchManagerContext(
            $center,
            $branchA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                $manager,
                $branchB,
                $this->validClassroomAttributes()
            );
    }

    public function test_classroom_cannot_be_created_in_deactivated_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $this->validClassroomAttributes()
            );
    }

    public function test_classroom_creation_rechecks_current_branch_status_from_database(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        /*
     * Change the persisted Branch without refreshing the
     * already-loaded model. The in-memory instance therefore
     * remains stale and still reports Active.
     */
        Branch::query()
            ->whereKey($branch->id)
            ->update([
                'status' => BranchStatus::Deactivated,
            ]);

        $this->assertSame(
            BranchStatus::Active,
            $branch->status
        );

        try {
            $this->service()
                ->create(
                    $owner,
                    $branch,
                    $this->validClassroomAttributes()
                );

            $this->fail(
                'Creating a classroom in a persistently deactivated branch was not rejected.'
            );
        } catch (DomainException) {
            // Expected.
        }

        $this->assertDatabaseMissing(
            'classrooms',
            [
                'branch_id' => $branch->id,
            ]
        );
    }

    public function test_general_update_cannot_move_classroom_or_bypass_lifecycle_and_availability(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
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

        $classroom = Classroom::factory()
            ->forBranch($branchA)
            ->active()
            ->available()
            ->create([
                'name' => 'Old Name',
                'capacity' => 20,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $classroom,
                [
                    'name' => 'Updated Name',
                    'capacity' => 40,

                    /*
                     * These must be ignored.
                     */
                    'branch_id' => $branchB->id,
                    'center_id' => 999999,
                    'status' =>
                    ClassroomStatus::Deactivated,
                    'availability_status' =>
                    ClassroomAvailabilityStatus::Unavailable,
                ]
            );

        $this->assertSame(
            'Updated Name',
            $updated->name
        );

        $this->assertSame(
            40,
            $updated->capacity
        );

        $this->assertSame(
            $branchA->id,
            $updated->branch_id
        );

        $this->assertSame(
            $center->id,
            $updated->center_id
        );

        $this->assertSame(
            ClassroomStatus::Active,
            $updated->status
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Available,
            $updated->availability_status
        );
    }

    public function test_authorized_user_can_change_classroom_availability_explicitly(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->available()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $classroom = $this->service()
            ->setAvailability(
                $owner,
                $classroom,
                ClassroomAvailabilityStatus::Unavailable
            );

        $this->assertSame(
            ClassroomAvailabilityStatus::Unavailable,
            $classroom->availability_status
        );

        $classroom = $this->service()
            ->setAvailability(
                $owner,
                $classroom,
                ClassroomAvailabilityStatus::Available
            );

        $this->assertSame(
            ClassroomAvailabilityStatus::Available,
            $classroom->availability_status
        );
    }

    public function test_branch_manager_can_activate_and_deactivate_classroom_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->deactivated()
            ->create();

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        $classroom = $this->service()
            ->activate(
                $manager,
                $classroom
            );

        $this->assertSame(
            ClassroomStatus::Active,
            $classroom->status
        );

        $classroom = $this->service()
            ->deactivate(
                $manager,
                $classroom
            );

        $this->assertSame(
            ClassroomStatus::Deactivated,
            $classroom->status
        );

        $this->assertDatabaseHas(
            'classrooms',
            [
                'id' => $classroom->id,
                'status' =>
                ClassroomStatus::Deactivated->value,
            ]
        );
    }

    public function test_service_rejects_cross_center_classroom_management(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $this->establishCenterOwnerContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $ownerA,
                $classroomB,
                [
                    'name' => 'Unauthorized',
                ]
            );
    }

    public function test_branch_manager_service_scope_rejects_inconsistent_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branchA
        );

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        /*
         * Deliberately establish an inconsistent context.
         */
        app(TenantContext::class)
            ->establishCenterScope($center);

        app(BranchContext::class)
            ->establishBranchScope($branchB);

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $manager,
                $classroomA,
                [
                    'name' => 'Blocked',
                ]
            );
    }

    public function test_existing_classroom_can_be_maintained_after_parent_branch_deactivation(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create([
                'name' => 'Historical Classroom',
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $classroom,
                [
                    'name' =>
                    'Historical Classroom Updated',
                ]
            );

        $this->assertSame(
            'Historical Classroom Updated',
            $updated->name
        );

        $updated = $this->service()
            ->deactivate(
                $owner,
                $updated
            );

        $this->assertSame(
            ClassroomStatus::Deactivated,
            $updated->status
        );
    }

    public function test_classroom_creation_creates_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $classroom = $this->service()
            ->create(
                $owner,
                $branch,
                [
                    'name' => 'Audit Classroom',
                    'code' => 'AUD-101',
                    'capacity' => 35,
                    'location' => 'Second Floor',
                    'availability_status' =>
                    ClassroomAvailabilityStatus::Available,
                    'status' =>
                    ClassroomStatus::Active,
                ]
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'classroom.created'
            )
            ->firstOrFail();

        $this->assertSame(
            $owner->id,
            $record->actor_user_id
        );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            $branch->id,
            $record->branch_id
        );

        $this->assertSame(
            'classrooms',
            $record->subject_type
        );

        $this->assertSame(
            $classroom->id,
            $record->subject_id
        );

        $this->assertNull(
            $record->before_values
        );

        $this->assertSame(
            'Audit Classroom',
            $record->after_values['name']
        );

        $this->assertSame(
            35,
            $record->after_values['capacity']
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Available->value,
            $record->after_values['availability_status']
        );

        $this->assertSame(
            ClassroomStatus::Active->value,
            $record->after_values['status']
        );
    }

    public function test_classroom_update_records_before_and_after_values(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create([
                'name' => 'Old Classroom',
                'capacity' => 20,
                'location' => 'Old Location',
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $classroom,
                [
                    'name' => 'Updated Classroom',
                    'capacity' => 45,
                    'location' => 'New Location',
                ]
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'classroom.updated'
            )
            ->firstOrFail();

        $this->assertSame(
            $updated->id,
            $record->subject_id
        );

        $this->assertSame(
            'Old Classroom',
            $record->before_values['name']
        );

        $this->assertSame(
            20,
            $record->before_values['capacity']
        );

        $this->assertSame(
            'Updated Classroom',
            $record->after_values['name']
        );

        $this->assertSame(
            45,
            $record->after_values['capacity']
        );

        $this->assertSame(
            $branch->id,
            $record->after_values['branch_id']
        );

        $this->assertSame(
            ClassroomStatus::Active->value,
            $record->after_values['status']
        );
    }

    public function test_classroom_availability_change_is_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->available()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->service()
            ->setAvailability(
                $owner,
                $classroom,
                ClassroomAvailabilityStatus::Unavailable
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'classroom.availability_changed'
            )
            ->firstOrFail();

        $this->assertSame(
            $classroom->id,
            $record->subject_id
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Available->value,
            $record->before_values['availability_status']
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Unavailable->value,
            $record->after_values['availability_status']
        );
    }

    public function test_classroom_lifecycle_changes_are_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->deactivated()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $service = $this->service();

        $classroom = $service->activate(
            $owner,
            $classroom
        );

        $classroom = $service->deactivate(
            $owner,
            $classroom
        );

        $activated = AuditRecord::query()
            ->where(
                'action_type',
                'classroom.activated'
            )
            ->firstOrFail();

        $deactivated = AuditRecord::query()
            ->where(
                'action_type',
                'classroom.deactivated'
            )
            ->firstOrFail();

        $this->assertSame(
            ClassroomStatus::Deactivated->value,
            $activated->before_values['status']
        );

        $this->assertSame(
            ClassroomStatus::Active->value,
            $activated->after_values['status']
        );

        $this->assertSame(
            ClassroomStatus::Active->value,
            $deactivated->before_values['status']
        );

        $this->assertSame(
            ClassroomStatus::Deactivated->value,
            $deactivated->after_values['status']
        );
    }

    public function test_no_op_classroom_update_does_not_create_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create([
                'name' => 'Unchanged Classroom',
                'capacity' => 25,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->service()
            ->update(
                $owner,
                $classroom,
                [
                    'name' => 'Unchanged Classroom',
                    'capacity' => 25,
                ]
            );

        $this->assertSame(
            0,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'classroom.updated'
                )
                ->count()
        );
    }

    public function test_repeated_classroom_state_requests_do_not_duplicate_audit_history(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $service = $this->service();

        /*
     * Already available: no event.
     */
        $classroom = $service->setAvailability(
            $owner,
            $classroom,
            ClassroomAvailabilityStatus::Available
        );

        $this->assertSame(
            0,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'classroom.availability_changed'
                )
                ->count()
        );

        $classroom = $service->setAvailability(
            $owner,
            $classroom,
            ClassroomAvailabilityStatus::Unavailable
        );

        $classroom = $service->setAvailability(
            $owner,
            $classroom,
            ClassroomAvailabilityStatus::Unavailable
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'classroom.availability_changed'
                )
                ->count()
        );

        /*
     * Already active: no event.
     */
        $classroom = $service->activate(
            $owner,
            $classroom
        );

        $this->assertSame(
            0,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'classroom.activated'
                )
                ->count()
        );

        $classroom = $service->deactivate(
            $owner,
            $classroom
        );

        $classroom = $service->deactivate(
            $owner,
            $classroom
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'classroom.deactivated'
                )
                ->count()
        );
    }

    public function test_classroom_management_uses_persisted_scope_instead_of_tampered_in_memory_scope(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create([
                'name' => 'Foreign Classroom',
            ]);

        /*
     * Modify only the in-memory Model.
     * The persisted row still belongs to Center B / Branch B.
     */
        $classroomB->center_id = $centerA->id;
        $classroomB->branch_id = $branchA->id;

        $this->establishCenterOwnerContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $ownerA,
                $classroomB,
                [
                    'name' => 'Should Be Rejected',
                ]
            );
    }

    public function test_classroom_creation_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $failingAudit = \Mockery::mock(
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
                ->create(
                    $owner,
                    $branch,
                    [
                        'name' => 'Rollback Classroom',
                        'code' => 'ROLLBACK-CR',
                        'capacity' => 20,
                        'location' => 'Test Location',
                        'availability_status' =>
                        ClassroomAvailabilityStatus::Available,
                        'status' =>
                        ClassroomStatus::Active,
                    ]
                );

            $this->fail(
                'Expected audit failure to abort classroom creation.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'classrooms',
            [
                'code' => 'ROLLBACK-CR',
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_classroom_update_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create([
                'name' => 'Original Classroom',
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $failingAudit = \Mockery::mock(
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
                ->update(
                    $owner,
                    $classroom,
                    [
                        'name' => 'Should Roll Back',
                    ]
                );

            $this->fail(
                'Expected audit failure to abort classroom update.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'classrooms',
            [
                'id' => $classroom->id,
                'name' => 'Original Classroom',
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_classroom_availability_change_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->available()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $failingAudit = \Mockery::mock(
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
                ->setAvailability(
                    $owner,
                    $classroom,
                    ClassroomAvailabilityStatus::Unavailable
                );

            $this->fail(
                'Expected audit failure to abort availability change.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'classrooms',
            [
                'id' => $classroom->id,
                'availability_status' =>
                ClassroomAvailabilityStatus::Available->value,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_classroom_lifecycle_change_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $failingAudit = \Mockery::mock(
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
                ->deactivate(
                    $owner,
                    $classroom
                );

            $this->fail(
                'Expected audit failure to abort classroom lifecycle change.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'classrooms',
            [
                'id' => $classroom->id,
                'status' =>
                ClassroomStatus::Active->value,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    private function service(): ClassroomManagementService
    {
        return app(
            ClassroomManagementService::class
        );
    }

    private function validClassroomAttributes(): array
    {
        return [
            'name' => 'Classroom 101',
            'code' => 'CR-101',
            'capacity' => 25,
            'location' => 'First Floor',
            'availability_status' =>
            ClassroomAvailabilityStatus::Available,
            'status' =>
            ClassroomStatus::Active,
        ];
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope($center);

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchManagerContext(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope($center);

        app(BranchContext::class)
            ->establishBranchScope($branch);
    }

    private function assignManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' => $branch->center_id,
                'user_id' => $manager->id,
                'branch_id' => $branch->id,
                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
            ]);
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if ($role === SystemRole::PlatformOwner) {
            return User::factory()->create([
                'center_id' => null,
                'person_id' => null,
                'role_id' => $this->role(
                    $role
                )->id,
                'status' => AccountStatus::Active,
            ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(
                $role
            )->id,
            'status' => AccountStatus::Active,
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
