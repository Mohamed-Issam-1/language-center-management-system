<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestPersonalPictureController;
use App\Http\Middleware\EstablishFilamentBranchContext;
use App\Services\Reports\DashboardReadService;
use App\Support\Enums\SystemRole;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
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

/*
|--------------------------------------------------------------------------
| Demo Student Portal
|--------------------------------------------------------------------------
|
| These routes intentionally contain no authentication or production
| persistence. They exist only for viewing the Student frontend with
| its demo data.
|
*/

Route::get('/demo/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('demo.dashboard');

Route::get('/demo/courses', function () {
    return Inertia::render('Student/MyCourses');
})->name('demo.courses');

Route::get('/demo/courses/{course}', function () {
    return Inertia::render('Student/CourseDetails');
})
    ->whereNumber('course')
    ->name('demo.courses.show');

Route::get('/demo/schedule', function () {
    return Inertia::render('Student/MySchedule');
})->name('demo.schedule');

Route::get('/demo/attendance', function () {
    return Inertia::render('Student/MyAttendance');
})->name('demo.attendance');

Route::get('/demo/payments', function () {
    return Inertia::render('Student/Payments');
})->name('demo.payments');

Route::get('/demo/profile', function () {
    return Inertia::render('Student/Profile');
})->name('demo.profile');

Route::get('/demo/profile/edit', function () {
    return Inertia::render('Student/EditProfile');
})->name('demo.profile.edit');

/*
|--------------------------------------------------------------------------
| Authenticated Dashboard
|--------------------------------------------------------------------------
*/

Route::get(
    '/dashboard',
    function (
        Request $request,
        DashboardReadService $dashboards
    ) {
        $actor = $request->user();

        $role = $actor->systemRole();

        if (
            in_array(
                $role,
                [
                    SystemRole::PlatformOwner,
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                    SystemRole::FinanceEmployee,
                ],
                true
            )
        ) {
            return redirect('/admin');
        }

        return Inertia::render(
            'Dashboard',
            [
                'dashboard' =>
                $dashboards->forUser(
                    $actor
                ),
            ]
        );
    }
)
    ->middleware([
        'auth',
        'tenant.context',
        'password.change.completed',
        'verified',
    ])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Student Portal
|--------------------------------------------------------------------------
|
| Production Student pages require the same authenticated tenant and
| account lifecycle boundaries as the rest of the application.
|
*/

Route::middleware([
    'auth',
    'tenant.context',
    'password.change.completed',
    'verified',
])->group(function () {
    Route::get('/my-courses', function () {
        return Inertia::render(
            'Student/MyCourses'
        );
    })->name('student.courses.index');

    Route::get('/my-courses/{course}', function () {
        return Inertia::render(
            'Student/CourseDetails'
        );
    })
        ->whereNumber('course')
        ->name('student.courses.show');

    Route::get('/my-schedule', function () {
        return Inertia::render(
            'Student/MySchedule'
        );
    })->name('student.schedule');

    Route::get('/my-attendance', function () {
        return Inertia::render(
            'Student/MyAttendance'
        );
    })->name('student.attendance');

    Route::get('/payments', function () {
        return Inertia::render(
            'Student/Payments'
        );
    })->name('student.payments');

    Route::get('/student/profile', function () {
        return Inertia::render(
            'Student/Profile'
        );
    })->name('student.profile');

    Route::get('/student/profile/edit', function () {
        return Inertia::render(
            'Student/EditProfile'
        );
    })->name('student.profile.edit');
});

/*
|--------------------------------------------------------------------------
| Existing Account Profile
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Administrative Files
|--------------------------------------------------------------------------
*/

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