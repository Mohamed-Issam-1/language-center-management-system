<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestPersonalPictureController;
use App\Http\Middleware\EstablishFilamentBranchContext;
use App\Services\Reports\DashboardReadService;
use App\Services\Students\StudentDashboardReadService;
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

Route::get(
    '/dashboard',
    function (
        Request $request,
        DashboardReadService $dashboards,
        StudentDashboardReadService $studentDashboards
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

        /*
         * Student gets the Student-portal presentation payload.
         *
         * Teacher remains on the existing generic DashboardReadService
         * contract because the Teacher frontend is outside the current
         * Student-module refactor.
         */
        $dashboard = $role === SystemRole::Student
            ? $studentDashboards->forUser($actor)
            : $dashboards->forUser($actor);

        return Inertia::render(
            'Dashboard',
            [
                'dashboard' => $dashboard,
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
| Student demo routes
|--------------------------------------------------------------------------
*/

Route::get('/demo/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('demo.dashboard');

Route::get('/demo/courses', function () {
    return Inertia::render('Student/MyCourses');
})->name('demo.courses');

Route::get('/demo/courses/{course}', function () {
    return Inertia::render('Student/CourseDetails');
})->name('demo.courses.show');

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
| Student authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'tenant.context',
    'password.change.completed',
    'verified',
])->group(function () {
    Route::get(
    '/my-courses',
    function (
        Request $request,
        StudentDashboardReadService $studentDashboard
    ) {
        return Inertia::render(
            'Student/MyCourses',
            [
                'studentCourses' =>
                    $studentDashboard
                        ->coursesForUser(
                            $request->user()
                        ),
            ]
        );
    }
)->name('student.courses.index');

    Route::get('/my-courses/{course}', function () {
        return Inertia::render('Student/CourseDetails');
    })->name('student.courses.show');

    Route::get('/my-schedule', function () {
        return Inertia::render('Student/MySchedule');
    })->name('student.schedule');

    Route::get('/my-attendance', function () {
        return Inertia::render('Student/MyAttendance');
    })->name('student.attendance');

    Route::get('/payments', function () {
        return Inertia::render('Student/Payments');
    })->name('student.payments');

    Route::get('/student/profile', function () {
        return Inertia::render('Student/Profile');
    })->name('student.profile');

    Route::get('/student/profile/edit', function () {
        return Inertia::render('Student/EditProfile');
    })->name('student.profile.edit');
});

/*
|--------------------------------------------------------------------------
| Account profile
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

    Route::delete(
        '/profile',
        [ProfileController::class, 'destroy']
    )->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Administration
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
    ->whereNumber('registrationRequest')
    ->name('admin.registration-requests.personal-picture');

require __DIR__ . '/auth.php';