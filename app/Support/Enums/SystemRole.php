<?php

namespace App\Support\Enums;

enum SystemRole: string
{
    case PlatformOwner = 'platform_owner';
    case CenterOwner = 'center_owner';
    case BranchManager = 'branch_manager';
    case FinanceEmployee = 'finance_employee';
    case Teacher = 'teacher';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::PlatformOwner => 'Platform Owner',
            self::CenterOwner => 'Center Owner',
            self::BranchManager => 'Branch Manager',
            self::FinanceEmployee => 'Finance Employee',
            self::Teacher => 'Teacher',
            self::Student => 'Student',
        };
    }
}
