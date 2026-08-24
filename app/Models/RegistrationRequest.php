<?php

namespace App\Models;

use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\RegistrationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationRequest extends Model
{
    /** @use HasFactory<RegistrationRequestFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'national_id_number',
        'full_name',
        'date_of_birth',
        'city_of_residence',
        'email',
        'phone_number',
        'personal_picture_path',
        'status',
        'selected_role_id',
        'selected_branch_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_reason',
        'pending_marker',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',

            'status' =>
            RegistrationRequestStatus::class,

            'reviewed_at' => 'datetime',

            'pending_marker' => 'integer',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function selectedRole(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'selected_role_id'
        );
    }

    public function selectedBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'selected_branch_id'
        );
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by_user_id'
        );
    }

    public function isPending(): bool
    {
        return $this->status
            === RegistrationRequestStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status
            === RegistrationRequestStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status
            === RegistrationRequestStatus::Rejected;
    }
}
