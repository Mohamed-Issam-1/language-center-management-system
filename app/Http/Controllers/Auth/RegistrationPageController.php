<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Center;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\CenterStatus;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationPageController extends Controller
{
    public function __invoke(
        Center $center,
        int $branch
    ): Response {
        abort_unless(
            $center->status === CenterStatus::Active,
            404
        );

        $selectedBranch =
            Branch::withoutGlobalScopes()
                ->whereKey($branch)
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'status',
                    BranchStatus::Active->value
                )
                ->firstOrFail();

        return Inertia::render(
            'Auth/Register',
            [
                'registrationContext' => [
                    'center' => [
                        'code' =>
                            $center->code,

                        'name' =>
                            $center->name,
                    ],

                    'branch' => [
                        'id' =>
                            $selectedBranch->id,

                        'code' =>
                            $selectedBranch->code,

                        'name' =>
                            $selectedBranch->name,
                    ],
                ],
            ]
        );
    }
}