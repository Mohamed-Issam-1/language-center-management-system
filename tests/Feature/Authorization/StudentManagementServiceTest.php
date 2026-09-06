<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentManagementService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use LogicException;

class StudentManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_center_owner_can_register_student_and_create_person(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $student = $this->service()
            ->register(
                $owner,
                $branch,
                '123456789'
            );

        $this->assertSame(
            $center->id,
            $student->center_id
        );

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertNull(
            $student->user_id
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertNull(
            $student->archived_at
        );

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $student->person_id,
                'center_id' => $center->id,
                'national_id_number' => '123456789',
            ]
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'center_id' => $center->id,
                'branch_id' => $branch->id,
                'person_id' => $student->person_id,
                'user_id' => null,
                'status' =>
                StudentStatus::Active->value,
            ]
        );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'student.created'
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
            'students',
            $record->subject_type
        );

        $this->assertSame(
            $student->id,
            $record->subject_id
        );

        $this->assertSame(
            StudentStatus::Active->value,
            $record->after_values['status']
        );
    }

    public function test_registration_reuses_existing_person_in_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create([
                'national_id_number' => '987654321',
            ]);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $student = $this->service()
            ->register(
                $owner,
                $branch,
                ' 987654321 '
            );

        $this->assertSame(
            $person->id,
            $student->person_id
        );

        $this->assertSame(
            1,
            Person::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'national_id_number',
                    '987654321'
                )
                ->count()
        );
    }

    public function test_registration_rejects_duplicate_student_for_existing_person_even_when_archived(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create([
                'national_id_number' => '111222333',
            ]);

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->archived()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->register(
                $owner,
                $branch,
                '111222333'
            );
    }

    public function test_registration_requires_non_empty_national_id_number(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->register(
                $owner,
                $branch,
                '   '
            );
    }

    public function test_student_cannot_be_registered_in_deactivated_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->register(
                $owner,
                $branch,
                '222333444'
            );
    }

    public function test_center_owner_cannot_register_student_in_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterWideContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->register(
                $ownerA,
                $branchB,
                '333444555'
            );
    }

    public function test_branch_manager_can_register_student_in_assigned_branch(): void
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

        $this->establishBranchContext(
            $center,
            $branch
        );

        $student = $this->service()
            ->register(
                $manager,
                $branch,
                '444555666'
            );

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );
    }

    public function test_branch_manager_operation_must_match_current_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $requestBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        /*
         * Persisted assignment permits assignedBranch,
         * but the request itself carries a different BranchContext.
         */
        $this->establishBranchContext(
            $center,
            $requestBranch
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->register(
                $manager,
                $assignedBranch,
                '555666777'
            );
    }

    public function test_center_owner_can_move_active_student_between_active_branches(): void
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

        $student = Student::factory()
            ->forBranch($branchA)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $student,
                [
                    'branch_id' => $branchB->id,
                ]
            );

        $this->assertSame(
            $branchB->id,
            $updated->branch_id
        );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'student.updated'
            )
            ->firstOrFail();

        $this->assertSame(
            $branchA->id,
            $record->before_values['branch_id']
        );

        $this->assertSame(
            $branchB->id,
            $record->after_values['branch_id']
        );
    }

    public function test_general_student_update_ignores_protected_fields_and_no_op_is_not_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $student,
                [
                    'center_id' => 999999,
                    'person_id' => 999999,
                    'user_id' => 999999,
                    'status' =>
                    StudentStatus::Archived,
                    'archived_at' => now(),
                ]
            );

        $this->assertSame(
            $center->id,
            $updated->center_id
        );

        $this->assertSame(
            $branch->id,
            $updated->branch_id
        );

        $this->assertSame(
            $person->id,
            $updated->person_id
        );

        $this->assertNull(
            $updated->user_id
        );

        $this->assertSame(
            StudentStatus::Active,
            $updated->status
        );

        $this->assertNull(
            $updated->archived_at
        );

        $this->assertSame(
            0,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'student.updated'
                )
                ->count()
        );
    }

    public function test_branch_manager_cannot_move_student_to_another_branch(): void
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

        $student = Student::factory()
            ->forBranch($branchA)
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

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $manager,
                $student,
                [
                    'branch_id' => $branchB->id,
                ]
            );
    }

    public function test_archived_student_cannot_be_updated(): void
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

        $student = Student::factory()
            ->forBranch($branchA)
            ->archived()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $owner,
                $student,
                [
                    'branch_id' => $branchB->id,
                ]
            );
    }

    public function test_student_cannot_be_moved_to_deactivated_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $activeBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $deactivatedBranch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $student = Student::factory()
            ->forBranch($activeBranch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $owner,
                $student,
                [
                    'branch_id' =>
                    $deactivatedBranch->id,
                ]
            );
    }

    public function test_archiving_student_preserves_record_and_is_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $service = $this->service();

        $student = $service->archive(
            $owner,
            $student
        );

        $this->assertSame(
            StudentStatus::Archived,
            $student->status
        );

        $this->assertNotNull(
            $student->archived_at
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'status' =>
                StudentStatus::Archived->value,
            ]
        );

        $service->archive(
            $owner,
            $student
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'student.archived'
                )
                ->count()
        );
    }

    public function test_restoring_student_is_idempotent_and_clears_archived_at(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->archived()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $service = $this->service();

        $student = $service->restore(
            $owner,
            $student
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertNull(
            $student->archived_at
        );

        $service->restore(
            $owner,
            $student
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'student.restored'
                )
                ->count()
        );
    }

    public function test_student_cannot_be_restored_while_branch_is_deactivated(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->archived()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->restore(
                $owner,
                $student
            );
    }

    public function test_student_role_account_for_same_person_and_center_can_be_linked(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $account = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $student = $this->service()
            ->linkAccount(
                $owner,
                $student,
                $account
            );

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'student.account_linked'
            )
            ->firstOrFail();

        $this->assertNull(
            $record->before_values['user_id']
        );

        $this->assertSame(
            $account->id,
            $record->after_values['user_id']
        );
    }

    public function test_non_student_role_account_cannot_be_linked_to_student(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $teacherAccount =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
            );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkAccount(
                $owner,
                $student,
                $teacherAccount
            );
    }

    public function test_student_account_for_another_person_cannot_be_linked(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $studentPerson = Person::factory()
            ->for($center)
            ->create();

        $otherPerson = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($studentPerson)
            ->active()
            ->create();

        $otherAccount =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $otherPerson
            );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkAccount(
                $owner,
                $student,
                $otherAccount
            );
    }

    public function test_archived_student_cannot_link_account(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->archived()
            ->create();

        $account = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkAccount(
                $owner,
                $student,
                $account
            );
    }

    public function test_student_management_uses_persisted_state_instead_of_tampered_model_state(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($assignedBranch)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        /*
         * Only mutate the supplied in-memory instance.
         *
         * The persisted Student still belongs to assignedBranch.
         */
        $student->branch_id =
            $otherBranch->id;

        $student->status =
            StudentStatus::Archived;

        $this->establishBranchContext(
            $center,
            $assignedBranch
        );

        $archived = $this->service()
            ->archive(
                $manager,
                $student
            );

        $this->assertSame(
            $assignedBranch->id,
            $archived->branch_id
        );

        $this->assertSame(
            StudentStatus::Archived,
            $archived->status
        );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'student.archived'
            )
            ->firstOrFail();

        $this->assertSame(
            $assignedBranch->id,
            $record->before_values['branch_id']
        );
    }

    public function test_branch_manager_can_link_student_account_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $account = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $linked = $this->service()
            ->linkAccount(
                $manager,
                $student,
                $account
            );

        $this->assertSame(
            $account->id,
            $linked->user_id
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'user_id' => $account->id,
            ]
        );
    }

    public function test_branch_manager_cannot_link_student_account_outside_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($otherBranch)
            ->forPerson($person)
            ->active()
            ->create();

        $account = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        $this->establishBranchContext(
            $center,
            $assignedBranch
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->linkAccount(
                $manager,
                $student,
                $account
            );
    }

    public function test_student_registration_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->register(
                    $owner,
                    $branch,
                    '700800900'
                );

            $this->fail(
                'Expected audit failure to abort Student registration.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        /*
     * Both Student and newly-created Person belong to the same
     * transaction and must disappear when Audit recording fails.
     */
        $this->assertDatabaseMissing(
            'people',
            [
                'center_id' => $center->id,
                'national_id_number' => '700800900',
            ]
        );

        $this->assertDatabaseCount(
            'students',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_update_rolls_back_when_audit_recording_fails(): void
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

        $student = Student::factory()
            ->forBranch($branchA)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->update(
                    $owner,
                    $student,
                    [
                        'branch_id' => $branchB->id,
                    ]
                );

            $this->fail(
                'Expected audit failure to abort Student update.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'branch_id' => $branchA->id,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_archive_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->archive(
                    $owner,
                    $student
                );

            $this->fail(
                'Expected audit failure to abort Student archive.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertNull(
            $student->archived_at
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'status' => StudentStatus::Active->value,
                'archived_at' => null,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_restore_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->archived()
            ->create();

        $originalArchivedAt =
            $student->archived_at;

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->restore(
                    $owner,
                    $student
                );

            $this->fail(
                'Expected audit failure to abort Student restore.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertSame(
            StudentStatus::Archived,
            $student->status
        );

        $this->assertNotNull(
            $student->archived_at
        );

        $this->assertSame(
            $originalArchivedAt?->getTimestamp(),
            $student->archived_at?->getTimestamp()
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'status' => StudentStatus::Archived->value,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_account_link_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $account = $this->createUserForRole(
            SystemRole::Student,
            $center,
            $person
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->linkAccount(
                    $owner,
                    $student,
                    $account
                );

            $this->fail(
                'Expected audit failure to abort Student account linking.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertNull(
            $student->user_id
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'user_id' => null,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    private function bindFailingAudit(): void
    {
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
    }

    private function service(): StudentManagementService
    {
        return app(
            StudentManagementService::class
        );
    }

    private function establishCenterWideContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchContext(
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

    private function assignManager(
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

                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
            ]);
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null,
        ?Person $person = null
    ): User {
        if (
            $role
            === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role(
                        $role
                    )->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        if (
            $person !== null
            && $person->center_id
            !== $center->id
        ) {
            throw new \LogicException(
                'Test Person and Center must match.'
            );
        }

        $person ??= Person::factory()
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
