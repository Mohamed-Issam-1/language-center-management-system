<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ActiveSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;

Route::middleware('guest')->group(function () {
    Route::get(
        'login',
        [AuthenticatedSessionController::class, 'create']
    )->name('login');

    Route::post(
        'login',
        [AuthenticatedSessionController::class, 'store']
    );

    Route::get(
        'forgot-password',
        [PasswordResetLinkController::class, 'create']
    )->name('password.request');

    Route::post(
        'forgot-password/verify',
        [PasswordResetLinkController::class, 'verifyCode']
    )->name('password.recovery.verify.submit');

    Route::post(
        'forgot-password/resend',
        [PasswordResetLinkController::class, 'resend']
    )->name('password.recovery.resend');

    Route::get(
        'forgot-password/reset',
        [PasswordResetLinkController::class, 'reset']
    )->name('password.recovery.reset');

    Route::post(
        'forgot-password',
        [PasswordResetLinkController::class, 'store']
    )->name('password.recovery.send');

    Route::get(
        'forgot-password/verify',
        [PasswordResetLinkController::class, 'verify']
    )->name('password.recovery.verify');
});

Route::middleware('auth')->group(function () {
    Route::get(
        'login/success',
        [AuthenticatedSessionController::class, 'success']
    )->name('login.success');
    /*
     * These two operations must remain available while a user is
     * completing a forced password change.
     */
    Route::put(
        'password',
        [PasswordController::class, 'update']
    )
        ->middleware('tenant.context')
        ->name('password.update');

    Route::post(
        'logout',
        [AuthenticatedSessionController::class, 'destroy']
    )->name('logout');

    /*
     * Other authenticated authentication functions require the
     * temporary-password flow to be completed first.
     */
    Route::middleware([
        'tenant.context',
        'password.change.completed',
    ])->group(function () {
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
