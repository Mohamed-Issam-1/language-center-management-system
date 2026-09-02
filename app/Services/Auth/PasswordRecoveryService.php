<?php

namespace App\Services\Auth;

use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordRecoveryService
{
    public const CODE_LENGTH = 5;

    public const EXPIRY_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Start a new password-recovery challenge for an account.
     *
     * @return array{
     *     user: User,
     *     challenge: PasswordRecoveryChallenge,
     *     code: string,
     *     masked_email: string
     * }
     */
    public function start(
        string $accountLoginIdentifier
    ): array {
        $identifier =
            trim($accountLoginIdentifier);

        return DB::transaction(
            function () use ($identifier): array {
                /*
             * Lock the account row so concurrent recovery
             * requests for the same account are serialized.
             */
                $user = User::query()
                    ->where(
                        'account_login_identifier',
                        $identifier
                    )
                    ->where(
                        'status',
                        AccountStatus::Active
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    $user === null
                    || blank($user->recovery_email)
                ) {
                    throw ValidationException::withMessages([
                        'account_login_identifier' =>
                        'Unable to process the password recovery request.',
                    ]);
                }

                $this->invalidateActiveChallenges(
                    $user
                );

                return $this->createChallenge(
                    $user
                );
            }
        );
    }

    /**
     * Resend a new code for an existing recovery account.
     *
     * @return array{
     *     user: User,
     *     challenge: PasswordRecoveryChallenge,
     *     code: string,
     *     masked_email: string
     * }
     */
    public function resend(
        int $userId
    ): array {
        return DB::transaction(
            function () use ($userId): array {
                /*
             * The user row is the serialization point for
             * all recovery-code issuance for this account.
             */
                $user = User::query()
                    ->whereKey($userId)
                    ->where(
                        'status',
                        AccountStatus::Active
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    $user === null
                    || blank($user->recovery_email)
                ) {
                    throw ValidationException::withMessages([
                        'code' =>
                        'Unable to resend the verification code.',
                    ]);
                }

                $latestChallenge =
                    PasswordRecoveryChallenge::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->latest('id')
                    ->first();

                if ($latestChallenge !== null) {
                    $availableAt =
                        $latestChallenge
                        ->created_at
                        ->copy()
                        ->addSeconds(
                            self::RESEND_COOLDOWN_SECONDS
                        );

                    if (now()->lt($availableAt)) {
                        $remaining = max(
                            1,
                            (int) ceil(
                                now()->diffInSeconds(
                                    $availableAt,
                                    false
                                )
                            )
                        );

                        throw ValidationException::withMessages([
                            'code' =>
                            "Please wait {$remaining} seconds before requesting another code.",
                        ]);
                    }
                }

                $this->invalidateActiveChallenges(
                    $user
                );

                return $this->createChallenge(
                    $user
                );
            }
        );
    }

    public function verify(
        int $userId,
        string $code
    ): PasswordRecoveryChallenge {
        if (
            ! preg_match(
                '/^\d{5}$/',
                $code
            )
        ) {
            throw ValidationException::withMessages([
                'code' =>
                'Please enter the complete 5-digit verification code.',
            ]);
        }

        $result = DB::transaction(
            function () use (
                $userId,
                $code
            ): array {
                $challenge =
                    PasswordRecoveryChallenge::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($challenge === null) {
                    return [
                        'status' => 'invalid',
                        'challenge' => null,
                    ];
                }

                if ($challenge->isUsed()) {
                    return [
                        'status' => 'used',
                        'challenge' => $challenge,
                    ];
                }

                if ($challenge->isExpired()) {
                    return [
                        'status' => 'expired',
                        'challenge' => $challenge,
                    ];
                }

                if (
                    ! $challenge
                        ->hasAttemptsRemaining()
                ) {
                    return [
                        'status' =>
                        'attempts_exhausted',

                        'challenge' =>
                        $challenge,
                    ];
                }

                /*
             * Always validate the supplied code,
             * even if this challenge was already verified.
             */
                if (
                    ! hash_equals(
                        $challenge->code_hash,
                        $this->hashCode($code)
                    )
                ) {
                    $challenge->increment(
                        'attempts'
                    );

                    $challenge->refresh();

                    if (
                        ! $challenge
                            ->hasAttemptsRemaining()
                    ) {
                        $challenge->forceFill([
                            'expires_at' => now(),
                        ])->save();

                        return [
                            'status' =>
                            'attempts_exhausted',

                            'challenge' =>
                            $challenge,
                        ];
                    }

                    return [
                        'status' =>
                        'invalid_code',

                        'challenge' =>
                        $challenge,
                    ];
                }

                /*
             * Re-submitting the same correct code
             * is safe and keeps verification idempotent.
             */
                if ($challenge->isVerified()) {
                    return [
                        'status' =>
                        'verified',

                        'challenge' =>
                        $challenge,
                    ];
                }

                $challenge->forceFill([
                    'verified_at' => now(),
                ])->save();

                return [
                    'status' =>
                    'verified',

                    'challenge' =>
                    $challenge,
                ];
            }
        );

        return match ($result['status']) {
            'verified' =>
            $result['challenge'],

            'expired' =>
            throw ValidationException::withMessages([
                'code' =>
                'This verification code has expired. Please request a new code.',
            ]),

            'used' =>
            throw ValidationException::withMessages([
                'code' =>
                'This verification code has already been used.',
            ]),

            'attempts_exhausted' =>
            throw ValidationException::withMessages([
                'code' =>
                'Too many incorrect attempts. Please request a new verification code.',
            ]),

            default =>
            throw ValidationException::withMessages([
                'code' =>
                'Invalid verification code.',
            ]),
        };
    }

    public function verifiedChallenge(
        int $challengeId,
        int $userId,
        bool $lockForUpdate = false
    ): ?PasswordRecoveryChallenge {
        $query =
            PasswordRecoveryChallenge::query()
            ->whereKey($challengeId)
            ->where(
                'user_id',
                $userId
            )
            ->whereNotNull(
                'verified_at'
            )
            ->whereNull(
                'used_at'
            )
            ->where(
                'expires_at',
                '>',
                now()
            );

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function markUsed(
        PasswordRecoveryChallenge $challenge
    ): void {
        $challenge->forceFill([
            'used_at' => now(),
        ])->save();
    }

    public function resetPassword(
        int $challengeId,
        int $userId,
        string $password
    ): User {
        return DB::transaction(
            function () use (
                $challengeId,
                $userId,
                $password
            ): User {
                $challenge =
                    $this->verifiedChallenge(
                        $challengeId,
                        $userId,
                        true
                    );

                if ($challenge === null) {
                    throw ValidationException::withMessages([
                        'password' =>
                        'This password recovery request is no longer valid.',
                    ]);
                }

                $user = User::query()
                    ->whereKey($userId)
                    ->where(
                        'status',
                        AccountStatus::Active
                    )
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    throw ValidationException::withMessages([
                        'password' =>
                        'Unable to process the password recovery request.',
                    ]);
                }

                $user->forceFill([
                    'password' =>
                    Hash::make($password),

                    'password_changed_at' =>
                    now(),

                    'must_change_password' =>
                    false,

                    'temporary_password_used_at' =>
                    null,

                    'failed_login_attempts' =>
                    0,

                    'locked_until' =>
                    null,

                    /*
                 * Invalidates existing remember-me cookies.
                 */
                    'remember_token' =>
                    Str::random(60),
                ])->save();

                $this->markUsed(
                    $challenge
                );

                return $user;
            }
        );
    }

    public function expireChallenge(
        PasswordRecoveryChallenge $challenge
    ): void {
        if ($challenge->used_at !== null) {
            return;
        }

        $challenge->forceFill([
            'expires_at' => now(),
        ])->save();
    }

    public function maskEmail(
        string $email
    ): string {
        [$localPart, $domain] =
            explode('@', $email, 2);

        $maskedLocal =
            substr($localPart, 0, 1)
            . str_repeat(
                '*',
                max(
                    4,
                    strlen($localPart) - 1
                )
            );

        $domainParts =
            explode('.', $domain);

        $domainName =
            array_shift($domainParts);

        $maskedDomain =
            substr($domainName, 0, 1)
            . str_repeat(
                '*',
                max(
                    4,
                    strlen($domainName) - 1
                )
            );

        $suffix =
            implode(
                '.',
                $domainParts
            );

        return $maskedLocal
            . '@'
            . $maskedDomain
            . (
                $suffix !== ''
                ? '.' . $suffix
                : ''
            );
    }

    /**
     * @return array{
     *     user: User,
     *     challenge: PasswordRecoveryChallenge,
     *     code: string,
     *     masked_email: string
     * }
     */
    private function createChallenge(
        User $user
    ): array {
        /*
         * Generate the complete 00000-99999 range.
         *
         * str_pad keeps leading-zero codes valid.
         */
        $code = str_pad(
            (string) random_int(
                0,
                99999
            ),
            self::CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );

        $challenge =
            PasswordRecoveryChallenge::query()
            ->create([
                'user_id' =>
                $user->id,

                'code_hash' =>
                $this->hashCode(
                    $code
                ),

                'attempts' => 0,

                'max_attempts' =>
                self::MAX_ATTEMPTS,

                'expires_at' =>
                now()->addMinutes(
                    self::EXPIRY_MINUTES
                ),
            ]);

        return [
            'user' => $user,

            'challenge' =>
            $challenge,

            'code' => $code,

            'masked_email' =>
            $this->maskEmail(
                (string) $user->recovery_email
            ),
        ];
    }

    private function invalidateActiveChallenges(
        User $user
    ): void {
        PasswordRecoveryChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->whereNull(
                'used_at'
            )
            ->where(
                'expires_at',
                '>',
                now()
            )
            ->update([
                'expires_at' => now(),
            ]);
    }

    private function hashCode(
        string $code
    ): string {
        return hash_hmac(
            'sha256',
            $code,
            (string) config('app.key')
        );
    }
}