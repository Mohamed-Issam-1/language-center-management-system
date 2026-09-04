<?php

namespace App\Support\Enums;

enum PaymentStatus: string
{
    case Posted = 'posted';

    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
