<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Support\Enums\CenterStatus;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the public Registration Request page.
     *
     * Public registration does not create a User Account.
     * It only allows the applicant to submit a pending
     * RegistrationRequest to an active Language Center.
     */
    public function create(): Response
    {
        $centers =
            Center::query()
            ->where(
                'status',
                CenterStatus::Active->value
            )
            ->orderBy('name')
            ->get([
                'code',
                'name',
            ])
            ->map(
                fn(
                    Center $center
                ): array => [
                    'code' =>
                    (string) $center->code,

                    'name' =>
                    (string) $center->name,
                ]
            )
            ->values()
            ->all();

        return Inertia::render(
            'Auth/Register',
            [
                'centers' => $centers,
            ]
        );
    }
}
