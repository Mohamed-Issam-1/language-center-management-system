<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Registration\RegistrationApprovalService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RegistrationApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_cannot_approve_center_owner_through_public_registration_workflow(): void
    {
        $center =
            $this->center(
                '01'
            );

        $actor =
            $this->platformOwner();

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::CenterOwner,
                attributes: [
                    'national_id_number' =>
                    '900000001',

                    'full_name' =>
                    'Public Center Owner Attempt',

                    'email' =>
                    'owner@example.test',
                ]
            );

        $this->establishPlatformContext();

        $beforeUserCount =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->count();

        $beforePersonCount =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->count();

        try {
            $this->service()
                ->approve(
                    $actor,
                    $request
                );

            $this->fail(
                'Expected Platform Owner public Registration Request approval to be rejected.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertNull(
            $request->reviewed_by_user_id
        );

        $this->assertNull(
            $request->reviewed_at
        );

        $this->assertSame(
            $beforeUserCount,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            $beforePersonCount,
            Person::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_center_owner_can_approve_teacher_registration(): void
    {
        $center = $this->center(
            '01'
        );

        $actor =
            $this->centerOwner(
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Teacher,
                attributes: [
                    'national_id_number' =>
                    '900000002',
                ]
            );

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            '01120001',
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            SystemRole::Teacher,
            $result->account->systemRole()
        );

        $teacher =
            Teacher::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $result->person->id
            )
            ->firstOrFail();

        $this->assertSame(
            $result->account->id,
            $teacher->user_id
        );

        $this->assertSame(
            RegistrationRequestStatus::Approved,
            $result->registrationRequest->status
        );
    }

    public function test_center_owner_can_approve_branch_manager_registration(): void
    {
        $center = $this->center(
            '01'
        );

        $branch =
            $this->branch(
                $center
            );

        $actor =
            $this->centerOwner(
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::BranchManager,
                branch: $branch,
                attributes: [
                    'national_id_number' =>
                    '900000003',
                ]
            );

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            '01110001',
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            SystemRole::BranchManager,
            $result->account->systemRole()
        );

        $record =
            \App\Models\BranchManager::withoutGlobalScopes()
            ->where(
                'person_id',
                $result->person->id
            )
            ->where(
                'center_id',
                $center->id
            )
            ->firstOrFail();

        $this->assertSame(
            $result->account->id,
            $record->user_id
        );

        $assignment =
            BranchManagerAssignment::withoutGlobalScopes()
            ->where(
                'user_id',
                $result->account->id
            )
            ->where(
                'active_marker',
                1
            )
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $assignment->branch_id
        );

        $this->assertNull(
            $assignment->ended_at
        );
    }

    public function test_center_owner_can_approve_finance_employee_registration(): void
    {
        $center = $this->center(
            '01'
        );

        $branch =
            $this->branch(
                $center
            );

        $actor =
            $this->centerOwner(
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::FinanceEmployee,
                branch: $branch,
                attributes: [
                    'national_id_number' =>
                    '900000004',
                ]
            );

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            '01210001',
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            SystemRole::FinanceEmployee,
            $result->account->systemRole()
        );

        $record =
            \App\Models\FinanceEmployee::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $result->person->id
            )
            ->firstOrFail();

        $this->assertSame(
            $result->account->id,
            $record->user_id
        );

        $assignment =
            FinanceEmployeeAssignment::withoutGlobalScopes()
            ->where(
                'user_id',
                $result->account->id
            )
            ->where(
                'active_marker',
                1
            )
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $assignment->branch_id
        );

        $this->assertNull(
            $assignment->ended_at
        );
    }

    public function test_center_owner_can_approve_student_registration(): void
    {
        $center = $this->center(
            '01'
        );

        $branch =
            $this->branch(
                $center
            );

        $actor =
            $this->centerOwner(
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Student,
                branch: $branch,
                attributes: [
                    'national_id_number' =>
                    '900000005',
                ]
            );

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            '01130001',
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            SystemRole::Student,
            $result->account->systemRole()
        );

        $student =
            Student::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $result->person->id
            )
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $result->account->id,
            $student->user_id
        );
    }

    public function test_branch_manager_can_approve_student_in_own_branch(): void
    {
        $center = $this->center(
            '02'
        );

        $branch =
            $this->branch(
                $center
            );

        $actor =
            $this->branchManagerActor(
                $center,
                $branch
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Student,
                branch: $branch,
                attributes: [
                    'national_id_number' =>
                    '900000006',
                ]
            );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            '02130001',
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            SystemRole::Student,
            $result->account->systemRole()
        );

        $student =
            Student::withoutGlobalScopes()
            ->where(
                'person_id',
                $result->person->id
            )
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $result->account->id,
            $student->user_id
        );

        $this->assertSame(
            $actor->id,
            $result->registrationRequest
                ->reviewed_by_user_id
        );
    }

    public function test_approval_reuses_and_synchronizes_existing_person_identity(): void
    {
        $center = $this->center(
            '03'
        );

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900000007',

                'full_name' =>
                'Old Name',

                'city_of_residence' =>
                'Old City',

                'email' =>
                'old@example.test',

                'phone_number' =>
                '+970590000000',
            ]);

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Teacher,
                attributes: [
                    'national_id_number' =>
                    '900000007',

                    'full_name' =>
                    'Approved Name',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'APPROVED@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599999999',

                    'personal_picture_path' =>
                    'registration/pictures/person.jpg',
                ]
            );

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()
            ->approve(
                $actor,
                $request
            );

        $this->assertSame(
            $person->id,
            $result->person->id
        );

        $result->person->refresh();

        $this->assertSame(
            'Approved Name',
            $result->person->full_name
        );

        $this->assertSame(
            'Gaza',
            $result->person
                ->city_of_residence
        );

        $this->assertSame(
            'approved@example.test',
            $result->person->email
        );

        $this->assertSame(
            '+970599999999',
            $result->person
                ->phone_number
        );

        $this->assertSame(
            'registration/pictures/person.jpg',
            $result->person
                ->personal_picture_path
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
                    '900000007'
                )
                ->count()
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.registration_identity_updated',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_registration_cannot_be_approved_without_selected_role(): void
    {
        $center = $this->center(
            '04'
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = RegistrationRequest::factory()
            ->create([
                'center_id' =>
                $center->id,

                'selected_role_id' =>
                null,

                'selected_branch_id' =>
                null,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_platform_owner_role_cannot_be_approved_through_center_registration(): void
    {
        $center = $this->center(
            '05'
        );

        $actor = $this->platformOwner();

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::PlatformOwner
        );

        $this->establishPlatformContext();

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_teacher_registration_cannot_have_selected_branch(): void
    {
        $center = $this->center(
            '06'
        );

        $branch = $this->branch(
            $center
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher,
            branch: $branch
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_student_registration_requires_selected_branch(): void
    {
        $center = $this->center(
            '07'
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Student
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_inactive_selected_branch_cannot_be_approved(): void
    {
        $center = $this->center(
            '08'
        );

        $branch = Branch::factory()
            ->create([
                'center_id' =>
                $center->id,

                'status' =>
                BranchStatus::Deactivated,
            ]);

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Student,
            branch: $branch
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_center_owner_cannot_approve_registration_from_another_center(): void
    {
        $centerA = $this->center(
            '09'
        );

        $centerB = $this->center(
            '10'
        );

        $actor = $this->centerOwner(
            $centerA
        );

        $request = $this->registrationRequest(
            center: $centerB,
            role: SystemRole::Teacher
        );

        $this->establishCenterContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_branch_manager_cannot_approve_student_without_matching_branch_context(): void
    {
        $center = $this->center(
            '11'
        );

        $assignedBranch = $this->branch(
            $center
        );

        $otherBranch = $this->branch(
            $center
        );

        $actor = $this->branchManagerActor(
            $center,
            $assignedBranch
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Student,
            branch: $assignedBranch
        );

        /*
     * Persisted assignment is correct, but request-level
     * BranchContext intentionally points elsewhere.
     */
        $this->establishBranchContext(
            $center,
            $otherBranch
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_branch_manager_cannot_approve_student_for_another_branch(): void
    {
        $center = $this->center(
            '12'
        );

        $assignedBranch = $this->branch(
            $center
        );

        $otherBranch = $this->branch(
            $center
        );

        $actor = $this->branchManagerActor(
            $center,
            $assignedBranch
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Student,
            branch: $otherBranch
        );

        $this->establishBranchContext(
            $center,
            $assignedBranch
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_deactivated_actor_cannot_approve_registration(): void
    {
        $center = $this->center(
            '13'
        );

        $actor = $this->centerOwner(
            $center
        );

        $actor->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_invalid_registration_identity_cannot_be_approved(): void
    {
        $center = $this->center(
            '14'
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher,
            attributes: [
                'email' =>
                'not-an-email',
            ]
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_approved_registration_cannot_be_approved_again(): void
    {
        $center = $this->center(
            '15'
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher
        );

        $this->establishCenterContext(
            $center
        );

        $this->service()->approve(
            $actor,
            $request
        );

        $userCount =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->count();

        try {
            $this->service()->approve(
                $actor,
                $request
            );

            $this->fail(
                'Expected repeated Registration approval to be rejected.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            $userCount,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );
    }

    public function test_approval_uses_persisted_request_state_instead_of_tampered_memory_state(): void
    {
        $center = $this->center(
            '16'
        );

        $actor = $this->centerOwner(
            $center
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher,
            attributes: [
                'national_id_number' =>
                '900000016',

                'full_name' =>
                'Persisted Registration Name',
            ]
        );

        /*
     * Local model mutations must not become approved identity.
     */
        $request->full_name =
            'Tampered In Memory Name';

        $request->email =
            'tampered@example.test';

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()->approve(
            $actor,
            $request
        );

        $this->assertSame(
            'Persisted Registration Name',
            $result->person->full_name
        );

        $this->assertNotSame(
            'tampered@example.test',
            $result->person->email
        );
    }

    public function test_duplicate_role_account_failure_rolls_back_person_identity_and_identifier_sequence(): void
    {
        $center = $this->center(
            '17'
        );

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900000017',

                'full_name' =>
                'Original Person Name',

                'email' =>
                'original@example.test',
            ]);

        /*
     * Existing Teacher account makes final account creation
     * invalid for this Registration Request.
     */
        $this->roleAccount(
            center: $center,
            person: $person,
            role: SystemRole::Teacher
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::Teacher,
            attributes: [
                'national_id_number' =>
                '900000017',

                'full_name' =>
                'Changed During Approval',

                'email' =>
                'changed@example.test',
            ]
        );

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()->approve(
                $actor,
                $request
            );

            $this->fail(
                'Expected duplicate Teacher account to abort Registration approval.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        /*
     * Person synchronization occurred before account creation,
     * so this proves the outer transaction rolled it back.
     */
        $person->refresh();

        $this->assertSame(
            'Original Person Name',
            $person->full_name
        );

        $this->assertSame(
            'original@example.test',
            $person->email
        );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertSame(
            1,
            $request->pending_marker
        );

        /*
     * Identifier allocation also happened before account
     * creation. It must not survive the failed approval.
     */
        $this->assertDatabaseMissing(
            'account_identifier_sequences',
            [
                'center_identifier_code' =>
                '17',

                'role_code' =>
                SystemRole::Teacher->value,
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'person.registration_identity_updated',

                'subject_id' =>
                $person->id,
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_late_branch_manager_assignment_failure_rolls_back_entire_approval(): void
    {
        $center = $this->center(
            '18'
        );

        $branch = $this->branch(
            $center
        );

        $actor = $this->centerOwner(
            $center
        );

        /*
     * Occupy the Branch with an existing active
     * Branch Manager assignment.
     *
     * The new approval will therefore fail late,
     * after Person, identifier, account, and
     * operational Staff creation have already run.
     */
        $this->branchManagerActor(
            $center,
            $branch
        );

        $request = $this->registrationRequest(
            center: $center,
            role: SystemRole::BranchManager,
            branch: $branch,
            attributes: [
                'national_id_number' =>
                '900000018',

                'full_name' =>
                'Rollback Branch Manager',

                'email' =>
                'rollback.manager@example.test',
            ]
        );

        $this->establishCenterContext(
            $center
        );

        $beforeUserCount =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->count();

        try {
            $this->service()->approve(
                $actor,
                $request
            );

            $this->fail(
                'Expected occupied Branch assignment to abort Registration approval.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertSame(
            1,
            $request->pending_marker
        );

        /*
     * The account created before assignment failure
     * must not survive.
     */
        $this->assertSame(
            $beforeUserCount,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'account_login_identifier' =>
                '18110001',
            ]
        );

        /*
     * The Person was also created before account
     * and assignment handling. It must roll back.
     */
        $this->assertDatabaseMissing(
            'people',
            [
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900000018',
            ]
        );

        /*
     * The operational Branch Manager record and
     * account linkage must not survive either.
     */
        $this->assertSame(
            0,
            \App\Models\BranchManager::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->whereHas(
                    'person',
                    function ($query): void {
                        $query->where(
                            'national_id_number',
                            '900000018'
                        );
                    }
                )
                ->count()
        );

        /*
     * Identifier allocation occurred before the
     * assignment failure and must roll back too.
     */
        $this->assertDatabaseMissing(
            'account_identifier_sequences',
            [
                'center_identifier_code' =>
                '18',

                'role_code' =>
                SystemRole::BranchManager->value,
            ]
        );

        /*
     * Audit events created inside the aborted
     * transaction must not survive.
     */
        $this->assertDatabaseMissing(
            'audit_records',
            [
                'center_id' =>
                $center->id,

                'action_type' =>
                'person.registration_identity_created',
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'center_id' =>
                $center->id,

                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );

        /*
     * The assignment that caused the conflict is
     * pre-existing state and must remain untouched.
     */
        $this->assertSame(
            1,
            BranchManagerAssignment::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->where(
                    'active_marker',
                    1
                )
                ->count()
        );
    }

    private function service(): RegistrationApprovalService
    {
        return app(
            RegistrationApprovalService::class
        );
    }

    private function center(
        string $identifierCode
    ): Center {
        return Center::factory()
            ->create([
                'identifier_code' =>
                $identifierCode,
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->create([
                'center_id' =>
                $center->id,

                'status' =>
                BranchStatus::Active,
            ]);
    }

    private function registrationRequest(
        Center $center,
        SystemRole $role,
        ?Branch $branch = null,
        array $attributes = []
    ): RegistrationRequest {
        return RegistrationRequest::factory()
            ->create(
                array_merge(
                    [
                        'center_id' =>
                        $center->id,

                        'selected_role_id' =>
                        $this->role(
                            $role
                        )->id,

                        'selected_branch_id' =>
                        $branch?->id,
                    ],
                    $attributes
                )
            );
    }

    private function platformOwner(): User
    {
        return User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

                'account_login_identifier' =>
                'platform.owner',

                'recovery_email' =>
                'platform@example.test',

                'status' =>
                AccountStatus::Active,
            ]);
    }

    private function centerOwner(
        Center $center
    ): User {
        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        return $this->roleAccount(
            center: $center,
            person: $person,
            role: SystemRole::CenterOwner
        );
    }

    private function branchManagerActor(
        Center $center,
        Branch $branch
    ): User {
        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $manager =
            $this->roleAccount(
                center: $center,
                person: $person,
                role: SystemRole::BranchManager
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

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

        return $manager;
    }

    private function roleAccount(
        Center $center,
        Person $person,
        SystemRole $role
    ): User {
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

    private function establishPlatformContext(): void
    {
        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();
    }

    private function establishCenterContext(
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
}
