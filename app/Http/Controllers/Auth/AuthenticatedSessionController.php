<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticateUserAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            /*
             * Self-service password recovery is intentionally
             * unavailable in the LCMS MVP.
             */
            'canResetPassword' => false,
            'status' => session('status'),
        ]);
    }

    public function store(
        LoginRequest $request,
        AuthenticateUserAccount $authenticator
    ): RedirectResponse {
        $validated = $request->validated();

        $user = $authenticator->handle(
            $validated['account_login_identifier'],
            $validated['password'],
        );

        Auth::login(
            $user,
            $request->boolean('remember')
        );

        $request->session()->regenerate();

        if ($user->must_change_password) {
            return redirect()
                ->route('profile.edit')
                ->with(
                    'status',
                    'You must change your temporary password before continuing.'
                );
        }

        return redirect()->intended(
            route('dashboard', absolute: false)
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
