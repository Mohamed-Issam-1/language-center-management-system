<?php

namespace App\Models;

use App\Support\Enums\StudentStatus;
use App\Support\Traits\HasBranchScope;
use App\Support\Traits\HasCenterScope;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasCenterScope, HasBranchScope;

    protected $fillable = [
        'center_id',
        'branch_id',
        'person_id',
        'user_id',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StudentStatus::class,
            'archived_at' => 'datetime',
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

    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === StudentStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status
            === StudentStatus::Archived;
    }
}
