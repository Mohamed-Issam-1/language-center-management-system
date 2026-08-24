<?php

use App\Http\Controllers\RegistrationRequestPersonalPictureController;
use App\Http\Middleware\EstablishFilamentBranchContext;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})
    ->middleware([
        'auth',
        'tenant.context',
        'password.change.completed',
        'verified',
    ])
    ->name('dashboard');

Route::middleware([
    'auth',
    'tenant.context',
    'password.change.completed',
])->group(function () {
    Route::get(
        '/profile',
        [ProfileController::class, 'edit']
    )->name('profile.edit');

    Route::patch(
        '/profile',
        [ProfileController::class, 'update']
    )->name('profile.update');
});

Route::get(
    '/admin/registration-requests/{registrationRequest}/personal-picture',
    RegistrationRequestPersonalPictureController::class
)
    ->middleware([
        'auth',
        'tenant.context',
        'password.change.completed',
        EstablishFilamentBranchContext::class,
    ])
    ->whereNumber(
        'registrationRequest'
    )
    ->name(
        'admin.registration-requests.personal-picture'
    );

require __DIR__ . '/auth.php';
