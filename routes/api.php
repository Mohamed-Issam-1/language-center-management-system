<?php

use App\Http\Controllers\Api\V1\RegistrationRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::post(
            'centers/{center:code}/registration-requests',
            [
                RegistrationRequestController::class,
                'store',
            ]
        )->name(
            'api.v1.registration-requests.store'
        );
    });
