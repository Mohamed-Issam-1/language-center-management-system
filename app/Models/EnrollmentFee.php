<?php

namespace App\Models;

use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\EnrollmentFeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnrollmentFee extends Model
{
    /** @use HasFactory<EnrollmentFeeFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'enrollment_id',
        'amount',
        'currency_code',
        'status',
        'created_by_user_id',
        'voided_by_user_id',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' =>
            'decimal:2',

            'status' =>
            EnrollmentFeeStatus::class,

            'voided_at' =>
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

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            Enrollment::class
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'voided_by_user_id'
        );
    }

    public function installments(): HasMany
    {
        return $this->hasMany(
            FeeInstallment::class
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === EnrollmentFeeStatus::Active;
    }

    public function isVoided(): bool
    {
        return $this->status
            === EnrollmentFeeStatus::Voided;
    }
}
