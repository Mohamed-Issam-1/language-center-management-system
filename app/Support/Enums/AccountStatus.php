<?php

namespace App\Support\Enums;

enum AccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Deactivated => 'Deactivated',
        };
    }
}
