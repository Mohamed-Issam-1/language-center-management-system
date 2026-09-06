<?php

namespace App\Models;

use App\Support\Traits\HasCenterScope;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory, HasCenterScope;

    public const CREATED_AT =
    'recorded_at';

    public const UPDATED_AT =
    'updated_at';

    protected $fillable = [
        'center_id',
        'session_id',
        'enrollment_id',
        'attendance_status_id',
        'recorded_by_user_id',
        'late_minutes',
        'excuse',
        'notes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'late_minutes' =>
            'integer',

            'recorded_at' =>
            'datetime',

            'updated_at' =>
            'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            ClassSession::class,
            'session_id'
        );
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            Enrollment::class
        );
    }

    public function attendanceStatus(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceStatus::class
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by_user_id'
        );
    }
}
