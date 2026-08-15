<?php

namespace App\Models;

use App\Support\Enums\CenterStatus;
use Database\Factories\CenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Center extends Model
{
    /** @use HasFactory<CenterFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'address',
        'timezone',
        'status',
        'operating_currency_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => CenterStatus::class,
        ];
    }
}
