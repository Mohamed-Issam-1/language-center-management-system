<?php

namespace App\Models;

use App\Support\Enums\BranchStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'name',
        'code',
        'phone',
        'email',
        'address',
        'working_hours',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'working_hours' => 'array',
            'status' => BranchStatus::class,
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }
}
