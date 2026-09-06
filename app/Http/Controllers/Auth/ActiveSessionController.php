<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ActiveSessionController extends Controller
{
    public function index(
        Request $request,
        ActiveSessionService $sessions
    ): JsonResponse {
        return response()->json([
            'sessions' => $sessions
                ->forUser(
                    $request->user(),
                    $request->session()->getId()
                )
                ->values(),
        ]);
    }

    public function destroy(
        Request $request,
        string $sessionKey,
        ActiveSessionService $sessions
    ): Response|RedirectResponse {
        $user = $request->user();

        $targetSession = $sessions
            ->findOwnedActiveSession(
                $user,
                $sessionKey
            );

        abort_if(
            $targetSession === null,
            404
        );

        /*
         * Ending the current session requires normal logout /
         * invalidation semantics. Simply deleting its database
         * record during the request could allow the current
         * request lifecycle to persist session data again.
         */
        if (
            hash_equals(
                $request->session()->getId(),
                $targetSession->id
            )
        ) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();

            $request->session()->regenerateToken();

            return redirect('/');
        }

        $sessions->terminate(
            $user,
            $targetSession->id
        );

        return response()->noContent();
    }
}
