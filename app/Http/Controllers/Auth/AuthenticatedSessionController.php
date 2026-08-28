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
            'canResetPassword' => true,
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
            $redirectTo = route(
                'profile.edit',
                absolute: false
            );
        } else {
            $redirectTo = $request->session()->pull(
                'url.intended',
                route('dashboard', absolute: false)
            );
        }

        $request->session()->put(
            'post_login_redirect',
            $redirectTo
        );

        return redirect()->route('login.success');
    }

    public function success(Request $request): Response
    {
        $redirectTo = $request->session()->pull(
            'post_login_redirect',
            route('dashboard', absolute: false)
        );

        if ($request->user()?->must_change_password) {
            $request->session()->flash(
                'status',
                'You must change your temporary password before continuing.'
            );
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => false,
            'status' => null,
            'showSuccessInitially' => true,
            'successRedirectTo' => $redirectTo,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
