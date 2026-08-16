<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticateUserAccount
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function handle(
        string $accountLoginIdentifier,
        string $password
    ): User {
        $result = DB::transaction(function () use (
            $accountLoginIdentifier,
            $password
        ): array {
            $user = User::query()
                ->where('account_login_identifier', $accountLoginIdentifier)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                return [
                    'status' => 'invalid',
                ];
            }

            $user->loadMissing([
                'role',
                'center',
            ]);

            $now = now();

            if (
                $user->locked_until !== null
                && $user->locked_until->isAfter($now)
            ) {
                return [
                    'status' => 'locked',
                    'seconds' => max(
                        1,
                        (int) ceil(
                            $now->diffInSeconds(
                                $user->locked_until,
                                true
                            )
                        )
                    ),
                ];
            }

            /*
             * A completed lock period starts a new sequence
             * of failed login attempts.
             */
            if ($user->locked_until !== null) {
                $user->forceFill([
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
            }

            if (! $this->accountMayAuthenticate($user)) {
                return [
                    'status' => 'invalid',
                ];
            }

            if (! Hash::check($password, $user->password)) {
                $attempts = (int) $user->failed_login_attempts + 1;

                $lockedUntil = $attempts >= self::MAX_FAILED_ATTEMPTS
                    ? $now->copy()->addMinutes(self::LOCK_MINUTES)
                    : null;

                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $lockedUntil,
                ])->save();

                return [
                    'status' => 'invalid',
                ];
            }

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => $now,
            ])->save();

            return [
                'status' => 'authenticated',
                'user' => $user,
            ];
        });

        if ($result['status'] === 'authenticated') {
            return $result['user'];
        }

        if ($result['status'] === 'locked') {
            throw ValidationException::withMessages([
                'account_login_identifier' => trans('auth.throttle', [
                    'seconds' => $result['seconds'],
                    'minutes' => max(
                        1,
                        (int) ceil($result['seconds'] / 60)
                    ),
                ]),
            ]);
        }

        throw ValidationException::withMessages([
            'account_login_identifier' => trans('auth.failed'),
        ]);
    }

    private function accountMayAuthenticate(User $user): bool
    {
        if ($user->status !== AccountStatus::Active) {
            return false;
        }

        if ($user->role === null) {
            return false;
        }

        /*
         * Platform Owner is platform-scoped.
         */
        if ($user->role->code === SystemRole::PlatformOwner->value) {
            return $user->center_id === null
                && $user->person_id === null;
        }

        /*
         * Every other system role is center-scoped.
         */
        if (
            $user->center_id === null
            || $user->person_id === null
            || $user->center === null
        ) {
            return false;
        }

        return $user->center->status === CenterStatus::Active;
    }
}
