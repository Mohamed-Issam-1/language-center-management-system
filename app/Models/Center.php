<?php

namespace App\Models;

use App\Support\Enums\CenterStatus;
use Database\Factories\CenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Center extends Model
{
    /** @use HasFactory<CenterFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'identifier_code',
        'name',
        'email',
        'phone',
        'address',
        'timezone',
        'status',
        'operating_currency_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => CenterStatus::class,
        ];
    }

    public function people(): HasMany
    {
        return $this->hasMany(
            Person::class
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(
            User::class
        );
    }

    public function branches(): HasMany
    {
        return $this->hasMany(
            Branch::class
        );
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(
            Classroom::class
        );
    }

    public function languages(): HasMany
    {
        return $this->hasMany(
            Language::class
        );
    }

    public function academicLevels(): HasMany
    {
        return $this->hasMany(
            AcademicLevel::class
        );
    }

    public function courses(): HasMany
    {
        return $this->hasMany(
            Course::class
        );
    }

    public function students(): HasMany
    {
        return $this->hasMany(
            Student::class
        );
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class
        );
    }

    public function enrollmentHistories(): HasMany
    {
        return $this->hasMany(
            EnrollmentHistory::class
        );
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(
            CourseClass::class
        );
    }

    public function registrationRequests(): HasMany
    {
        return $this->hasMany(
            RegistrationRequest::class
        );
    }
}
