<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class PaymentPolicy
{
    public function view(
        User $user,
        Payment $payment
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $payment->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerHasAccess(
                $user,
                $payment->center_id,
                $payment->branch_id
            ),

            SystemRole::FinanceEmployee =>
            $this->financeEmployeeHasAccess(
                $user,
                $payment->center_id,
                $payment->branch_id
            ),

            SystemRole::Student =>
            $this->isPaymentStudent(
                $user,
                $payment
            ),

            default =>
            false,
        };
    }

    public function create(
        User $user,
        Student $student,
        Branch $branch
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        ) {
            return false;
        }

        if (
            $student->center_id
            !== $branch->center_id
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $student->center_id
        ) {
            return false;
        }

        return $this->canManageBranch(
            $user,
            $branch->center_id,
            $branch->id
        );
    }

    public function reverse(
        User $user,
        Payment $payment
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $payment->center_id
        ) {
            return false;
        }

        return $this->canManageBranch(
            $user,
            $payment->center_id,
            $payment->branch_id
        );
    }

    private function canManageBranch(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerHasAccess(
                $user,
                $centerId,
                $branchId
            ),

            SystemRole::FinanceEmployee =>
            $this->financeEmployeeHasAccess(
                $user,
                $centerId,
                $branchId
            ),

            default =>
            false,
        };
    }

    private function branchManagerHasAccess(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return $user
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->exists();
    }

    private function financeEmployeeHasAccess(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return FinanceEmployeeAssignment::query()
            ->withoutGlobalScopes()
            ->active()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->exists();
    }

    private function isPaymentStudent(
        User $user,
        Payment $payment
    ): bool {
        return Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $payment->student_id
            )
            ->where(
                'center_id',
                $payment->center_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();
    }
}
