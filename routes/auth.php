<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ActiveSessionController;

Route::middleware('guest')->group(function () {
    Route::get(
        'login',
        [AuthenticatedSessionController::class, 'create']
    )->name('login');

    Route::post(
        'login',
        [AuthenticatedSessionController::class, 'store']
    );
});

Route::middleware('auth')->group(function () {
    /*
     * These two operations must remain available while a user is
     * completing a forced password change.
     */
    Route::put(
        'password',
        [PasswordController::class, 'update']
    )->name('password.update');

    Route::post(
        'logout',
        [AuthenticatedSessionController::class, 'destroy']
    )->name('logout');

    /*
     * Other authenticated authentication functions require the
     * temporary-password flow to be completed first.
     */
    Route::middleware('password.change.completed')
        ->group(function () {
            Route::get(
                'account/sessions',
                [ActiveSessionController::class, 'index']
            )->name('account.sessions.index');

            Route::delete(
                'account/sessions/{sessionKey}',
                [ActiveSessionController::class, 'destroy']
            )->name('account.sessions.destroy');
            Route::get(
                'verify-email',
                EmailVerificationPromptController::class
            )->name('verification.notice');

            Route::get(
                'verify-email/{id}/{hash}',
                VerifyEmailController::class
            )
                ->middleware([
                    'signed',
                    'throttle:6,1',
                ])
                ->name('verification.verify');

            Route::post(
                'email/verification-notification',
                [EmailVerificationNotificationController::class, 'store']
            )
                ->middleware('throttle:6,1')
                ->name('verification.send');

            Route::get(
                'confirm-password',
                [ConfirmablePasswordController::class, 'show']
            )->name('password.confirm');

            Route::post(
                'confirm-password',
                [ConfirmablePasswordController::class, 'store']
            );
        });
});
