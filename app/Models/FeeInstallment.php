<?php

namespace App\Models;

use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\FeeInstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeInstallment extends Model
{
    /** @use HasFactory<FeeInstallmentFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'enrollment_fee_id',
        'sequence_number',
        'due_date',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' =>
            'integer',

            'due_date' =>
            'date',

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

    public function enrollmentFee(): BelongsTo
    {
        return $this->belongsTo(
            EnrollmentFee::class
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(
            PaymentAllocation::class
        );
    }
}
