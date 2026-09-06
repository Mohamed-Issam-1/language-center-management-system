<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordRecoveryCodeMail;
use App\Services\Auth\ActiveSessionService;
use App\Services\Auth\PasswordRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
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
        Request $request,
        PasswordRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validate([
            'account_login_identifier' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $result = $recovery->start(
            $validated['account_login_identifier']
        );

        try {
            Mail::to(
                $result['user']->recovery_email
            )->send(
                new PasswordRecoveryCodeMail(
                    (string) $result['user']->name,
                    $result['code']
                )
            );
        } catch (Throwable $exception) {
            $recovery->expireChallenge(
                $result['challenge']
            );

            report($exception);

            throw ValidationException::withMessages([
                'account_login_identifier' =>
                'Unable to process the password recovery request.',
            ]);
        }

        $request->session()->put([
            'password_recovery_user_id' =>
            $result['user']->id,

            'password_recovery_email' =>
            $result['masked_email'],
        ]);

        $request->session()->forget(
            'password_recovery_challenge_id'
        );

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
        Request $request,
        PasswordRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validate([
            'code' => [
                'required',
                'digits:5',
            ],
        ]);

        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if ($userId === null) {
            return redirect()->route(
                'password.request'
            );
        }

        $challenge = $recovery->verify(
            (int) $userId,
            $validated['code']
        );

        $request->session()->put(
            'password_recovery_challenge_id',
            $challenge->id
        );

        return redirect()->route(
            'password.recovery.reset'
        );
    }

    public function resend(
        Request $request,
        PasswordRecoveryService $recovery
    ): RedirectResponse {
        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if ($userId === null) {
            return redirect()->route(
                'password.request'
            );
        }

        $result = $recovery->resend(
            (int) $userId
        );

        try {
            Mail::to(
                $result['user']->recovery_email
            )->send(
                new PasswordRecoveryCodeMail(
                    (string) $result['user']->name,
                    $result['code']
                )
            );
        } catch (Throwable $exception) {
            $recovery->expireChallenge(
                $result['challenge']
            );

            report($exception);

            throw ValidationException::withMessages([
                'code' =>
                'Unable to resend the verification code.',
            ]);
        }

        $request->session()->put(
            'password_recovery_email',
            $result['masked_email']
        );

        $request->session()->forget(
            'password_recovery_challenge_id'
        );

        return back();
    }

    public function reset(
        Request $request,
        PasswordRecoveryService $recovery
    ): Response|RedirectResponse {
        if (
            $request->session()->get(
                'passwordResetSuccessful',
                false
            )
        ) {
            return Inertia::render(
                'Auth/ResetPassword',
                [
                    'passwordResetSuccessful' =>
                    true,
                ]
            );
        }

        $challengeId =
            $request->session()->get(
                'password_recovery_challenge_id'
            );

        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if (
            $challengeId === null
            || $userId === null
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        $challenge =
            $recovery->verifiedChallenge(
                (int) $challengeId,
                (int) $userId
            );

        if ($challenge === null) {
            $this->clearRecoverySession(
                $request
            );

            return redirect()->route(
                'password.request'
            );
        }

        return Inertia::render(
            'Auth/ResetPassword',
            [
                'passwordResetSuccessful' =>
                false,
            ]
        );
    }

    public function resetPassword(
        Request $request,
        PasswordRecoveryService $recovery,
        ActiveSessionService $sessions
    ): RedirectResponse {
        $validated = $request->validate([
            'password' => [
                'required',
                Password::defaults(),
                'confirmed',
            ],
        ]);

        $challengeId =
            $request->session()->get(
                'password_recovery_challenge_id'
            );

        $userId =
            $request->session()->get(
                'password_recovery_user_id'
            );

        if (
            $challengeId === null
            || $userId === null
        ) {
            return redirect()->route(
                'password.request'
            );
        }

        $user = $recovery->resetPassword(
            (int) $challengeId,
            (int) $userId,
            $validated['password']
        );

        /*
         * Password recovery invalidates all authenticated
         * sessions belonging to the recovered account.
         *
         * The current recovery browser session is a guest
         * session and therefore is not deleted here.
         */
        $sessions->terminateAll(
            $user
        );

        $this->clearRecoverySession(
            $request
        );

        /*
         * Rotate the guest recovery session after the
         * sensitive credential operation.
         */
        $request->session()->regenerate();

        return redirect()
            ->route(
                'password.recovery.reset'
            )
            ->with(
                'passwordResetSuccessful',
                true
            );
    }

    private function clearRecoverySession(
        Request $request
    ): void {
        $request->session()->forget([
            'password_recovery_user_id',
            'password_recovery_email',
            'password_recovery_challenge_id',
        ]);
    }
}
