<?php

namespace App\Models;

use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'name',
        'code',
        'capacity',
        'location',
        'availability_status',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'availability_status' => ClassroomAvailabilityStatus::class,
            'status' => ClassroomStatus::class,
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

    public function assignedCourseClasses(): HasMany
    {
        return $this->hasMany(
            CourseClass::class,
            'assigned_classroom_id'
        );
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(
            ClassSchedule::class
        );
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(
            ClassSession::class
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === ClassroomStatus::Active;
    }

    public function isAvailable(): bool
    {
        return $this->availability_status
            === ClassroomAvailabilityStatus::Available;
    }
}
