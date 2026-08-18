<?php

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
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/demo/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('demo.dashboard');
Route::get('/demo/courses', function () {
    return Inertia::render('Student/MyCourses');
})->name('demo.courses');
Route::get('/my-courses/{course}', function () {
    return Inertia::render('Student/CourseDetails');
})->middleware(['auth', 'verified'])->name('student.courses.show');

Route::get('/demo/courses/{course}', function () {
    return Inertia::render('Student/CourseDetails');
})->name('demo.courses.show');


Route::get('/my-schedule', function () {
    return Inertia::render('Student/MySchedule');
})->middleware(['auth', 'verified'])->name('student.schedule');


Route::get('/demo/schedule', function () {
    return Inertia::render('Student/MySchedule');
})->name('demo.schedule');


Route::get('/my-attendance', function () {
    return Inertia::render('Student/MyAttendance');
})->middleware(['auth', 'verified'])->name('student.attendance');

Route::get('/demo/attendance', function () {
    return Inertia::render('Student/MyAttendance');
})->name('demo.attendance');

Route::get('/payments', function () {
    return Inertia::render('Student/Payments');
})->middleware(['auth', 'verified'])->name('student.payments');

Route::get('/demo/payments', function () {
    return Inertia::render('Student/Payments');
})->name('demo.payments');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
