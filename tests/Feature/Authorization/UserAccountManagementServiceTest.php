<?php

namespace Tests\Feature\Authorization;

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

    public function test_branch_manager_student_account_management_fails_closed_until_student_branch_scope_exists(): void
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
