<?php

namespace App\Support\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';
    case Transferred = 'transferred';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Withdrawn => 'Withdrawn',
            self::Transferred => 'Transferred',
            self::Cancelled => 'Cancelled',
        };
    }
}
