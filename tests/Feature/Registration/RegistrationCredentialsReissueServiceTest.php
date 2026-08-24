<?php

namespace Tests\Feature\Registration;

use App\Mail\RegistrationCredentialsMail;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Services\Registration\RegistrationCredentialsReissueService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class RegistrationCredentialsReissueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_reissue_teacher_credentials(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $account =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $center,
                recoveryEmail: 'teacher.reissue@example.test'
            );

        $account->forceFill([
            'password' =>
            Hash::make(
                'OldPassword!123'
            ),

            'must_change_password' =>
            false,

            'temporary_password_used_at' =>
            now(),

            'failed_login_attempts' =>
            4,

            'locked_until' =>
            now()->addMinutes(10),
        ])->save();

        $oldPasswordHash =
            $account->password;

        $this->establishCenterContext(
            $center
        );

        $this->service()->reissue(
            $actor,
            $account
        );

        $account->refresh();

        $newTemporaryPassword =
            null;

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ) use (
                &$newTemporaryPassword,
                $account
            ): bool {
                $newTemporaryPassword =
                    $mail->temporaryPassword;

                return $mail->hasTo(
                    'teacher.reissue@example.test'
                )
                    && $mail
                    ->accountLoginIdentifier
                    === $account
                    ->account_login_identifier
                    && $mail
                    ->loginUrl
                    === route('login');
            }
        );

        Mail::assertNotQueued(
            RegistrationCredentialsMail::class
        );

        $this->assertNotNull(
            $newTemporaryPassword
        );

        $this->assertNotSame(
            $oldPasswordHash,
            $account->password
        );

        $this->assertFalse(
            Hash::check(
                'OldPassword!123',
                $account->password
            )
        );

        $this->assertTrue(
            Hash::check(
                $newTemporaryPassword,
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

    public function test_platform_owner_can_reissue_center_owner_credentials(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $account =
            $this->createCenterAccount(
                SystemRole::CenterOwner,
                $center,
                recoveryEmail: 'center.owner.reissue@example.test'
            );

        $this->establishPlatformContext();

        $this->service()->reissue(
            $actor,
            $account
        );

        $account->refresh();

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ) use ($account): bool {
                return $mail->hasTo(
                    'center.owner.reissue@example.test'
                )
                    && $mail
                    ->accountLoginIdentifier
                    === $account
                    ->account_login_identifier
                    && Hash::check(
                        $mail->temporaryPassword,
                        $account->password
                    );
            }
        );

        $this->assertTrue(
            $account->must_change_password
        );
    }

    public function test_branch_manager_can_reissue_student_credentials_in_assigned_branch(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->createCenterAccount(
                SystemRole::Student,
                $center,
                $person,
                recoveryEmail: 'student.reissue@example.test'
            );

        Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->establishBranchContext(
            $center,
            $branch
        );

        $this->service()->reissue(
            $manager,
            $account
        );

        $account->refresh();

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ) use ($account): bool {
                return $mail->hasTo(
                    'student.reissue@example.test'
                )
                    && Hash::check(
                        $mail->temporaryPassword,
                        $account->password
                    );
            }
        );

        $this->assertTrue(
            $account->must_change_password
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

    public function test_branch_manager_cannot_reissue_student_credentials_outside_assigned_branch(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $assignedBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->createCenterAccount(
                SystemRole::Student,
                $center,
                $person,
                recoveryEmail: 'outside.branch@example.test'
            );

        Student::factory()
            ->forBranch(
                $otherBranch
            )
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $originalPassword =
            $account->password;

        $this->establishBranchContext(
            $center,
            $assignedBranch
        );

        try {
            $this->service()->reissue(
                $manager,
                $account
            );

            $this->fail(
                'Expected cross-Branch credential reissue to be rejected.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $account->refresh();

        $this->assertSame(
            $originalPassword,
            $account->password
        );

        Mail::assertNothingSent();

        $this->assertSame(
            0,
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

    public function test_deactivated_account_credentials_cannot_be_reissued(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $account =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $center,
                status: AccountStatus::Deactivated,
                recoveryEmail: 'deactivated@example.test'
            );

        $originalPassword =
            $account->password;

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()->reissue(
                $actor,
                $account
            );

            $this->fail(
                'Expected deactivated account credential reissue to be rejected.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $account->refresh();

        $this->assertSame(
            $originalPassword,
            $account->password
        );

        Mail::assertNothingSent();
    }

    public function test_unauthorized_reissue_does_not_change_password_or_send_email(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $teacher =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $center,
                recoveryEmail: 'teacher@example.test'
            );

        $originalPassword =
            $teacher->password;

        $this->establishBranchContext(
            $center,
            $branch
        );

        try {
            $this->service()->reissue(
                $manager,
                $teacher
            );

            $this->fail(
                'Expected Branch Manager reissue of Teacher credentials to be rejected.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $teacher->refresh();

        $this->assertSame(
            $originalPassword,
            $teacher->password
        );

        Mail::assertNothingSent();
    }

    public function test_delivery_failure_does_not_roll_back_new_temporary_password(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $account =
            $this->createCenterAccount(
                SystemRole::Teacher,
                $center,
                recoveryEmail: 'delivery.failure@example.test'
            );

        $originalPassword =
            $account->password;

        $this->establishCenterContext(
            $center
        );

        $issuedTemporaryPassword =
            null;

        $failingDelivery =
            \Mockery::mock(
                RegistrationCredentialsDeliveryService::class
            );

        $failingDelivery
            ->shouldReceive(
                'deliverAccountCredentials'
            )
            ->once()
            ->withArgs(
                function (
                    User $deliveredAccount,
                    string $temporaryPassword
                ) use (
                    $account,
                    &$issuedTemporaryPassword
                ): bool {
                    $issuedTemporaryPassword =
                        $temporaryPassword;

                    return $deliveredAccount->id
                        === $account->id
                        && $temporaryPassword !== '';
                }
            )
            ->andThrow(
                new LogicException(
                    'Simulated credential email failure.'
                )
            );

        $service =
            new RegistrationCredentialsReissueService(
                app(
                    UserAccountManagementService::class
                ),
                $failingDelivery
            );

        try {
            $service->reissue(
                $actor,
                $account
            );

            $this->fail(
                'Expected simulated credential email failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated credential email failure.',
                $exception->getMessage()
            );
        }

        $account->refresh();

        $this->assertNotNull(
            $issuedTemporaryPassword
        );

        $this->assertNotSame(
            $originalPassword,
            $account->password
        );

        $this->assertTrue(
            Hash::check(
                $issuedTemporaryPassword,
                $account->password
            )
        );

        $this->assertTrue(
            $account->must_change_password
        );

        /*
         * Password issuance committed successfully before
         * the external mail delivery failed.
         */
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

    private function service(): RegistrationCredentialsReissueService
    {
        return app(
            RegistrationCredentialsReissueService::class
        );
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

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
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
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this->role(
                        $role
                    )->id,

                    'status' =>
                    AccountStatus::Active,

                    'account_login_identifier' =>
                    'platform.reissue.actor',

                    'recovery_email' =>
                    'platform@example.test',
                ]);
        }

        $center ??=
            Center::factory()
            ->active()
            ->create();

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

                'account_login_identifier' =>
                'actor.' . $role->value,

                'recovery_email' =>
                'actor.' . $role->value
                    . '@example.test',
            ]);
    }

    private function createCenterAccount(
        SystemRole $role,
        Center $center,
        ?Person $person = null,
        AccountStatus $status = AccountStatus::Active,
        string $recoveryEmail = 'target@example.test'
    ): User {
        $person ??=
            Person::factory()
            ->for($center)
            ->create();

        $account =
            User::factory()
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
                $status,

                'account_login_identifier' =>
                'target.' . $role->value,

                'recovery_email' =>
                $recoveryEmail,
            ]);

        if (
            $status
            === AccountStatus::Deactivated
        ) {
            $account->forceFill([
                'deactivated_at' =>
                now(),
            ])->save();

            $account->refresh();
        }

        return $account;
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
