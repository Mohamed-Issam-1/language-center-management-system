<?php

namespace App\Policies;

use App\Models\Center;
use App\Models\User;
use App\Support\Enums\SystemPermission;

class CenterPolicy
{
    public function create(
        User $user
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageCenters
        );
    }

    public function update(
        User $user,
        Center $center
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageCenters
        );
    }

    public function activate(
        User $user,
        Center $center
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageCenters
        );
    }

    public function suspend(
        User $user,
        Center $center
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageCenters
        );
    }
}
