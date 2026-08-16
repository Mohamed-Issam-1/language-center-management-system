<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChangeCompleted
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (
            $user === null
            || ! $user->must_change_password
        ) {
            return $next($request);
        }

        /*
         * The existing Profile page is temporarily used as the
         * password-change interface on the backend branch.
         *
         * Profile information updates and account deletion remain
         * blocked because their route names are not allow-listed.
         */
        if ($request->routeIs(
            'profile.edit',
            'password.update',
            'logout'
        )) {
            return $next($request);
        }

        return redirect()
            ->route('profile.edit')
            ->with(
                'status',
                'You must change your temporary password before continuing.'
            );
    }
}
