<?php

namespace App\Models;

use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'payment_id',
        'fee_installment_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' =>
            'decimal:2',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class
        );
    }

    public function feeInstallment(): BelongsTo
    {
        return $this->belongsTo(
            FeeInstallment::class
        );
    }
}
