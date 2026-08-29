<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordRecoveryCodeMail;
use App\Models\PasswordRecoveryCode;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render(
            'Auth/ForgotPassword'
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated = $request->validate([
            'account_login_identifier' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $user = User::query()
            ->where(
                'account_login_identifier',
                trim(
                    $validated['account_login_identifier']
                )
            )
            ->where(
                'status',
                AccountStatus::Active
            )
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

        /*
         * Expire any previous unused code.
         */
        PasswordRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update([
                'expires_at' => now(),
            ]);

        $code = (string) random_int(
            10000,
            99999
        );

        $recoveryCode =
            PasswordRecoveryCode::query()
            ->create([
                'user_id' => $user->id,

                'code_hash' => hash_hmac(
                    'sha256',
                    $code,
                    config('app.key')
                ),

                'expires_at' =>
                now()->addMinutes(30),
            ]);

        try {
            Mail::to(
                $user->recovery_email
            )->send(
                new PasswordRecoveryCodeMail(
                    $user->name,
                    $code
                )
            );
        } catch (Throwable $exception) {
            $recoveryCode->delete();

            report($exception);

            throw ValidationException::withMessages([
                'account_login_identifier' =>
                'Unable to process the password recovery request.',
            ]);
        }

        $request->session()->put([
            'password_recovery_user_id' =>
            $user->id,

            'password_recovery_email' =>
            $this->maskEmail(
                $user->recovery_email
            ),
        ]);

        return redirect()->route(
            'password.recovery.verify'
        );
    }

    public function verify(
        Request $request
    ): Response|RedirectResponse {
        if (
            ! $request->session()->has(
                'password_recovery_user_id'
            )
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        return Inertia::render(
            'Auth/VerifyRecoveryCode',
            [
                'maskedEmail' =>
                $request->session()->get(
                    'password_recovery_email'
                ),
            ]
        );
    }

    public function verifyCode(
        Request $request
    ): RedirectResponse {
        $validated = $request->validate([
            'code' => [
                'required',
                'digits:5',
            ],
        ]);

        $userId = $request->session()->get(
            'password_recovery_user_id'
        );

        if ($userId === null) {
            return redirect()->route(
                'password.request'
            );
        }

        $recoveryCode =
            PasswordRecoveryCode::query()
            ->where(
                'user_id',
                $userId
            )
            ->latest('id')
            ->first();

        if ($recoveryCode === null) {
            throw ValidationException::withMessages([
                'code' =>
                'Invalid verification code.',
            ]);
        }

        if ($recoveryCode->used_at !== null) {
            throw ValidationException::withMessages([
                'code' =>
                'This verification code has already been used.',
            ]);
        }

        if (
            $recoveryCode->expires_at
            ->isPast()
        ) {
            throw ValidationException::withMessages([
                'code' =>
                'This verification code has expired. Please request a new code.',
            ]);
        }

        $submittedHash = hash_hmac(
            'sha256',
            $validated['code'],
            config('app.key')
        );

        if (
            ! hash_equals(
                $recoveryCode->code_hash,
                $submittedHash
            )
        ) {
            throw ValidationException::withMessages([
                'code' =>
                'Invalid verification code.',
            ]);
        }

        $recoveryCode->forceFill([
            'verified_at' => now(),
        ])->save();

        $request->session()->put(
            'password_recovery_code_id',
            $recoveryCode->id
        );

        return redirect()->route(
            'password.recovery.reset'
        );
    }

    public function resend(
        Request $request
    ): RedirectResponse {
        $userId = $request->session()->get(
            'password_recovery_user_id'
        );

        if ($userId === null) {
            return redirect()->route(
                'password.request'
            );
        }

        $user = User::query()
            ->find($userId);

        if (
            $user === null
            || blank($user->recovery_email)
        ) {
            throw ValidationException::withMessages([
                'code' =>
                'Unable to resend the verification code.',
            ]);
        }

        $latestCode =
            PasswordRecoveryCode::query()
            ->where(
                'user_id',
                $user->id
            )
            ->latest('id')
            ->first();

        if ($latestCode !== null) {
            $secondsSinceLastCode =
                (int) $latestCode
                    ->created_at
                    ->diffInSeconds(
                        now()
                    );

            if (
                $secondsSinceLastCode < 60
            ) {
                $remaining =
                    60 -
                    $secondsSinceLastCode;

                throw ValidationException::withMessages([
                    'code' =>
                    "Please wait {$remaining} seconds before requesting another code.",
                ]);
            }
        }

        PasswordRecoveryCode::query()
            ->where(
                'user_id',
                $user->id
            )
            ->whereNull('used_at')
            ->where(
                'expires_at',
                '>',
                now()
            )
            ->update([
                'expires_at' => now(),
            ]);

        $code = (string) random_int(
            10000,
            99999
        );

        $recoveryCode =
            PasswordRecoveryCode::query()
            ->create([
                'user_id' =>
                $user->id,

                'code_hash' =>
                hash_hmac(
                    'sha256',
                    $code,
                    config(
                        'app.key'
                    )
                ),

                'expires_at' =>
                now()->addMinutes(
                    30
                ),
            ]);

        try {
            Mail::to(
                $user->recovery_email
            )->send(
                new PasswordRecoveryCodeMail(
                    $user->name,
                    $code
                )
            );
        } catch (Throwable $exception) {
            $recoveryCode->delete();

            report($exception);

            throw ValidationException::withMessages([
                'code' =>
                'Unable to resend the verification code.',
            ]);
        }

        return back();
    }

    public function reset(
        Request $request
    ): Response|RedirectResponse {
        /*
     * Allow the success screen to be displayed
     * after the recovery session has been cleared.
     */
        if (
            $request->session()->get(
                'passwordResetSuccessful',
                false
            )
        ) {
            return Inertia::render(
                'Auth/ResetPassword',
                [
                    'passwordResetSuccessful' => true,
                ]
            );
        }

        $recoveryCodeId =
            $request->session()->get(
                'password_recovery_code_id'
            );

        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if (
            $recoveryCodeId === null
            || $userId === null
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        $recoveryCode =
            PasswordRecoveryCode::query()
            ->whereKey($recoveryCodeId)
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
            ->first();

        if (
            $recoveryCode === null
            || $recoveryCode->expires_at->isPast()
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        return Inertia::render(
            'Auth/ResetPassword',
            [
                'passwordResetSuccessful' => false,
            ]
        );
    }

    public function resetPassword(
        Request $request
    ): RedirectResponse {
        $validated = $request->validate([
            'password' => [
                'required',
                Password::defaults(),
                'confirmed',
            ],
        ]);

        $recoveryCodeId =
            $request->session()->get(
                'password_recovery_code_id'
            );

        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if (
            $recoveryCodeId === null
            || $userId === null
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        DB::transaction(function () use (
            $recoveryCodeId,
            $userId,
            $validated
        ): void {
            $recoveryCode =
                PasswordRecoveryCode::query()
                ->whereKey(
                    $recoveryCodeId
                )
                ->where(
                    'user_id',
                    $userId
                )
                ->lockForUpdate()
                ->first();

            if (
                $recoveryCode === null
                || $recoveryCode->verified_at === null
                || $recoveryCode->used_at !== null
                || $recoveryCode->expires_at->isPast()
            ) {
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
                'password' => Hash::make(
                    $validated['password']
                ),

                'password_changed_at' => now(),

                'must_change_password' => false,

                'temporary_password_used_at' => null,

                'failed_login_attempts' => 0,

                'locked_until' => null,
            ])->save();

            /*
         * A recovery code is single-use.
         */
            $recoveryCode->forceFill([
                'used_at' => now(),
            ])->save();
        });

        /*
     * Recovery is complete. Do not leave the
     * verification state in the session.
     */
        $request->session()->forget([
            'password_recovery_user_id',
            'password_recovery_email',
            'password_recovery_code_id',
        ]);

        return redirect()
            ->route(
                'password.recovery.reset'
            )
            ->with(
                'passwordResetSuccessful',
                true
            );
    }

    private function maskEmail(
        string $email
    ): string {
        [$name, $domain] =
            explode('@', $email, 2);

        $name =
            substr($name, 0, 1)
            . str_repeat(
                '*',
                max(4, strlen($name) - 1)
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
            implode('.', $domainParts);

        return $name
            . '@'
            . $maskedDomain
            . ($suffix !== ''
                ? '.' . $suffix
                : '');
    }
}
