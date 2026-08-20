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
    ];

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
}
