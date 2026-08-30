<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Registration\RegistrationApprovalService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

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

    public function test_center_owner_approves_self_registration_by_activating_existing_pending_account_and_creating_student(): void
    {
        $center = $this->center('01');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000001',
                password: 'ChosenPass1'
            );

        $accountId = $account->id;
        $identifier = $account->account_login_identifier;
        $passwordHash = $account->password;

        $beforeUserCount =
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count();

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()->approve(
            $actor,
            $request
        );

        $this->assertSame(
            $accountId,
            $result->account->id
        );

        $this->assertSame(
            $person->id,
            $result->person->id
        );

        $this->assertSame(
            SystemRole::Student,
            $result->role
        );

        $this->assertSame(
            AccountStatus::Active,
            $result->account->status
        );

        $this->assertSame(
            $identifier,
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            $passwordHash,
            $result->account->password
        );

        $this->assertTrue(
            Hash::check(
                'ChosenPass1',
                $result->account->password
            )
        );

        $this->assertFalse(
            $result->account
                ->must_change_password
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

        $student =
            Student::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'person_id',
                    $person->id
                )
                ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $accountId,
            $student->user_id
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertSame(
            RegistrationRequestStatus::Approved,
            $result->registrationRequest->status
        );

        $this->assertSame(
            $actor->id,
            $result->registrationRequest
                ->reviewed_by_user_id
        );

        $this->assertNull(
            $result->registrationRequest
                ->pending_marker
        );

        /*
         * Approval must not allocate another login identifier.
         * The identifier was already created at submission.
         */
        $this->assertDatabaseCount(
            'account_identifier_sequences',
            0
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'user_account.activated',

                'subject_id' =>
                $accountId,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_branch_manager_can_approve_self_registration_in_own_branch(): void
    {
        $center = $this->center('02');
        $branch = $this->branch($center);

        $actor = $this->branchManagerActor(
            $center,
            $branch
        );

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000002'
            );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $result = $this->service()->approve(
            $actor,
            $request
        );

        $this->assertSame(
            $account->id,
            $result->account->id
        );

        $this->assertSame(
            AccountStatus::Active,
            $result->account->status
        );

        $student =
            Student::withoutGlobalScopes()
                ->where(
                    'person_id',
                    $person->id
                )
                ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $this->assertSame(
            $actor->id,
            $result->registrationRequest
                ->reviewed_by_user_id
        );
    }

    public function test_approval_reuses_existing_matching_student_record(): void
    {
        $center = $this->center('03');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000003'
            );

        $student =
            Student::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'branch_id' =>
                    $branch->id,

                    'person_id' =>
                    $person->id,

                    'user_id' =>
                    null,

                    'status' =>
                    StudentStatus::Active,

                    'archived_at' =>
                    null,
                ]);

        $studentId = $student->id;

        $this->establishCenterContext(
            $center
        );

        $this->service()->approve(
            $actor,
            $request
        );

        $student->refresh();

        $this->assertSame(
            $studentId,
            $student->id
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $this->assertSame(
            1,
            Student::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'person_id',
                    $person->id
                )
                ->count()
        );
    }

    public function test_approval_restores_archived_existing_student_record(): void
    {
        $center = $this->center('04');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000004'
            );

        $student =
            Student::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'branch_id' =>
                    $branch->id,

                    'person_id' =>
                    $person->id,

                    'user_id' =>
                    null,

                    'status' =>
                    StudentStatus::Archived,

                    'archived_at' =>
                    now(),
                ]);

        $this->establishCenterContext(
            $center
        );

        $this->service()->approve(
            $actor,
            $request
        );

        $student->refresh();

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertNull(
            $student->archived_at
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );
    }

    public function test_center_owner_moves_existing_student_to_selected_registration_branch(): void
    {
        $center = $this->center('05');
        $oldBranch = $this->branch($center);
        $selectedBranch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $selectedBranch,
                nationalId: '900000005'
            );

        $student =
            Student::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'branch_id' =>
                    $oldBranch->id,

                    'person_id' =>
                    $person->id,

                    'user_id' =>
                    null,

                    'status' =>
                    StudentStatus::Active,

                    'archived_at' =>
                    null,
                ]);

        $this->establishCenterContext(
            $center
        );

        $this->service()->approve(
            $actor,
            $request
        );

        $student->refresh();

        $this->assertSame(
            $selectedBranch->id,
            $student->branch_id
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );
    }

    public function test_approval_preserves_applicant_password_and_login_identifier(): void
    {
        $center = $this->center('06');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, , $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000006',
                password: 'Applicant9Pass'
            );

        $beforeHash = $account->password;
        $beforeIdentifier =
            $account->account_login_identifier;

        $this->establishCenterContext(
            $center
        );

        $result = $this->service()->approve(
            $actor,
            $request
        );

        $this->assertSame(
            $beforeIdentifier,
            $result->account
                ->account_login_identifier
        );

        $this->assertSame(
            $beforeHash,
            $result->account->password
        );

        $this->assertTrue(
            Hash::check(
                'Applicant9Pass',
                $result->account->password
            )
        );

        $this->assertFalse(
            $result->account
                ->must_change_password
        );
    }

    public function test_non_student_registration_role_cannot_be_approved(): void
    {
        $center = $this->center('07');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000007'
            );

        $request->forceFill([
            'selected_role_id' =>
            $this->role(
                SystemRole::Teacher
            )->id,
        ])->save();

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

    public function test_linked_user_must_still_be_pending_when_request_is_approved(): void
    {
        $center = $this->center('08');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, , $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000008'
            );

        $account->forceFill([
            'status' =>
            AccountStatus::Active,
        ])->save();

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
        $center = $this->center('09');

        $branch =
            Branch::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'status' =>
                    BranchStatus::Deactivated,
                ]);

        $actor = $this->centerOwner(
            $center
        );

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000009'
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

    public function test_center_owner_cannot_approve_self_registration_from_another_center(): void
    {
        $centerA = $this->center('10');
        $centerB = $this->center('11');
        $branchB = $this->branch($centerB);

        $actor = $this->centerOwner(
            $centerA
        );

        [$request] =
            $this->selfRegistration(
                center: $centerB,
                branch: $branchB,
                nationalId: '900000010'
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

    public function test_branch_manager_cannot_approve_self_registration_for_another_branch(): void
    {
        $center = $this->center('12');

        $assignedBranch =
            $this->branch(
                $center
            );

        $otherBranch =
            $this->branch(
                $center
            );

        $actor =
            $this->branchManagerActor(
                $center,
                $assignedBranch
            );

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $otherBranch,
                nationalId: '900000011'
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

    public function test_platform_owner_cannot_approve_public_self_registration(): void
    {
        $center = $this->center('13');
        $branch = $this->branch($center);
        $actor = $this->platformOwner();

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000012'
            );

        $this->establishPlatformContext();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_deactivated_actor_cannot_approve_self_registration(): void
    {
        $center = $this->center('14');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        $actor->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000013'
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

    public function test_approved_self_registration_cannot_be_approved_again(): void
    {
        $center = $this->center('15');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000014'
            );

        $this->establishCenterContext(
            $center
        );

        $this->service()->approve(
            $actor,
            $request
        );

        $request->refresh();

        $this->expectException(
            DomainException::class
        );

        $this->service()->approve(
            $actor,
            $request
        );
    }

    public function test_approval_uses_persisted_request_state_instead_of_tampered_memory_state(): void
    {
        $center = $this->center('16');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000015',
                fullName: 'Persisted Registration Name'
            );

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

    public function test_late_student_link_failure_rolls_back_identity_and_account_activation(): void
    {
        $center = $this->center('17');
        $branch = $this->branch($center);
        $actor = $this->centerOwner($center);

        [$request, $person, $account] =
            $this->selfRegistration(
                center: $center,
                branch: $branch,
                nationalId: '900000016',
                fullName: 'Original Applicant Name'
            );

        /*
         * Make the Registration identity differ from Person so
         * approval performs a Person synchronization before the
         * intentionally late Student-link failure.
         */
        $request->forceFill([
            'full_name' =>
            'Changed During Approval',
        ])->save();

        /*
         * Create a pre-existing Student record already linked to
         * another account for the same Person. linkAccount() must
         * reject replacing that relationship.
         */
        $otherAccount =
            $this->roleAccount(
                center: $center,
                person: $person,
                role: SystemRole::Teacher
            );

        Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $otherAccount->id,

                'status' =>
                StudentStatus::Active,

                'archived_at' =>
                null,
            ]);

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()->approve(
                $actor,
                $request
            );

            $this->fail(
                'Expected the existing Student account link to abort approval.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $person->refresh();
        $account->refresh();
        $request->refresh();

        $this->assertSame(
            'Original Applicant Name',
            $person->full_name
        );

        $this->assertSame(
            AccountStatus::Pending,
            $account->status
        );

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertSame(
            1,
            $request->pending_marker
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
            ->active()
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

    /**
     * @return array{
     *     0: RegistrationRequest,
     *     1: Person,
     *     2: User
     * }
     */
    private function selfRegistration(
        Center $center,
        Branch $branch,
        string $nationalId,
        string $password = 'ChosenPass1',
        string $fullName = 'Self Registration Student'
    ): array {
        $email =
            strtolower($nationalId)
            . '@example.test';

        $person =
            Person::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'national_id_number' =>
                    $nationalId,

                    'full_name' =>
                    $fullName,

                    'date_of_birth' =>
                    '2001-05-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    $email,

                    'phone_number' =>
                    '+970599123456',

                    'personal_picture_path' =>
                    null,
                ]);

        $account =
            User::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'person_id' =>
                    $person->id,

                    'role_id' =>
                    $this->role(
                        SystemRole::Student
                    )->id,

                    'account_login_identifier' =>
                    $center->identifier_code
                    . '139999',

                    'recovery_email' =>
                    $email,

                    'status' =>
                    AccountStatus::Pending,

                    'password' =>
                    Hash::make(
                        $password
                    ),
                ]);

        $account->forceFill([
            'must_change_password' =>
            false,

            'temporary_password_used_at' =>
            null,

            'failed_login_attempts' =>
            0,

            'locked_until' =>
            null,

            'last_login_at' =>
            null,

            'password_changed_at' =>
            null,

            'deactivated_at' =>
            null,
        ])->save();

        $account->refresh();

        $request =
            RegistrationRequest::factory()
                ->create([
                    'center_id' =>
                    $center->id,

                    'person_id' =>
                    $person->id,

                    'user_id' =>
                    $account->id,

                    'national_id_number' =>
                    $nationalId,

                    'full_name' =>
                    $fullName,

                    'date_of_birth' =>
                    '2001-05-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    $email,

                    'phone_number' =>
                    '+970599123456',

                    'personal_picture_path' =>
                    null,

                    'selected_role_id' =>
                    $this->role(
                        SystemRole::Student
                    )->id,

                    'selected_branch_id' =>
                    $branch->id,

                    'status' =>
                    RegistrationRequestStatus::Pending,

                    'reviewed_by_user_id' =>
                    null,

                    'reviewed_at' =>
                    null,

                    'rejection_reason' =>
                    null,

                    'pending_marker' =>
                    1,
                ]);

        return [
            $request,
            $person,
            $account,
        ];
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
