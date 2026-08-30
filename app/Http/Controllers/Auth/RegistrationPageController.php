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
    public function __invoke(): Response
    {
        $center = Center::query()
            ->where(
                'status',
                CenterStatus::Active->value
            )
            ->firstOrFail();

        $branch = Branch::withoutGlobalScopes()
            ->where('center_id', $center->id)
            ->where(
                'status',
                BranchStatus::Active->value
            )
            ->firstOrFail();

        return Inertia::render('Auth/Register', [
            'registrationContext' => [
                'center' => [
                    'code' => $center->code,
                    'name' => $center->name,
                ],

                'branch' => [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                ],
            ],
        ]);
    }
}