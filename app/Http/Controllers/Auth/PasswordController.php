<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $wasForcedPasswordChange = $user->must_change_password;

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'temporary_password_used_at' => null,
            'password_changed_at' => now(),
        ])->save();

        /*
         * Refresh the session identifier after a credential change.
         */
        $request->session()->regenerate();

        if ($wasForcedPasswordChange) {
            return redirect()
                ->route('dashboard')
                ->with(
                    'status',
                    'Password changed successfully.'
                );
        }

        return back();
    }
}
