<?php

namespace App\Models;

use App\Support\Enums\AcademicRecordStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\LanguageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'name',
        'code',
        'description',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' =>
            AcademicRecordStatus::class,

            'archived_at' =>
            'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(
            Center::class
        );
    }

    public function academicLevels(): HasMany
    {
        return $this->hasMany(
            AcademicLevel::class
        );
    }

    public function courses(): HasMany
    {
        return $this->hasMany(
            Course::class
        );
    }

    public function isActive(): bool
    {
        return $this->status
            === AcademicRecordStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status
            === AcademicRecordStatus::Archived;
    }
}
