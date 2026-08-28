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
                    $validated[
                        'account_login_identifier'
                    ]
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