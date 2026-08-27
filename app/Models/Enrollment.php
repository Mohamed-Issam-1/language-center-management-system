<?php

namespace App\Models;

use App\Support\Enums\EnrollmentStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'student_id',
        'class_id',
        'enrollment_number',
        'enrollment_date',
        'enrollment_status',
        'eligibility_status',
        'withdrawal_date',
        'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_date' => 'date',

            'enrollment_status' =>
            EnrollmentStatus::class,

            'withdrawal_date' => 'date',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(
            Student::class
        );
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(
            CourseClass::class,
            'class_id'
        );
    }

    public function histories(): HasMany
    {
        return $this->hasMany(
            EnrollmentHistory::class
        );
    }

    public function isActive(): bool
    {
        return $this->enrollment_status
            === EnrollmentStatus::Active;
    }

    public function isCompleted(): bool
    {
        return $this->enrollment_status
            === EnrollmentStatus::Completed;
    }

    public function isWithdrawn(): bool
    {
        return $this->enrollment_status
            === EnrollmentStatus::Withdrawn;
    }

    public function isTransferred(): bool
    {
        return $this->enrollment_status
            === EnrollmentStatus::Transferred;
    }

    public function isCancelled(): bool
    {
        return $this->enrollment_status
            === EnrollmentStatus::Cancelled;
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(
            Attendance::class
        );
    }
}
