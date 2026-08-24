<?php

namespace App\Models;

use App\Support\Traits\HasCenterScope;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
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
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(
            Student::class
        );
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(
            Teacher::class
        );
    }

    public function branchManager(): HasOne
    {
        return $this->hasOne(
            BranchManager::class
        );
    }

    public function financeEmployee(): HasOne
    {
        return $this->hasOne(
            FinanceEmployee::class
        );
    }
}
