<?php

namespace App\Models;

use App\Support\Enums\AcademicRecordStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'language_id',
        'academic_level_id',
        'name',
        'code',
        'description',
        'duration_weeks',
        'total_hours',
        'default_fee',
        'passing_grade',
        'minimum_attendance',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_weeks' =>
            'integer',

            'total_hours' =>
            'decimal:2',

            'default_fee' =>
            'decimal:2',

            'passing_grade' =>
            'decimal:2',

            'minimum_attendance' =>
            'decimal:2',

            'status' =>
            AcademicRecordStatus::class,

            'archived_at' =>
            'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(
            Language::class
        );
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(
            AcademicLevel::class
        );
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'course_prerequisites',
            'course_id',
            'prerequisite_course_id'
        )
            ->withPivot([
                'id',
                'center_id',
                'requirement_type',
            ])
            ->withTimestamps();
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(
            CourseClass::class
        );
    }

    public function requiredByCourses(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'course_prerequisites',
            'prerequisite_course_id',
            'course_id'
        )
            ->withPivot([
                'id',
                'center_id',
                'requirement_type',
            ])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status
            === AcademicRecordStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status
            === AcademicRecordStatus::Archived;
    }
}
