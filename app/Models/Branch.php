<?php

namespace App\Models;

use App\Support\Enums\BranchStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function selectedRegistrationRequests(): HasMany
    {
        return $this->hasMany(
            RegistrationRequest::class,
            'selected_branch_id'
        );
    }

    public function branchManagerAssignments(): HasMany
    {
        return $this->hasMany(
            BranchManagerAssignment::class
        );
    }

    public function activeBranchManagerAssignment(): HasOne
    {
        return $this->hasOne(
            BranchManagerAssignment::class
        )
            ->where('active_marker', 1)
            ->whereNull('ended_at');
    }

    public function financeEmployeeAssignments(): HasMany
    {
        return $this->hasMany(
            FinanceEmployeeAssignment::class
        );
    }

    public function activeFinanceEmployeeAssignments(): HasMany
    {
        return $this->hasMany(
            FinanceEmployeeAssignment::class
        )
            ->where('active_marker', 1)
            ->whereNull('ended_at');
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(
            Classroom::class
        );
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(
            CourseClass::class
        );
    }

    public function students(): HasMany
    {
        return $this->hasMany(
            Student::class
        );
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }
}
