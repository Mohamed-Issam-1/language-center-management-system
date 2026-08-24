<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class RedirectToApplicationLoginController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        /*
         * Filament keeps its own login route so its authentication
         * middleware has a valid login URL to redirect guests to.
         *
         * Actual credential authentication is performed only by
         * the LCMS application login flow at /login.
         *
         * Do not use redirect()->guest() here because the original
         * intended Filament URL has already been stored when the
         * unauthenticated request was intercepted.
         */
        return redirect()
            ->route('login');
    }
}
