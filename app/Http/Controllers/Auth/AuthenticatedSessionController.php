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
            * Self-service password recovery is available
            * through the LCMS verification-code flow.
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

        return redirect()
            ->route('login.success');
    }

    public function success(Request $request): Response
    {
        /*
     * Never allow the intermediate success screen to
     * bypass the forced password-change destination.
     */
        if ($request->user()?->must_change_password) {
            $request->session()->forget(
                'post_login_redirect'
            );

            $redirectTo = route(
                'profile.edit',
                absolute: false
            );

            $request->session()->flash(
                'status',
                'You must change your temporary password before continuing.'
            );
        } else {
            $redirectTo = $request->session()->pull(
                'post_login_redirect',
                route('dashboard', absolute: false)
            );
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
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
