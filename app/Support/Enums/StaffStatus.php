<?php

namespace App\Support\Enums;

enum StaffStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Deactivated => 'Deactivated',
        };
    }
}