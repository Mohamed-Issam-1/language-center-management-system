<?php

namespace App\Models;

use App\Support\Traits\HasCenterScope;
use Database\Factories\AttendanceStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceStatus extends Model
{
    /** @use HasFactory<AttendanceStatusFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'name',
        'code',
        'contribution_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'contribution_value' =>
            'decimal:2',

            'is_active' =>
            'boolean',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(
            Attendance::class
        );
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
