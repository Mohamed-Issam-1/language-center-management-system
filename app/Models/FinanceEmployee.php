<?php

namespace App\Models;

use App\Support\Enums\StaffStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\FinanceEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceEmployee extends Model
{
    /** @use HasFactory<FinanceEmployeeFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'person_id',
        'user_id',
        'status',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
            'deactivated_at' => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(
            Person::class
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === StaffStatus::Active;
    }

    public function isDeactivated(): bool
    {
        return $this->status
            === StaffStatus::Deactivated;
    }
}
