<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Student;
use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\BranchContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class UserAccountManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_platform_owner_can_create_center_owner_account_with_new_person_and_temporary_password(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->suspended()
            ->create();

        $this->establishPlatformContext();

        $account = $this->service()
            ->create(
                actor: $platformOwner,
                center: $center,
                nationalIdNumber: '900000001',
                role: SystemRole::CenterOwner,
                accountLoginIdentifier: 'center.owner.one',
                recoveryEmail: 'OWNER@EXAMPLE.TEST',
                temporaryPassword: 'Temporary!123'
            );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertNotNull(
            $account->person_id
        );

        $this->assertSame(
            SystemRole::CenterOwner,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'center.owner.one',
            $account->account_login_identifier
        );

        $this->assertSame(
            'owner@example.test',
            $account->recovery_email
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNull(
            $account->temporary_password_used_at
        );

        $this->assertNull(
            $account->password_changed_at
        );

        $this->assertNull(
            $account->deactivated_at
        );

        $this->assertTrue(
            Hash::check(
                'Temporary!123',
                $account->password
            )
        );

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $account->person_id,
                'center_id' => $center->id,
                'national_id_number' => '900000001',
            ]
        );

        $this->assertStringEndsWith(
            '@internal.lcms.invalid',
            $account->email
        );

        $record = AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.created'
            )
            ->firstOrFail();

        $this->assertSame(
            $platformOwner->id,
            $record->actor_user_id
        );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            'users',
            $record->subject_type
        );

        $this->assertSame(
            $account->id,
            $record->subject_id
        );

        $this->assertNull(
            $record->before_values
        );

        $this->assertSame(
            SystemRole::CenterOwner->value,
            $record->after_values['role']
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $record->after_values['status']
        );

        $this->assertTrue(
            $record->after_values['credential_change_required']
        );
    }

    public function test_account_creation_reuses_existing_person_inside_same_center(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create([
                'national_id_number' => '900000002',
            ]);

        $this->establishPlatformContext();

        $account = $this->service()
            ->create(
                actor: $platformOwner,
                center: $center,
                nationalIdNumber: '900000002',
                role: SystemRole::CenterOwner,
                accountLoginIdentifier: 'existing.person.owner',
                recoveryEmail: 'existing@example.test',
                temporaryPassword: 'Temporary!123'
            );

        $this->assertSame(
            $person->id,
            $account->person_id
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
                    '900000002'
                )
                ->count()
        );
    }

    public function test_same_person_can_receive_separate_role_specific_accounts(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $teacher = $this->service()
            ->create(
                actor: $centerOwner,
                center: $center,
                nationalIdNumber: '900000003',
                role: SystemRole::Teacher,
                accountLoginIdentifier: 'person.teacher',
                recoveryEmail: 'shared@example.test',
                temporaryPassword: 'Temporary!123'
            );

        $student = $this->service()
            ->create(
                actor: $centerOwner,
                center: $center,
                nationalIdNumber: '900000003',
                role: SystemRole::Student,
                accountLoginIdentifier: 'person.student',
                recoveryEmail: 'shared@example.test',
                temporaryPassword: 'Temporary!456'
            );

        $this->assertNotSame(
            $teacher->id,
            $student->id
        );

        $this->assertSame(
            $teacher->person_id,
            $student->person_id
        );

        $this->assertSame(
            SystemRole::Teacher,
            $teacher->systemRole()
        );

        $this->assertSame(
            SystemRole::Student,
            $student->systemRole()
        );

        $this->assertSame(
            'shared@example.test',
            $teacher->recovery_email
        );

        $this->assertSame(
            'shared@example.test',
            $student->recovery_email
        );
    }

    public function test_same_person_cannot_receive_duplicate_account_for_same_role(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->create(
                actor: $centerOwner,
                center: $center,
                nationalIdNumber: '900000004',
                role: SystemRole::Teacher,
                accountLoginIdentifier: 'teacher.first',
                recoveryEmail: 'teacher@example.test',
                temporaryPassword: 'Temporary!123'
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                actor: $centerOwner,
                center: $center,
                nationalIdNumber: '900000004',
                role: SystemRole::Teacher,
                accountLoginIdentifier: 'teacher.second',
                recoveryEmail: 'teacher@example.test',
                temporaryPassword: 'Temporary!456'
            );
    }

    public function test_account_login_identifier_is_unique_platform_wide_through_service(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $this->establishPlatformContext();

        $this->service()
            ->create(
                actor: $platformOwner,
                center: $centerA,
                nationalIdNumber: '900000005',
                role: SystemRole::CenterOwner,
                accountLoginIdentifier: 'platform.unique.identifier',
                recoveryEmail: 'a@example.test',
                temporaryPassword: 'Temporary!123'
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                actor: $platformOwner,
                center: $centerB,
                nationalIdNumber: '900000006',
                role: SystemRole::CenterOwner,
                accountLoginIdentifier: 'platform.unique.identifier',
                recoveryEmail: 'b@example.test',
                temporaryPassword: 'Temporary!456'
            );
    }

    public function test_platform_owner_cannot_create_non_center_owner_account(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->create();

        $this->establishPlatformContext();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                actor: $platformOwner,
                center: $center,
                nationalIdNumber: '900000007',
                role: SystemRole::Teacher,
                accountLoginIdentifier: 'blocked.teacher',
                recoveryEmail: 'teacher@example.test',
                temporaryPassword: 'Temporary!123'
            );
    }

    public function test_center_owner_can_create_allowed_role_accounts_inside_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $roles = [
            SystemRole::BranchManager,
            SystemRole::FinanceEmployee,
            SystemRole::Teacher,
            SystemRole::Student,
        ];

        foreach (
            $roles as $index => $role
        ) {
            $account = $this->service()
                ->create(
                    actor: $centerOwner,
                    center: $center,
                    nationalIdNumber: '91000000' . ($index + 1),
                    role: $role,
                    accountLoginIdentifier: 'allowed.role.' . $index,
                    recoveryEmail: 'allowed'
                        . $index
                        . '@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->assertSame(
                $role,
                $account->systemRole()
            );

            $this->assertSame(
                $center->id,
                $account->center_id
            );
        }
    }

    public function test_center_owner_cannot_create_center_owner_account(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                actor: $centerOwner,
                center: $center,
                nationalIdNumber: '900000008',
                role: SystemRole::CenterOwner,
                accountLoginIdentifier: 'blocked.owner',
                recoveryEmail: 'blocked@example.test',
                temporaryPassword: 'Temporary!123'
            );
    }

    public function test_center_owner_cannot_create_account_in_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                actor: $centerOwner,
                center: $centerB,
                nationalIdNumber: '900000009',
                role: SystemRole::Teacher,
                accountLoginIdentifier: 'cross.center.teacher',
                recoveryEmail: 'teacher@example.test',
                temporaryPassword: 'Temporary!123'
            );
    }

    public function test_branch_manager_cannot_bypass_student_scope_through_generic_account_creation(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchManager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                actor: $branchManager,
                center: $center,
                nationalIdNumber: '900000010',
                role: SystemRole::Student,
                accountLoginIdentifier: 'branch.student',
                recoveryEmail: 'student@example.test',
                temporaryPassword: 'Temporary!123'
            );
    }

    public function test_center_owner_can_create_student_account_for_active_student(): void
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

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $account = $this->service()
            ->createStudentAccountForStudent(
                actor: $centerOwner,
                student: $student,
                accountLoginIdentifier: 'student.account.one',
                recoveryEmail: 'STUDENT@EXAMPLE.TEST',
                temporaryPassword: 'Temporary!123'
            );

        $student->refresh();

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $person->id,
            $account->person_id
        );

        $this->assertSame(
            SystemRole::Student,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'student.account.one',
            $account->account_login_identifier
        );

        $this->assertSame(
            'student@example.test',
            $account->recovery_email
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNull(
            $account->temporary_password_used_at
        );

        $this->assertTrue(
            Hash::check(
                'Temporary!123',
                $account->password
            )
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $accountAudit =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.created'
            )
            ->where(
                'subject_id',
                $account->id
            )
            ->firstOrFail();

        $this->assertSame(
            $centerOwner->id,
            $accountAudit->actor_user_id
        );

        $linkAudit =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'student.account_linked'
            )
            ->where(
                'subject_id',
                $student->id
            )
            ->firstOrFail();

        $this->assertNull(
            $linkAudit->before_values['user_id']
        );

        $this->assertSame(
            $account->id,
            $linkAudit->after_values['user_id']
        );
    }

    public function test_archived_student_cannot_receive_new_student_account(): void
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

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $userCount =
            User::withoutGlobalScopes()
            ->count();

        try {
            $this->service()
                ->createStudentAccountForStudent(
                    actor: $centerOwner,
                    student: $student,
                    accountLoginIdentifier: 'archived.student',
                    recoveryEmail: 'archived@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected Archived Student account creation to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'An Archived Student must be restored before creating a User Account.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertNull(
            $student->user_id
        );

        $this->assertSame(
            $userCount,
            User::withoutGlobalScopes()
                ->count()
        );
    }

    public function test_new_student_account_is_rejected_when_person_already_has_student_role_account(): void
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

        $existingAccount =
            $this->createCenterAccount(
                SystemRole::Student,
                $center,
                $person
            );

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $userCount =
            User::withoutGlobalScopes()
            ->count();

        try {
            $this->service()
                ->createStudentAccountForStudent(
                    actor: $centerOwner,
                    student: $student,
                    accountLoginIdentifier: 'duplicate.student',
                    recoveryEmail: 'duplicate@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected duplicate Student-role account creation to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'This Person already has a Student account in the language center.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertNull(
            $student->user_id
        );

        $this->assertDatabaseHas(
            'users',
            [
                'id' =>
                $existingAccount->id,
            ]
        );

        $this->assertSame(
            $userCount,
            User::withoutGlobalScopes()
                ->count()
        );
    }

    public function test_branch_manager_can_create_student_account_in_assigned_branch(): void
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

        $account = $this->service()
            ->createStudentAccountForStudent(
                actor: $manager,
                student: $student,
                accountLoginIdentifier: 'branch.student.one',
                recoveryEmail: 'branch.student@example.test',
                temporaryPassword: 'Temporary!123'
            );

        $student->refresh();

        $this->assertSame(
            SystemRole::Student,
            $account->systemRole()
        );

        $this->assertSame(
            $person->id,
            $account->person_id
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'branch_id' => $branch->id,
                'user_id' => $account->id,
            ]
        );
    }

    public function test_branch_manager_cannot_create_student_account_outside_assigned_branch(): void
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

        $userCount =
            User::withoutGlobalScopes()
            ->count();

        try {
            $this->service()
                ->createStudentAccountForStudent(
                    actor: $manager,
                    student: $student,
                    accountLoginIdentifier: 'outside.branch.student',
                    recoveryEmail: 'outside@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected cross-branch Student account creation to be rejected.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $student->refresh();

        $this->assertNull(
            $student->user_id
        );

        $this->assertSame(
            $userCount,
            User::withoutGlobalScopes()
                ->count()
        );
    }

    public function test_branch_manager_can_manage_linked_student_account_in_assigned_branch(): void
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

        $account = $this->createCenterAccount(
            SystemRole::Student,
            $center,
            $person
        );

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' => $account->id,
            ]);

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

        $updated = $this->service()
            ->update(
                $manager,
                $account,
                [
                    'account_login_identifier' =>
                    'managed.student',
                    'recovery_email' =>
                    'MANAGED@EXAMPLE.TEST',
                ]
            );

        $this->assertSame(
            'managed.student',
            $updated->account_login_identifier
        );

        $this->assertSame(
            'managed@example.test',
            $updated->recovery_email
        );

        $this->assertSame(
            $account->id,
            $student->user_id
        );
    }

    public function test_branch_manager_cannot_manage_linked_student_account_outside_assigned_branch(): void
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

        $account = $this->createCenterAccount(
            SystemRole::Student,
            $center,
            $person
        );

        Student::factory()
            ->forBranch($otherBranch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' => $account->id,
            ]);

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
            ->update(
                $manager,
                $account,
                [
                    'recovery_email' =>
                    'should.not.change@example.test',
                ]
            );
    }

    public function test_branch_manager_cannot_manage_unlinked_student_role_account(): void
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

        $account = $this->createCenterAccount(
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

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $manager,
                $account,
                [
                    'recovery_email' =>
                    'should.not.change@example.test',
                ]
            );
    }

    public function test_branch_manager_cannot_bypass_scope_by_tampering_with_student_branch_in_memory(): void
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

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        /*
     * Tamper only with the local Eloquent instance.
     *
     * Persisted Student still belongs to $otherBranch.
     */
        $student->branch_id =
            $assignedBranch->id;

        $this->establishBranchContext(
            $center,
            $assignedBranch
        );

        try {
            $this->service()
                ->createStudentAccountForStudent(
                    actor: $manager,
                    student: $student,
                    accountLoginIdentifier: 'tampered.branch.student',
                    recoveryEmail: 'tampered@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected persisted Student Branch scope to reject the tampered Student instance.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $persistedStudent =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $student->id
            );

        $this->assertSame(
            $otherBranch->id,
            $persistedStudent->branch_id
        );

        $this->assertNull(
            $persistedStudent->user_id
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'account_login_identifier' =>
                'tampered.branch.student',
            ]
        );
    }

    public function test_student_account_creation_and_link_roll_back_when_second_audit_fails(): void
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

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        /*
        * First Audit call represents user_account.created
        * and succeeds.
        *
        * Second Audit call represents student.account_linked
        * and fails.
        */
        $realAudit = app(
            AuditRecorder::class
        );

        $failingAudit = \Mockery::mock(
            AuditRecorder::class
        );

        /*
        * Let the first Audit call execute normally.
        *
        * Because it runs inside the same database transaction,
        * its persisted AuditRecord must also be rolled back
        * when the second Audit call fails.
        */
        $failingAudit
            ->shouldReceive('record')
            ->once()
            ->ordered()
            ->andReturnUsing(
                function (...$arguments) use (
                    $realAudit
                ): AuditRecord {
                    return $realAudit->record(
                        ...$arguments
                    );
                }
            );

        /*
        * Fail the second Audit call after the User Account
        * has been created and Student.user_id has been linked.
        */
        $failingAudit
            ->shouldReceive('record')
            ->once()
            ->ordered()
            ->andThrow(
                new LogicException(
                    'Simulated second audit failure.'
                )
            );

        $this->app->instance(
            AuditRecorder::class,
            $failingAudit
        );

        try {
            $this->service()
                ->createStudentAccountForStudent(
                    actor: $centerOwner,
                    student: $student,
                    accountLoginIdentifier: 'rollback.student.account',
                    recoveryEmail: 'rollback.student@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected second audit failure to abort Student account creation and linking.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated second audit failure.',
                $exception->getMessage()
            );
        }

        $student->refresh();

        $this->assertNull(
            $student->user_id
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'account_login_identifier' =>
                'rollback.student.account',
            ]
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'person_id' => $person->id,
                'branch_id' => $branch->id,
                'user_id' => null,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_branch_manager_can_deactivate_and_reactivate_linked_student_account_in_assigned_branch(): void
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

        $account = $this->createCenterAccount(
            SystemRole::Student,
            $center,
            $person
        );

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' => $account->id,
            ]);

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

        $deactivated = $this->service()
            ->deactivate(
                $manager,
                $account
            );

        $this->assertSame(
            AccountStatus::Deactivated,
            $deactivated->status
        );

        $this->assertNotNull(
            $deactivated->deactivated_at
        );

        $student->refresh();

        $this->assertSame(
            $account->id,
            $student->user_id
        );

        $activated = $this->service()
            ->activate(
                $manager,
                $deactivated
            );

        $this->assertSame(
            AccountStatus::Active,
            $activated->status
        );

        $this->assertNull(
            $activated->deactivated_at
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'user_id' => $account->id,
            ]
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.deactivated'
                )
                ->where(
                    'subject_id',
                    $account->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.activated'
                )
                ->where(
                    'subject_id',
                    $account->id
                )
                ->count()
        );
    }

    public function test_branch_manager_can_issue_temporary_password_for_linked_student_account_in_assigned_branch(): void
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

        $account = $this->createCenterAccount(
            SystemRole::Student,
            $center,
            $person
        );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' => $account->id,
            ]);

        $account->forceFill([
            'failed_login_attempts' => 4,
            'locked_until' => now()
                ->addMinutes(10),
            'must_change_password' => false,
        ])->save();

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

        $updated = $this->service()
            ->issueTemporaryPassword(
                $manager,
                $account,
                'BranchReset!123'
            );

        $this->assertTrue(
            Hash::check(
                'BranchReset!123',
                $updated->password
            )
        );

        $this->assertTrue(
            $updated->must_change_password
        );

        $this->assertNull(
            $updated->temporary_password_used_at
        );

        $this->assertSame(
            0,
            $updated->failed_login_attempts
        );

        $this->assertNull(
            $updated->locked_until
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.temporary_password_issued'
                )
                ->where(
                    'subject_id',
                    $account->id
                )
                ->count()
        );
    }

    public function test_branch_manager_cannot_manage_non_student_account_even_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $teacher = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
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

        $originalRecoveryEmail =
            $teacher->recovery_email;

        try {
            $this->service()
                ->update(
                    $manager,
                    $teacher,
                    [
                        'recovery_email' =>
                        'unauthorized@example.test',
                    ]
                );

            $this->fail(
                'Expected Branch Manager management of non-Student account to be rejected.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $teacher->refresh();

        $this->assertSame(
            $originalRecoveryEmail,
            $teacher->recovery_email
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }
    public function test_general_account_update_changes_only_allowed_identity_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center,
            $person
        );

        $originalRoleId = $account->role_id;
        $originalCenterId = $account->center_id;
        $originalPersonId = $account->person_id;
        $originalPassword = $account->password;

        $this->establishCenterContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $centerOwner,
                $account,
                [
                    'account_login_identifier' =>
                    'teacher.updated',

                    'recovery_email' =>
                    'UPDATED@EXAMPLE.TEST',

                    /*
                     * General update must ignore all of these.
                     */
                    'center_id' =>
                    Center::factory()
                        ->create()
                        ->id,

                    'person_id' =>
                    Person::factory()
                        ->for($center)
                        ->create()
                        ->id,

                    'role_id' =>
                    $this->role(
                        SystemRole::Student
                    )->id,

                    'status' =>
                    AccountStatus::Deactivated,

                    'password' =>
                    'malicious-password',

                    'must_change_password' => true,
                ]
            );

        $this->assertSame(
            'teacher.updated',
            $updated->account_login_identifier
        );

        $this->assertSame(
            'updated@example.test',
            $updated->recovery_email
        );

        $this->assertSame(
            $originalRoleId,
            $updated->role_id
        );

        $this->assertSame(
            $originalCenterId,
            $updated->center_id
        );

        $this->assertSame(
            $originalPersonId,
            $updated->person_id
        );

        $this->assertSame(
            AccountStatus::Active,
            $updated->status
        );

        $this->assertSame(
            $originalPassword,
            $updated->password
        );

        $this->assertFalse(
            $updated->must_change_password
        );
    }

    public function test_no_op_account_update_does_not_create_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->update(
                $centerOwner,
                $account,
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'recovery_email' =>
                    $account->recovery_email,
                ]
            );

        $this->assertSame(
            0,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.updated'
                )
                ->count()
        );
    }

    public function test_account_management_uses_persisted_state_instead_of_tampered_in_memory_state(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $persistedCenterId =
            $account->center_id;

        $persistedRoleId =
            $account->role_id;

        /*
         * Tamper only with the local Eloquent instance.
         */
        $account->center_id =
            $otherCenter->id;

        $account->role_id =
            $this->role(
                SystemRole::CenterOwner
            )->id;

        $this->establishCenterContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $centerOwner,
                $account,
                [
                    'recovery_email' =>
                    'persisted@example.test',
                ]
            );

        $this->assertSame(
            $persistedCenterId,
            $updated->center_id
        );

        $this->assertSame(
            $persistedRoleId,
            $updated->role_id
        );

        $this->assertSame(
            SystemRole::Teacher,
            $updated->systemRole()
        );

        $record =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.updated'
            )
            ->firstOrFail();

        $this->assertSame(
            $persistedCenterId,
            $record->before_values['center_id']
        );

        $this->assertSame(
            SystemRole::Teacher->value,
            $record->before_values['role']
        );
    }

    public function test_account_activate_and_deactivate_are_idempotent_and_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $service = $this->service();

        $account = $service->deactivate(
            $centerOwner,
            $account
        );

        $this->assertSame(
            AccountStatus::Deactivated,
            $account->status
        );

        $this->assertNotNull(
            $account->deactivated_at
        );

        $account = $service->deactivate(
            $centerOwner,
            $account
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.deactivated'
                )
                ->count()
        );

        $account = $service->activate(
            $centerOwner,
            $account
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );

        $service->activate(
            $centerOwner,
            $account
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.activated'
                )
                ->count()
        );

        $deactivatedRecord =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.deactivated'
            )
            ->firstOrFail();

        $activatedRecord =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.activated'
            )
            ->firstOrFail();

        $this->assertSame(
            AccountStatus::Active->value,
            $deactivatedRecord
                ->before_values['status']
        );

        $this->assertSame(
            AccountStatus::Deactivated->value,
            $deactivatedRecord
                ->after_values['status']
        );

        $this->assertSame(
            AccountStatus::Deactivated->value,
            $activatedRecord
                ->before_values['status']
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $activatedRecord
                ->after_values['status']
        );
    }

    public function test_deactivation_preserves_person_role_and_account_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::FinanceEmployee,
            $center
        );

        $personId = $account->person_id;
        $roleId = $account->role_id;

        $this->establishCenterContext(
            $center
        );

        $deactivated = $this->service()
            ->deactivate(
                $centerOwner,
                $account
            );

        $this->assertSame(
            $personId,
            $deactivated->person_id
        );

        $this->assertSame(
            $roleId,
            $deactivated->role_id
        );

        $this->assertDatabaseHas(
            'users',
            [
                'id' => $deactivated->id,
                'person_id' => $personId,
                'role_id' => $roleId,
                'status' =>
                AccountStatus::Deactivated->value,
            ]
        );

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $personId,
                'center_id' => $center->id,
            ]
        );
    }

    public function test_temporary_password_issue_resets_credential_security_state_and_is_audited(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $account->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' =>
            now()->addMinutes(10),
            'must_change_password' => false,
            'temporary_password_used_at' =>
            now(),
        ])->save();

        $oldPassword =
            $account->password;

        $this->establishCenterContext(
            $center
        );

        $account = $this->service()
            ->issueTemporaryPassword(
                $centerOwner,
                $account,
                'ResetTemporary!456'
            );

        $this->assertNotSame(
            $oldPassword,
            $account->password
        );

        $this->assertTrue(
            Hash::check(
                'ResetTemporary!456',
                $account->password
            )
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNull(
            $account->temporary_password_used_at
        );

        $this->assertSame(
            0,
            $account->failed_login_attempts
        );

        $this->assertNull(
            $account->locked_until
        );

        $record =
            AuditRecord::withoutGlobalScopes()
            ->where(
                'action_type',
                'user_account.temporary_password_issued'
            )
            ->firstOrFail();

        $this->assertSame(
            $account->id,
            $record->subject_id
        );

        $this->assertTrue(
            $record->after_values['credential_change_required']
        );

        $this->assertSame(
            0,
            $record->after_values['failed_login_attempts']
        );

        $this->assertArrayNotHasKey(
            'password',
            $record->after_values
        );

        $this->assertStringNotContainsString(
            'ResetTemporary!456',
            json_encode(
                $record->after_values
            )
        );
    }

    public function test_temporary_password_cannot_be_issued_for_deactivated_account(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center,
            status: AccountStatus::Deactivated
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->issueTemporaryPassword(
                $centerOwner,
                $account,
                'Temporary!123'
            );
    }

    public function test_account_creation_rolls_back_person_and_account_when_audit_fails(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->create();

        $this->establishPlatformContext();

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
                    actor: $platformOwner,
                    center: $center,
                    nationalIdNumber: '990000001',
                    role: SystemRole::CenterOwner,
                    accountLoginIdentifier: 'rollback.owner',
                    recoveryEmail: 'rollback@example.test',
                    temporaryPassword: 'Temporary!123'
                );

            $this->fail(
                'Expected audit failure to abort account creation.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'people',
            [
                'center_id' => $center->id,
                'national_id_number' =>
                '990000001',
            ]
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'account_login_identifier' =>
                'rollback.owner',
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_account_update_rolls_back_when_audit_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $originalIdentifier =
            $account->account_login_identifier;

        $originalRecoveryEmail =
            $account->recovery_email;

        $this->establishCenterContext(
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
                    $centerOwner,
                    $account,
                    [
                        'account_login_identifier' =>
                        'should.rollback',

                        'recovery_email' =>
                        'rollback@example.test',
                    ]
                );

            $this->fail(
                'Expected audit failure to abort account update.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'users',
            [
                'id' => $account->id,
                'account_login_identifier' =>
                $originalIdentifier,
                'recovery_email' =>
                $originalRecoveryEmail,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_account_lifecycle_change_rolls_back_when_audit_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $this->establishCenterContext(
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
                    $centerOwner,
                    $account
                );

            $this->fail(
                'Expected audit failure to abort account deactivation.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'users',
            [
                'id' => $account->id,
                'status' =>
                AccountStatus::Active->value,
                'deactivated_at' => null,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_temporary_password_issue_rolls_back_when_audit_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $account = $this->createCenterAccount(
            SystemRole::Teacher,
            $center
        );

        $originalPassword =
            $account->password;

        $this->establishCenterContext(
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
                ->issueTemporaryPassword(
                    $centerOwner,
                    $account,
                    'ShouldRollback!123'
                );

            $this->fail(
                'Expected audit failure to abort temporary password issuance.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $account->refresh();

        $this->assertSame(
            $originalPassword,
            $account->password
        );

        $this->assertFalse(
            $account->must_change_password
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_in_memory_actor_role_tampering_cannot_grant_account_creation_authority(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchManager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        /*
     * Persisted role is Branch Manager.
     *
     * Tamper only with the supplied Eloquent instance so it
     * appears to be a Center Owner.
     */
        $branchManager->role_id =
            $this->role(
                SystemRole::CenterOwner
            )->id;

        $branchManager->unsetRelation(
            'role'
        );

        $this->establishCenterContext(
            $center
        );

        $beforeUserCount =
            User::withoutGlobalScopes()
            ->count();

        try {
            $this->service()->create(
                actor: $branchManager,

                center: $center,

                nationalIdNumber: '989000001',

                role: SystemRole::Teacher,

                accountLoginIdentifier: 'tampered.actor.role',

                recoveryEmail: 'tampered.role@example.test',

                temporaryPassword: 'Temporary!123'
            );

            $this->fail(
                'Expected persisted Branch Manager role to reject generic Teacher account creation.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            $beforeUserCount,
            User::withoutGlobalScopes()
                ->count()
        );

        $persisted =
            User::withoutGlobalScopes()
            ->whereKey(
                $branchManager->id
            )
            ->firstOrFail();

        $this->assertSame(
            SystemRole::BranchManager,
            $persisted->systemRole()
        );
    }

    public function test_in_memory_actor_center_tampering_cannot_grant_cross_center_management(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $centerOwner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $teacher =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $centerB
            );

        $originalRecoveryEmail =
            $teacher->recovery_email;

        /*
     * Persisted actor belongs to Center A.
     *
     * Tamper only with the local model so it appears
     * to belong to Center B.
     */
        $centerOwner->center_id =
            $centerB->id;

        $this->establishCenterContext(
            $centerB
        );

        try {
            $this->service()->update(
                $centerOwner,
                $teacher,
                [
                    'recovery_email' =>
                    'cross.center@example.test',
                ]
            );

            $this->fail(
                'Expected persisted actor Center scope to reject cross-Center account management.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $teacher->refresh();

        $this->assertSame(
            $originalRecoveryEmail,
            $teacher->recovery_email
        );

        $persistedActor =
            User::withoutGlobalScopes()
            ->whereKey(
                $centerOwner->id
            )
            ->firstOrFail();

        $this->assertSame(
            $centerA->id,
            $persistedActor->center_id
        );
    }

    public function test_stale_in_memory_active_actor_cannot_manage_accounts_after_persisted_deactivation(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $centerOwner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $teacher =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $center
            );

        $originalPassword =
            $teacher->password;

        /*
     * Keep the supplied actor instance stale and Active,
     * while authoritative persisted state becomes
     * Deactivated.
     */
        User::withoutGlobalScopes()
            ->whereKey(
                $centerOwner->id
            )
            ->update([
                'status' =>
                AccountStatus::Deactivated,

                'deactivated_at' =>
                now(),
            ]);

        $this->assertSame(
            AccountStatus::Active,
            $centerOwner->status
        );

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->issueTemporaryPassword(
                    $centerOwner,
                    $teacher,
                    'ShouldNotBeIssued!123'
                );

            $this->fail(
                'Expected persisted deactivated actor state to reject account management.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $teacher->refresh();

        $this->assertSame(
            $originalPassword,
            $teacher->password
        );

        $this->assertFalse(
            Hash::check(
                'ShouldNotBeIssued!123',
                $teacher->password
            )
        );

        $this->assertSame(
            0,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'action_type',
                    'user_account.temporary_password_issued'
                )
                ->where(
                    'subject_id',
                    $teacher->id
                )
                ->count()
        );
    }

    private function service(): UserAccountManagementService
    {
        return app(
            UserAccountManagementService::class
        );
    }

    private function establishPlatformContext(): void
    {
        app(TenantContext::class)
            ->establishPlatformScope();
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
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
        ?Center $center = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role($role)->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' =>
                $this->role($role)->id,
                'status' =>
                AccountStatus::Active,
            ]);
    }

    private function createCenterAccount(
        SystemRole $role,
        Center $center,
        ?Person $person = null,
        AccountStatus $status = AccountStatus::Active
    ): User {
        $person ??= Person::factory()
            ->for($center)
            ->create();

        $user = User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' =>
                $this->role($role)->id,
                'status' => $status,
            ]);

        if (
            $status
            === AccountStatus::Deactivated
        ) {
            $user->forceFill([
                'deactivated_at' => now(),
            ])->save();

            $user->refresh();
        }

        return $user;
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
