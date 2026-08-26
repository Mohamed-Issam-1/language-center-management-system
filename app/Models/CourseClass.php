<?php

namespace App\Models;

use App\Support\Enums\CourseClassStatus;
use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\CourseClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseClass extends Model
{
    /** @use HasFactory<CourseClassFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'course_id',
        'assigned_classroom_id',
        'assigned_teacher_id',
        'class_code',
        'name',
        'start_date',
        'end_date',
        'capacity',
        'delivery_mode',
        'class_status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',

            'class_status' =>
            CourseClassStatus::class,
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class
        );
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(
            Course::class
        );
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class,
            'class_id'
        );
    }

    public function assignedClassroom(): BelongsTo
    {
        return $this->belongsTo(
            Classroom::class,
            'assigned_classroom_id'
        );
    }

    public function assignedTeacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'assigned_teacher_id'
        );
    }

    public function isPlanned(): bool
    {
        return $this->class_status
            === CourseClassStatus::Planned;
    }

    public function isActive(): bool
    {
        return $this->class_status
            === CourseClassStatus::Active;
    }

    public function isCompleted(): bool
    {
        return $this->class_status
            === CourseClassStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->class_status
            === CourseClassStatus::Cancelled;
    }
}
