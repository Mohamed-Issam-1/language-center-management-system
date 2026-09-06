<?php

namespace App\Models;

use App\Support\Traits\HasCenterScope;
use App\Support\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceEmployeeAssignment extends Model
{
    use HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'user_id',
        'branch_id',
        'started_at',
        'ended_at',
        'active_marker',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'active_marker' => 'integer',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query
            ->where('active_marker', 1)
            ->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->active_marker === 1
            && $this->ended_at === null;
    }
}
