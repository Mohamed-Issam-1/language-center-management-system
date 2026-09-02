<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use App\Services\Auth\PasswordRecoveryService;
use App\Support\Enums\AccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private PasswordRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service =
            app(
                PasswordRecoveryService::class
            );
    }

    public function test_recovery_challenge_can_be_started_for_active_account(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $this->assertSame(
            $user->id,
            $result['user']->id
        );

        $this->assertMatchesRegularExpression(
            '/^\d{5}$/',
            $result['code']
        );

        $this->assertNotSame(
            $result['code'],
            $result['challenge']->code_hash
        );

        $this->assertSame(
            0,
            $result['challenge']->attempts
        );

        $this->assertSame(
            5,
            $result['challenge']->max_attempts
        );

        $this->assertDatabaseHas(
            'password_recovery_challenges',
            [
                'user_id' => $user->id,
                'attempts' => 0,
                'max_attempts' => 5,
            ]
        );
    }

    public function test_unknown_account_cannot_start_recovery(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->service->start(
            'unknown-account'
        );
    }

    public function test_deactivated_account_cannot_start_recovery(): void
    {
        $user = User::factory()
            ->deactivated()
            ->create([
                'account_login_identifier' =>
                'deactivated.recovery',

                'recovery_email' =>
                'deactivated@example.test',
            ]);

        $this->expectException(
            ValidationException::class
        );

        $this->service->start(
            $user->account_login_identifier
        );
    }

    public function test_pending_account_cannot_start_recovery(): void
    {
        $user = User::factory()
            ->pending()
            ->create([
                'account_login_identifier' =>
                'pending.recovery',

                'recovery_email' =>
                'pending@example.test',
            ]);

        $this->expectException(
            ValidationException::class
        );

        $this->service->start(
            $user->account_login_identifier
        );
    }

    public function test_account_with_blank_recovery_email_cannot_start_recovery(): void
    {
        $user = User::factory()->create([
            'account_login_identifier' =>
            'without.recovery.email',

            'recovery_email' => '',

            'status' =>
            AccountStatus::Active,
        ]);

        $this->expectException(
            ValidationException::class
        );

        $this->service->start(
            $user->account_login_identifier
        );
    }

    public function test_starting_new_recovery_invalidates_previous_active_challenge(): void
    {
        $user = $this->activeUser();

        $first =
            $this->service->start(
                $user->account_login_identifier
            );

        $second =
            $this->service->start(
                $user->account_login_identifier
            );

        $firstChallenge =
            $first['challenge']->fresh();

        $this->assertTrue(
            $firstChallenge
                ->expires_at
                ->lte(now())
        );

        $this->assertFalse(
            $second['challenge']
                ->expires_at
                ->isPast()
        );
    }

    public function test_correct_code_verifies_challenge(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $challenge =
            $this->service->verify(
                $user->id,
                $result['code']
            );

        $this->assertNotNull(
            $challenge->verified_at
        );

        $this->assertNull(
            $challenge->used_at
        );
    }

    public function test_different_code_cannot_reuse_already_verified_challenge(): void
    {
        $user = User::factory()->create([
            'account_login_identifier' =>
            'verified-code-reuse',

            'recovery_email' =>
            'verified@example.test',

            'status' =>
            AccountStatus::Active,
        ]);

        $result = $this->service->start(
            $user->account_login_identifier
        );

        $this->service->verify(
            $user->id,
            $result['code']
        );

        $wrongCode =
            $result['code'] === '00000'
            ? '00001'
            : '00000';

        try {
            $this->service->verify(
                $user->id,
                $wrongCode
            );

            $this->fail(
                'A different code must not reuse an already verified challenge.'
            );
        } catch (ValidationException) {
            //
        }

        $challenge =
            $result['challenge']->fresh();

        $this->assertNotNull(
            $challenge->verified_at
        );

        $this->assertSame(
            1,
            $challenge->attempts
        );
    }

    public function test_incorrect_code_increments_attempt_counter(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        try {
            $this->service->verify(
                $user->id,
                $this->differentCode(
                    $result['code']
                )
            );

            $this->fail(
                'Expected validation exception.'
            );
        } catch (ValidationException) {
            //
        }

        $challenge =
            $result['challenge']
            ->fresh();

        $this->assertSame(
            1,
            $challenge->attempts
        );

        $this->assertNull(
            $challenge->verified_at
        );
    }

    public function test_five_incorrect_attempts_exhaust_challenge(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $wrongCode =
            $this->differentCode(
                $result['code']
            );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->service->verify(
                    $user->id,
                    $wrongCode
                );
            } catch (ValidationException) {
                //
            }
        }

        $challenge =
            $result['challenge']
            ->fresh();

        $this->assertSame(
            5,
            $challenge->attempts
        );

        $this->assertTrue(
            $challenge
                ->expires_at
                ->lte(now())
        );

        $this->assertNull(
            $challenge->verified_at
        );
    }

    public function test_expired_code_cannot_be_verified(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $result['challenge']
            ->forceFill([
                'expires_at' =>
                now()->subSecond(),
            ])
            ->save();

        $this->expectException(
            ValidationException::class
        );

        $this->service->verify(
            $user->id,
            $result['code']
        );
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        $user = $this->activeUser();

        $this->service->start(
            $user->account_login_identifier
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->resend(
            $user->id
        );
    }

    public function test_resend_after_cooldown_invalidates_old_challenge(): void
    {
        $user = $this->activeUser();

        $first =
            $this->service->start(
                $user->account_login_identifier
            );

        $this->travel(
            61
        )->seconds();

        $second =
            $this->service->resend(
                $user->id
            );

        $this->assertTrue(
            $first['challenge']
                ->fresh()
                ->expires_at
                ->lte(now())
        );

        $this->assertNotSame(
            $first['challenge']->id,
            $second['challenge']->id
        );
    }

    public function test_verified_challenge_can_be_resolved_by_user_and_id(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $verified =
            $this->service->verify(
                $user->id,
                $result['code']
            );

        $resolved =
            $this->service
            ->verifiedChallenge(
                $verified->id,
                $user->id
            );

        $this->assertNotNull(
            $resolved
        );

        $this->assertSame(
            $verified->id,
            $resolved->id
        );
    }

    public function test_used_challenge_is_no_longer_valid_for_password_reset(): void
    {
        $user = $this->activeUser();

        $result =
            $this->service->start(
                $user->account_login_identifier
            );

        $verified =
            $this->service->verify(
                $user->id,
                $result['code']
            );

        $this->service->markUsed(
            $verified
        );

        $this->assertNull(
            $this->service
                ->verifiedChallenge(
                    $verified->id,
                    $user->id
                )
        );
    }

    public function test_masked_email_does_not_expose_full_address(): void
    {
        $masked =
            $this->service->maskEmail(
                'recovery@example.test'
            );

        $this->assertNotSame(
            'recovery@example.test',
            $masked
        );

        $this->assertStringContainsString(
            '@',
            $masked
        );

        $this->assertStringContainsString(
            '.test',
            $masked
        );
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'account_login_identifier' =>
            '01234567',

            'recovery_email' =>
            'recovery@example.test',

            'status' =>
            AccountStatus::Active,
        ]);
    }

    private function differentCode(
        string $code
    ): string {
        return $code === '00000'
            ? '00001'
            : '00000';
    }
}
