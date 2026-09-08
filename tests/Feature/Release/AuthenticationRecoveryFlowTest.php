<?php

namespace Tests\Feature\Release;

use App\Mail\RegistrationCredentialsMail;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PasswordRecoveryService;
use App\Services\Registration\RegistrationApprovalService;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Services\Registration\RegistrationReviewService;
use App\Services\Registration\RegistrationSubmissionService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthenticationRecoveryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_approved_student_can_complete_full_credential_and_recovery_lifecycle(): void
    {
        Mail::fake();

        /*
         * ---------------------------------------------------------
         * 1. CENTER / BRANCH / APPROVING ACTOR
         * ---------------------------------------------------------
         */

        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '42',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $ownerPerson =
            Person::factory()
            ->for($center)
            ->create();

        $owner =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $ownerPerson->id,

                'role_id' =>
                $this->role(
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);

        /*
         * ---------------------------------------------------------
         * 2. PUBLIC REGISTRATION
         * ---------------------------------------------------------
         */

        $registration =
            app(
                RegistrationSubmissionService::class
            )->submit(
                $center,
                [
                    'national_id_number' =>
                    '900000002',

                    'full_name' =>
                    'Release Auth Student',

                    'date_of_birth' =>
                    '2002-06-10',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'release.auth.student@example.test',

                    'phone_number' =>
                    '+970599000002',
                ]
            );

        /*
         * ---------------------------------------------------------
         * 3. ADMINISTRATIVE REVIEW
         * ---------------------------------------------------------
         */

        $this->establishCenterOwnerContext(
            $center
        );

        $registration =
            app(
                RegistrationReviewService::class
            )->selectRole(
                $owner,
                $registration,
                SystemRole::Student
            );

        $registration =
            app(
                RegistrationReviewService::class
            )->selectBranch(
                $owner,
                $registration,
                $branch
            );

        /*
         * ---------------------------------------------------------
         * 4. APPROVAL + TEMPORARY CREDENTIAL
         * ---------------------------------------------------------
         */

        $approval =
            app(
                RegistrationApprovalService::class
            )->approve(
                $owner,
                $registration
            );

        $account =
            $approval->account;

        $temporaryPassword =
            $approval->temporaryPassword;

        $this->assertSame(
            SystemRole::Student,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'release.auth.student@example.test',
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
                $temporaryPassword,
                $account->password
            )
        );

        /*
         * ---------------------------------------------------------
         * 5. CREDENTIAL DELIVERY
         * ---------------------------------------------------------
         */

        app(
            RegistrationCredentialsDeliveryService::class
        )->deliver(
            $approval
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ) use (
                $account,
                $temporaryPassword
            ): bool {
                return $mail->hasTo(
                    $account->recovery_email
                )
                    && $mail
                    ->accountLoginIdentifier
                    ===
                    $account
                    ->account_login_identifier

                    && $mail
                    ->temporaryPassword
                    ===
                    $temporaryPassword

                    && $mail
                    ->loginUrl
                    ===
                    route('login');
            }
        );

        Mail::assertNotQueued(
            RegistrationCredentialsMail::class
        );

        /*
         * ---------------------------------------------------------
         * 6. FIRST LOGIN WITH TEMPORARY PASSWORD
         * ---------------------------------------------------------
         */

        $loginResponse =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'password' =>
                    $temporaryPassword,
                ]
            );

        $this->assertAuthenticatedAs(
            $account
        );

        $loginResponse->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
        );

        $account->refresh();

        /*
         * The temporary password is now consumed.
         *
         * The authenticated session remains available only for
         * completion of the forced password-change lifecycle.
         */
        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNotNull(
            $account->temporary_password_used_at
        );

        /*
         * Operational access remains blocked until the permanent
         * password has been configured.
         */
        $this->get(
            '/dashboard'
        )->assertRedirect(
            route(
                'profile.edit',
                absolute: false
            )
        );

        /*
         * ---------------------------------------------------------
         * 7. FORCED PERMANENT PASSWORD CHANGE
         * ---------------------------------------------------------
         */

        $permanentPassword =
            'PermanentPass456!';

        $passwordChangeResponse =
            $this
            ->from('/profile')
            ->put(
                '/password',
                [
                    'current_password' =>
                    $temporaryPassword,

                    'password' =>
                    $permanentPassword,

                    'password_confirmation' =>
                    $permanentPassword,
                ]
            );

        $passwordChangeResponse
            ->assertSessionHasNoErrors();

        /*
         * Completing a forced password change requires a fresh
         * authentication session using the new password.
         */
        $this->assertGuest();

        $account->refresh();

        $this->assertFalse(
            $account->must_change_password
        );

        $this->assertNull(
            $account->temporary_password_used_at
        );

        $this->assertNotNull(
            $account->password_changed_at
        );

        $this->assertTrue(
            Hash::check(
                $permanentPassword,
                $account->password
            )
        );

        /*
         * ---------------------------------------------------------
         * 8. OLD TEMPORARY PASSWORD CANNOT AUTHENTICATE
         * ---------------------------------------------------------
         */

        $oldTemporaryLogin =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'password' =>
                    $temporaryPassword,
                ]
            );

        $oldTemporaryLogin
            ->assertSessionHasErrors(
                'account_login_identifier'
            );

        $this->assertGuest();

        /*
         * ---------------------------------------------------------
         * 9. NEW PERMANENT PASSWORD AUTHENTICATES
         * ---------------------------------------------------------
         */

        $permanentLogin =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'password' =>
                    $permanentPassword,
                ]
            );

        $this->assertAuthenticatedAs(
            $account
        );

        $permanentLogin->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
        );

        /*
         * Password recovery starts from a guest session.
         */
        $this->post(
            '/logout'
        );

        $this->assertGuest();

        /*
         * ---------------------------------------------------------
         * 10. PASSWORD RECOVERY CHALLENGE
         * ---------------------------------------------------------
         */

        $recoveryService =
            app(
                PasswordRecoveryService::class
            );

        $recovery =
            $recoveryService->start(
                $account
                    ->account_login_identifier
            );

        $this->assertSame(
            $account->id,
            $recovery['user']->id
        );

        $this->assertMatchesRegularExpression(
            '/^\d{5}$/',
            $recovery['code']
        );

        /*
         * Plaintext OTP must never be stored directly.
         */
        $this->assertNotSame(
            $recovery['code'],
            $recovery['challenge']
                ->code_hash
        );

        $this->assertSame(
            0,
            $recovery['challenge']
                ->attempts
        );

        $this->assertSame(
            5,
            $recovery['challenge']
                ->max_attempts
        );

        /*
         * ---------------------------------------------------------
         * 11. VERIFY THE 5-DIGIT CODE
         * ---------------------------------------------------------
         */

        $challenge =
            $recoveryService->verify(
                $account->id,
                $recovery['code']
            );

        $this->assertTrue(
            $challenge->isVerified()
        );

        $this->assertFalse(
            $challenge->isUsed()
        );

        /*
         * ---------------------------------------------------------
         * 12. RESET PASSWORD USING VERIFIED CHALLENGE
         * ---------------------------------------------------------
         */

        $recoveredPassword =
            'RecoveredPass789!';

        $account =
            $recoveryService
            ->resetPassword(
                $challenge->id,
                $account->id,
                $recoveredPassword
            );

        $account->refresh();

        $challenge->refresh();

        $this->assertTrue(
            Hash::check(
                $recoveredPassword,
                $account->password
            )
        );

        $this->assertTrue(
            $challenge->isUsed()
        );

        /*
         * ---------------------------------------------------------
         * 13. PREVIOUS PERMANENT PASSWORD IS INVALID
         * ---------------------------------------------------------
         */

        $oldPermanentLogin =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'password' =>
                    $permanentPassword,
                ]
            );

        $oldPermanentLogin
            ->assertSessionHasErrors(
                'account_login_identifier'
            );

        $this->assertGuest();

        /*
         * ---------------------------------------------------------
         * 14. RECOVERED PASSWORD AUTHENTICATES
         * ---------------------------------------------------------
         */

        $recoveredLogin =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $account
                        ->account_login_identifier,

                    'password' =>
                    $recoveredPassword,
                ]
            );

        $this->assertAuthenticatedAs(
            $account
        );

        $recoveredLogin->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
        );
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

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->establishCenterWideScope();
    }
}