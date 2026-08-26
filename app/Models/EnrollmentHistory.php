<?php

namespace App\Models;

use App\Support\Enums\EnrollmentStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\EnrollmentHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentHistory extends Model
{
    /** @use HasFactory<EnrollmentHistoryFactory> */
    use HasFactory, HasCenterScope;

    public $timestamps = false;

    protected $fillable = [
        'center_id',
        'enrollment_id',
        'from_class_id',
        'to_class_id',
        'performed_by_user_id',
        'event_type',
        'previous_status',
        'new_status',
        'notes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_status' =>
            EnrollmentStatus::class,

            'new_status' =>
            EnrollmentStatus::class,

            'occurred_at' => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            Enrollment::class
        );
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(
            CourseClass::class,
            'from_class_id'
        );
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(
            CourseClass::class,
            'to_class_id'
        );
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'performed_by_user_id'
        );
    }
}
