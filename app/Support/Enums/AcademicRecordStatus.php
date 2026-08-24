<?php

namespace App\Support\Enums;

enum AcademicRecordStatus: string
{
    case Active = 'active';

    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}