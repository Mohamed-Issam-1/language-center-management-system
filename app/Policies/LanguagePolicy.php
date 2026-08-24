<?php

namespace App\Policies;

use App\Models\Language;
use App\Models\User;
use App\Support\Enums\SystemPermission;

class LanguagePolicy
{
    public function create(
        User $user
    ): bool {
        return $this->canManageCenter(
            $user
        );
    }

    public function update(
        User $user,
        Language $language
    ): bool {
        return $this->canManage(
            $user,
            $language
        );
    }

    public function archive(
        User $user,
        Language $language
    ): bool {
        return $this->canManage(
            $user,
            $language
        );
    }

    public function restore(
        User $user,
        Language $language
    ): bool {
        return $this->canManage(
            $user,
            $language
        );
    }

    private function canManage(
        User $user,
        Language $language
    ): bool {
        return $this->canManageCenter(
            $user
        )
            && $user->center_id
            === $language->center_id;
    }

    private function canManageCenter(
        User $user
    ): bool {
        return $user->hasPermission(
            SystemPermission::ManageAcademicStructure
        )
            && $user->center_id !== null;
    }
}
