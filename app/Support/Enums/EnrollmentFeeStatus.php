<?php

namespace App\Support\Enums;

enum EnrollmentFeeStatus: string
{
    case Active = 'active';

    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Voided => 'Voided',
        };
    }
}
