<?php

namespace App\Models;

use App\Support\Enums\PaymentStatus;
use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'student_id',
        'receipt_number',
        'idempotency_key',
        'amount',
        'currency_code',
        'payment_method',
        'paid_at',
        'received_by_user_id',
        'reference',
        'notes',
        'status',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' =>
            'decimal:2',

            'paid_at' =>
            'datetime',

            'status' =>
            PaymentStatus::class,

            'reversed_at' =>
            'datetime',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(
            Student::class
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by_user_id'
        );
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reversed_by_user_id'
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(
            PaymentAllocation::class
        );
    }

    public function isPosted(): bool
    {
        return $this->status
            === PaymentStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status
            === PaymentStatus::Reversed;
    }
}
