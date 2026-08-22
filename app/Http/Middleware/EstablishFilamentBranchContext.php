<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstablishFilamentBranchContext
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly EstablishBranchContext $operationalBranchContext
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
         * Never allow operational state from another request or
         * Livewire interaction to leak into the current one.
         */
        $this->branchContext->clear();

        $user = $request->user();

        abort_unless(
            $user instanceof User,
            401
        );

        $systemRole = $user->systemRole();

        abort_if(
            $systemRole === null,
            403,
            'The authenticated account has no valid system role.'
        );

        /*
         * Platform Owner has platform-wide scope and deliberately
         * has no Branch context.
         *
         * EstablishTenantContext has already validated the platform
         * account structure before this middleware executes.
         */
        if (
            $systemRole === SystemRole::PlatformOwner
        ) {
            abort_unless(
                $this->tenant->isEstablished()
                    && $this->tenant->isPlatformScoped(),
                403,
                'Filament Platform Owner access requires platform tenant scope.'
            );

            return $next($request);
        }

        /*
         * Reuse the authoritative operational Branch middleware for:
         *
         * - Center Owner       -> Center-wide Branch context
         * - Branch Manager     -> assigned Branch
         * - Finance Employee   -> assigned Branch
         *
         * It also fails closed for unsupported roles.
         */
        return $this->operationalBranchContext
            ->handle(
                $request,
                $next
            );
    }
}
