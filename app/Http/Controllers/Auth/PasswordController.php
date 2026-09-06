<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'current_password',
            ],
            'password' => [
                'required',
                'different:current_password',
                Password::defaults(),
                'confirmed',
            ],
        ]);

        $user = $request->user();

        $wasForcedPasswordChange =
            $user->must_change_password;

        $user->forceFill([
            'password' =>
            Hash::make(
                $validated['password']
            ),

            'must_change_password' =>
            false,

            'temporary_password_used_at' =>
            null,

            'password_changed_at' =>
            now(),
        ])->save();

        /*
         * Completing the first-login temporary-password flow
         * requires a fresh authentication cycle.
         *
         * This prevents the session authenticated with the
         * temporary credential from continuing after the
         * permanent credential is established.
         */
        if ($wasForcedPasswordChange) {
            Auth::guard('web')->logout();

            $request->session()
                ->invalidate();

            $request->session()
                ->regenerateToken();

            return redirect()
                ->route('login')
                ->with(
                    'status',
                    'Password changed successfully. Please sign in again.'
                );
        }

        /*
         * A normal password change by an already established
         * account keeps the user authenticated, but rotates the
         * session identifier after the credential change.
         */
        $request->session()
            ->regenerate();

        return back();
    }
}
