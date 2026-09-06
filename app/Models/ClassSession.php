<?php

namespace App\Models;

use App\Support\Enums\ClassSessionStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\ClassSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    /** @use HasFactory<ClassSessionFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'class_id',
        'schedule_id',
        'occurrence_date',
        'classroom_id',
        'teacher_id',
        'session_date',
        'start_time',
        'end_time',
        'topic',
        'session_status',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
            'session_date' => 'date',

            'session_status' =>
            ClassSessionStatus::class,
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

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(
            ClassSchedule::class,
            'schedule_id'
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

    public function isScheduled(): bool
    {
        return $this->session_status
            === ClassSessionStatus::Scheduled;
    }

    public function isCompleted(): bool
    {
        return $this->session_status
            === ClassSessionStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->session_status
            === ClassSessionStatus::Cancelled;
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(
            Attendance::class,
            'session_id'
        );
    }
}
