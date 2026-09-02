<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordRecoveryCodeMail;
use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_is_available(): void
    {
        $response = $this->get(
            route('password.request')
        );

        $response
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page->component(
                    'Auth/ForgotPassword'
                )
            );
    }

    public function test_active_account_can_request_recovery_code(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $response = $this->post(
            route('password.recovery.send'),
            [
                'account_login_identifier' =>
                $user->account_login_identifier,
            ]
        );

        $response
            ->assertRedirect(
                route(
                    'password.recovery.verify',
                    absolute: false
                )
            )
            ->assertSessionHas(
                'password_recovery_user_id',
                $user->id
            )
            ->assertSessionHas(
                'password_recovery_email'
            );

        $this->assertDatabaseHas(
            'password_recovery_challenges',
            [
                'user_id' => $user->id,
                'attempts' => 0,
                'max_attempts' => 5,
            ]
        );

        Mail::assertSent(
            PasswordRecoveryCodeMail::class,
            function (
                PasswordRecoveryCodeMail $mail
            ) use ($user): bool {
                return $mail->hasTo(
                    $user->recovery_email
                )
                    && preg_match(
                        '/^\d{5}$/',
                        $mail->verificationCode
                    ) === 1;
            }
        );
    }

    public function test_unknown_account_cannot_request_recovery_code(): void
    {
        Mail::fake();

        $response = $this->from(
            route('password.request')
        )->post(
            route('password.recovery.send'),
            [
                'account_login_identifier' =>
                'unknown-account-id',
            ]
        );

        $response
            ->assertRedirect(
                route('password.request')
            )
            ->assertSessionHasErrors(
                'account_login_identifier'
            );

        Mail::assertNothingSent();

        $this->assertDatabaseCount(
            'password_recovery_challenges',
            0
        );
    }

    public function test_deactivated_account_cannot_request_recovery_code(): void
    {
        Mail::fake();

        $user = User::factory()
            ->deactivated()
            ->create([
                'account_login_identifier' =>
                'deactivated.account',

                'recovery_email' =>
                'deactivated@example.test',
            ]);

        $response = $this->from(
            route('password.request')
        )->post(
            route('password.recovery.send'),
            [
                'account_login_identifier' =>
                $user->account_login_identifier,
            ]
        );

        $response
            ->assertRedirect(
                route('password.request')
            )
            ->assertSessionHasErrors(
                'account_login_identifier'
            );

        Mail::assertNothingSent();

        $this->assertDatabaseMissing(
            'password_recovery_challenges',
            [
                'user_id' => $user->id,
            ]
        );
    }

    public function test_verification_page_requires_recovery_session(): void
    {
        $response = $this->get(
            route(
                'password.recovery.verify'
            )
        );

        $response->assertRedirect(
            route(
                'password.request',
                absolute: false
            )
        );
    }

    public function test_verification_page_displays_masked_email(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $this->startRecovery(
            $user
        );

        $response = $this->get(
            route(
                'password.recovery.verify'
            )
        );

        $response
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page
                    ->component(
                        'Auth/VerifyRecoveryCode'
                    )
                    ->has('maskedEmail')
            );
    }

    public function test_correct_code_opens_password_reset_flow(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $code = $this->startRecovery(
            $user
        );

        $response = $this->post(
            route(
                'password.recovery.verify.submit'
            ),
            [
                'code' => $code,
            ]
        );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $response
            ->assertRedirect(
                route(
                    'password.recovery.reset',
                    absolute: false
                )
            )
            ->assertSessionHas(
                'password_recovery_challenge_id',
                $challenge->id
            );

        $this->assertNotNull(
            $challenge->fresh()->verified_at
        );

        $this->get(
            route(
                'password.recovery.reset'
            )
        )
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page
                    ->component(
                        'Auth/ResetPassword'
                    )
                    ->where(
                        'passwordResetSuccessful',
                        false
                    )
            );
    }

    public function test_incorrect_code_is_rejected_and_attempt_is_recorded(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $correctCode =
            $this->startRecovery(
                $user
            );

        $response = $this->from(
            route(
                'password.recovery.verify'
            )
        )->post(
            route(
                'password.recovery.verify.submit'
            ),
            [
                'code' =>
                $this->differentCode(
                    $correctCode
                ),
            ]
        );

        $response
            ->assertRedirect(
                route(
                    'password.recovery.verify'
                )
            )
            ->assertSessionHasErrors(
                'code'
            );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            1,
            $challenge->attempts
        );

        $this->assertNull(
            $challenge->verified_at
        );
    }

    public function test_five_incorrect_codes_exhaust_recovery_challenge(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $correctCode =
            $this->startRecovery(
                $user
            );

        $wrongCode =
            $this->differentCode(
                $correctCode
            );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(
                route(
                    'password.recovery.verify.submit'
                ),
                [
                    'code' => $wrongCode,
                ]
            )->assertSessionHasErrors(
                'code'
            );
        }

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            5,
            $challenge->attempts
        );

        $this->assertTrue(
            $challenge
                ->expires_at
                ->lte(now())
        );

        $this->post(
            route(
                'password.recovery.verify.submit'
            ),
            [
                'code' => $correctCode,
            ]
        )->assertSessionHasErrors(
            'code'
        );
    }

    public function test_expired_code_cannot_be_verified(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $code = $this->startRecovery(
            $user
        );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $challenge->forceFill([
            'expires_at' =>
            now()->subSecond(),
        ])->save();

        $this->post(
            route(
                'password.recovery.verify.submit'
            ),
            [
                'code' => $code,
            ]
        )->assertSessionHasErrors(
            'code'
        );

        $this->assertNull(
            $challenge->fresh()->verified_at
        );
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $this->startRecovery(
            $user
        );

        $response = $this->post(
            route(
                'password.recovery.resend'
            )
        );

        $response->assertSessionHasErrors(
            'code'
        );

        Mail::assertSentCount(1);

        $this->assertSame(
            1,
            PasswordRecoveryChallenge::query()
                ->where(
                    'user_id',
                    $user->id
                )
                ->count()
        );
    }

    public function test_resend_after_cooldown_creates_new_code(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $this->startRecovery(
            $user
        );

        $firstChallenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->travel(
            61
        )->seconds();

        $response = $this->post(
            route(
                'password.recovery.resend'
            )
        );

        $response->assertSessionDoesntHaveErrors();

        Mail::assertSentCount(2);

        $secondChallenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertNotSame(
            $firstChallenge->id,
            $secondChallenge->id
        );

        $this->assertTrue(
            $firstChallenge
                ->fresh()
                ->expires_at
                ->lte(now())
        );
    }

    public function test_reset_screen_requires_verified_recovery_session(): void
    {
        $response = $this->get(
            route(
                'password.recovery.reset'
            )
        );

        $response->assertRedirect(
            route(
                'password.request',
                absolute: false
            )
        );
    }

    public function test_verified_account_can_set_new_password(): void
    {
        Mail::fake();

        $oldPassword =
            'OldPassword123!';

        $newPassword =
            'NewPassword456!';

        $user = $this->recoveryUser(
            $oldPassword
        );

        $code = $this->startRecovery(
            $user
        );

        $this->verifyRecoveryCode(
            $code
        );

        $response = $this->post(
            route(
                'password.recovery.reset.store'
            ),
            [
                'password' =>
                $newPassword,

                'password_confirmation' =>
                $newPassword,
            ]
        );

        $response->assertRedirect(
            route(
                'password.recovery.reset',
                absolute: false
            )
        );

        $user->refresh();

        $this->assertTrue(
            Hash::check(
                $newPassword,
                $user->password
            )
        );

        $this->assertFalse(
            Hash::check(
                $oldPassword,
                $user->password
            )
        );

        $this->assertFalse(
            $user->must_change_password
        );

        $this->assertSame(
            0,
            $user->failed_login_attempts
        );

        $this->assertNull(
            $user->locked_until
        );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull(
            $challenge->used_at
        );
    }

    public function test_successful_reset_invalidates_existing_user_sessions(): void
    {
        Mail::fake();

        $user = $this->recoveryUser(
            'OldPassword123!'
        );

        $sessionId =
            'recovery-existing-session';

        DB::connection(
            config('session.connection')
        )
            ->table(
                config(
                    'session.table',
                    'sessions'
                )
            )
            ->insert([
                'id' =>
                $sessionId,

                'user_id' =>
                $user->id,

                'ip_address' =>
                '127.0.0.1',

                'user_agent' =>
                'Password Recovery Test',

                'payload' =>
                base64_encode(
                    'test-session'
                ),

                'last_activity' =>
                now()->timestamp,
            ]);

        $code = $this->startRecovery(
            $user
        );

        $this->verifyRecoveryCode(
            $code
        );

        $this->post(
            route(
                'password.recovery.reset.store'
            ),
            [
                'password' =>
                'NewPassword456!',

                'password_confirmation' =>
                'NewPassword456!',
            ]
        )->assertRedirect(
            route(
                'password.recovery.reset',
                absolute: false
            )
        );

        $this->assertDatabaseMissing(
            config(
                'session.table',
                'sessions'
            ),
            [
                'id' => $sessionId,
                'user_id' => $user->id,
            ],
            config('session.connection')
        );
    }

    public function test_successful_reset_rotates_remember_token(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $user->forceFill([
            'remember_token' =>
            'known-remember-token',
        ])->save();

        $code = $this->startRecovery(
            $user
        );

        $this->verifyRecoveryCode(
            $code
        );

        $this->post(
            route(
                'password.recovery.reset.store'
            ),
            [
                'password' =>
                'NewPassword456!',

                'password_confirmation' =>
                'NewPassword456!',
            ]
        );

        $this->assertNotSame(
            'known-remember-token',
            $user->fresh()->remember_token
        );
    }

    public function test_used_challenge_cannot_be_reused(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        $code = $this->startRecovery(
            $user
        );

        $this->verifyRecoveryCode(
            $code
        );

        $this->post(
            route(
                'password.recovery.reset.store'
            ),
            [
                'password' =>
                'NewPassword456!',

                'password_confirmation' =>
                'NewPassword456!',
            ]
        )->assertRedirect(
            route(
                'password.recovery.reset',
                absolute: false
            )
        );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull(
            $challenge->used_at
        );

        /*
     * The first request after a successful reset
     * intentionally displays the success screen.
     */
        $this->get(
            route(
                'password.recovery.reset'
            )
        )
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page
                    ->component(
                        'Auth/ResetPassword'
                    )
                    ->where(
                        'passwordResetSuccessful',
                        true
                    )
            );

        /*
        * The recovery session was already cleared.
        * Once the one-request success flash is consumed,
        * the used challenge cannot reopen the reset flow.
        */
        $this->get(
            route(
                'password.recovery.reset'
            )
        )->assertRedirect(
            route(
                'password.request',
                absolute: false
            )
        );
    }

    public function test_recovery_code_requests_are_rate_limited(): void
    {
        Mail::fake();

        $user = $this->recoveryUser();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post(
                route(
                    'password.recovery.send'
                ),
                [
                    'account_login_identifier' =>
                    $user->account_login_identifier,
                ]
            )->assertRedirect(
                route(
                    'password.recovery.verify',
                    absolute: false
                )
            );
        }

        $this->post(
            route(
                'password.recovery.send'
            ),
            [
                'account_login_identifier' =>
                $user->account_login_identifier,
            ]
        )->assertStatus(429);

        Mail::assertSent(
            PasswordRecoveryCodeMail::class,
            6
        );
    }

    private function recoveryUser(
        string $password = 'Password123!'
    ): User {
        return User::factory()->create([
            'account_login_identifier' =>
            '01234567',

            'recovery_email' =>
            'recovery@example.test',

            'status' =>
            AccountStatus::Active,

            'password' =>
            $password,
        ]);
    }

    private function startRecovery(
        User $user
    ): string {
        $verificationCode = null;

        $this->post(
            route(
                'password.recovery.send'
            ),
            [
                'account_login_identifier' =>
                $user->account_login_identifier,
            ]
        )->assertRedirect(
            route(
                'password.recovery.verify',
                absolute: false
            )
        );

        Mail::assertSent(
            PasswordRecoveryCodeMail::class,
            function (
                PasswordRecoveryCodeMail $mail
            ) use (
                $user,
                &$verificationCode
            ): bool {
                if (
                    ! $mail->hasTo(
                        $user->recovery_email
                    )
                ) {
                    return false;
                }

                $verificationCode =
                    $mail->verificationCode;

                return true;
            }
        );

        $this->assertNotNull(
            $verificationCode
        );

        return $verificationCode;
    }

    private function verifyRecoveryCode(
        string $code
    ): void {
        $this->post(
            route(
                'password.recovery.verify.submit'
            ),
            [
                'code' => $code,
            ]
        )->assertRedirect(
            route(
                'password.recovery.reset',
                absolute: false
            )
        );
    }

    private function differentCode(
        string $code
    ): string {
        return $code === '00000'
            ? '00001'
            : '00000';
    }
}
