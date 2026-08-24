<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstablishTenantContext
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
         * Explicitly clear any previously resolved state before
         * establishing the context for this request.
         */
        $this->tenant->clear();

        $user = $request->user();

        abort_unless(
            $user instanceof User,
            401
        );

        /*
        * Authentication state alone is not sufficient authorization.
        *
        * An account may have been deactivated or otherwise made
        * non-active after its current session was created.
        *
        * Every protected request must therefore revalidate the
        * persisted account lifecycle state.
        */
        abort_unless(
            $user->status === AccountStatus::Active,
            403,
            'The authenticated account is not active.'
        );

        $user->loadMissing([
            'role',
            'center',
            'person',
        ]);

        $systemRole = $user->systemRole();

        abort_if(
            $systemRole === null,
            403,
            'The authenticated account has no valid system role.'
        );

        if (
            $systemRole === SystemRole::PlatformOwner
        ) {
            $this->establishPlatformContext(
                $user
            );

            return $next($request);
        }

        $this->establishCenterContext(
            $user
        );

        return $next($request);
    }

    private function establishPlatformContext(
        User $user
    ): void {
        /*
         * Platform Owner is the only platform-scoped role and must
         * not be attached to a Center or Person.
         */
        abort_if(
            $user->center_id !== null
                || $user->person_id !== null,
            403,
            'Invalid platform account scope.'
        );

        $this->tenant->establishPlatformScope();
    }

    private function establishCenterContext(
        User $user
    ): void {
        /*
         * Every non-platform account must have both a Center and
         * a Person.
         */
        abort_if(
            $user->center_id === null
                || $user->person_id === null,
            403,
            'Invalid center account scope.'
        );

        abort_if(
            $user->center === null
                || $user->person === null,
            403,
            'The account tenant relationships are unavailable.'
        );

        /*
         * The database composite foreign key also protects this
         * invariant, but it remains an explicit authorization
         * boundary at the request layer.
         */
        abort_if(
            $user->person->center_id
                !== $user->center_id,
            403,
            'The account Person does not belong to the account Center.'
        );

        abort_unless(
            $user->center->status
                === CenterStatus::Active,
            403,
            'The language center is not active.'
        );

        $this->tenant->establishCenterScope(
            $user->center
        );
    }
}
