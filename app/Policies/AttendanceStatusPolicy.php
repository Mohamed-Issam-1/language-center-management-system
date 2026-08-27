<?php

namespace App\Policies;

use App\Models\AttendanceStatus;
use App\Models\Center;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class AttendanceStatusPolicy
{
    public function create(
        User $user,
        Center $center
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageAttendanceSettings
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id !== $center->id
        ) {
            return false;
        }

        return $user->systemRole()
            === SystemRole::CenterOwner;
    }

    public function update(
        User $user,
        AttendanceStatus $status
    ): bool {
        return $this->canManage(
            $user,
            $status
        );
    }

    public function activate(
        User $user,
        AttendanceStatus $status
    ): bool {
        return $this->canManage(
            $user,
            $status
        );
    }

    public function deactivate(
        User $user,
        AttendanceStatus $status
    ): bool {
        return $this->canManage(
            $user,
            $status
        );
    }

    private function canManage(
        User $user,
        AttendanceStatus $status
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageAttendanceSettings
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $status->center_id
        ) {
            return false;
        }

        return $user->systemRole()
            === SystemRole::CenterOwner;
    }
}
