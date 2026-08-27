<?php

namespace App\Models;

use App\Support\Enums\ClassScheduleStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\ClassScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSchedule extends Model
{
    /** @use HasFactory<ClassScheduleFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'class_id',
        'classroom_id',
        'teacher_id',
        'day_of_week',
        'start_time',
        'end_time',
        'effective_from',
        'effective_until',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'status' => ClassScheduleStatus::class,
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(
            CourseClass::class,
            'class_id'
        );
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(
            Classroom::class
        );
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class
        );
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(
            ClassSession::class,
            'schedule_id'
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === ClassScheduleStatus::Active;
    }

    public function isCancelled(): bool
    {
        return $this->status
            === ClassScheduleStatus::Cancelled;
    }
}
