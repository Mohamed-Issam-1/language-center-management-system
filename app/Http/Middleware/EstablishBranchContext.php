<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstablishBranchContext
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
         * Never allow Branch scope from a previous request
         * to survive into the current request.
         */
        $this->branchContext->clear();

        $user = $request->user();

        abort_unless(
            $user instanceof User,
            401
        );

        /*
         * Branch context is an operational child scope of a
         * center tenant. TenantContext must therefore already
         * be established by tenant.context middleware.
         */
        abort_unless(
            $this->tenant->isCenterScoped(),
            403,
            'Branch operational scope requires an active center tenant.'
        );

        $centerId = $this->tenant->centerId();

        abort_if(
            $centerId === null
                || $user->center_id !== $centerId,
            403,
            'Authenticated account and tenant context do not match.'
        );

        $systemRole = $user->systemRole();

        abort_if(
            $systemRole === null,
            403,
            'The authenticated account has no valid system role.'
        );

        if (
            $systemRole === SystemRole::CenterOwner
        ) {
            /*
             * Center Owner is intentionally not restricted to
             * one Branch. Branch-owned queries will later use
             * this state to retain Center-wide scope.
             */
            $this->branchContext
                ->establishCenterWideScope();

            return $next($request);
        }

        if (
            $systemRole === SystemRole::BranchManager
        ) {
            $branch = $this->resolveBranchManagerBranch(
                $user,
                $centerId
            );

            $this->branchContext
                ->establishBranchScope($branch);

            return $next($request);
        }

        if (
            $systemRole === SystemRole::FinanceEmployee
        ) {
            $branch = $this->resolveFinanceEmployeeBranch(
                $user,
                $centerId
            );

            $this->branchContext
                ->establishBranchScope($branch);

            return $next($request);
        }

        /*
         * Platform Owner has platform scope.
         *
         * Teacher and Student authorization will be resolved
         * later from class, enrollment, and record-level scopes
         * rather than inventing a Branch assignment for them.
         */
        abort(
            403,
            'The authenticated role does not use Branch operational scope.'
        );
    }

    private function resolveBranchManagerBranch(
        User $user,
        int $centerId
    ): Branch {
        $assignments =
            BranchManagerAssignment::query()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $user->id
            )
            ->active()
            ->with('branch')
            ->limit(2)
            ->get();

        /*
         * Database constraints normally guarantee at most one
         * active assignment. Requiring exactly one here also
         * fails closed if persisted data is ever inconsistent.
         */
        abort_unless(
            $assignments->count() === 1,
            403,
            'The Branch Manager does not have exactly one active Branch assignment.'
        );

        $branch = $assignments
            ->first()
            ?->branch;

        return $this->validateAssignedBranch(
            $branch,
            $centerId
        );
    }

    private function resolveFinanceEmployeeBranch(
        User $user,
        int $centerId
    ): Branch {
        $assignments =
            FinanceEmployeeAssignment::query()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $user->id
            )
            ->active()
            ->with('branch')
            ->limit(2)
            ->get();

        abort_unless(
            $assignments->count() === 1,
            403,
            'The Finance Employee does not have exactly one active Branch assignment.'
        );

        $branch = $assignments
            ->first()
            ?->branch;

        return $this->validateAssignedBranch(
            $branch,
            $centerId
        );
    }

    private function validateAssignedBranch(
        ?Branch $branch,
        int $centerId
    ): Branch {
        abort_if(
            $branch === null,
            403,
            'The assigned Branch is unavailable.'
        );

        abort_if(
            $branch->center_id !== $centerId,
            403,
            'The assigned Branch is outside the authenticated Center.'
        );

        return $branch;
    }
}
