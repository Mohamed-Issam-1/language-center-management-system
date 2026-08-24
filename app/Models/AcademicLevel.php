<?php

namespace App\Models;

use App\Support\Enums\AcademicRecordStatus;
use App\Support\Traits\HasCenterScope;
use Database\Factories\AcademicLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicLevel extends Model
{
    /** @use HasFactory<AcademicLevelFactory> */
    use HasFactory, HasCenterScope;

    protected $fillable = [
        'center_id',
        'language_id',
        'name',
        'code',
        'sequence_number',
        'description',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' =>
            'integer',

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

    public function language(): BelongsTo
    {
        return $this->belongsTo(
            Language::class
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
