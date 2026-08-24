<?php

namespace App\Models;

use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\AuditRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditRecord extends Model
{
    /** @use HasFactory<AuditRecordFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    public $timestamps = false;

    protected $fillable = [
        'center_id',
        'branch_id',
        'actor_user_id',
        'actor_role',
        'action_type',
        'subject_type',
        'subject_id',
        'before_values',
        'after_values',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Audit Records are append-only application history.
         *
         * Normal Eloquent workflows must never modify or delete
         * an event after it has been recorded.
         */
        static::updating(
            function (): never {
                throw new LogicException(
                    'Audit records cannot be modified.'
                );
            }
        );

        static::deleting(
            function (): never {
                throw new LogicException(
                    'Audit records cannot be deleted.'
                );
            }
        );
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }
}
