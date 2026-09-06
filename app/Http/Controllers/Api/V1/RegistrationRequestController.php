<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use App\Models\Center;
use App\Services\Registration\RegistrationSubmissionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegistrationRequestController extends Controller
{
    public function store(
        StoreRegistrationRequest $request,
        Center $center,
        RegistrationSubmissionService $registrations
    ): JsonResponse {
        try {
            $registration =
                $registrations->submit(
                    $center,
                    $request->validated(),
                    $request->file(
                        'personal_picture'
                    )
                );
        } catch (DomainException $exception) {
            return response()->json(
                [
                    'message' =>
                    $exception->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /*
         * Do not return the private picture path or unnecessary
         * personal identity information in the public response.
         */
        return response()->json(
            [
                'message' =>
                'Registration request submitted successfully.',

                'data' => [
                    'id' =>
                    $registration->id,

                    'center_code' =>
                    $center->code,

                    'status' =>
                    $registration
                        ->status
                        ->value,

                    'submitted_at' =>
                    $registration
                        ->created_at
                        ->toISOString(),
                ],
            ],
            Response::HTTP_CREATED
        );
    }
}
