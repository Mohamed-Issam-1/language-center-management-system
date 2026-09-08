<?php

namespace App\Services\Students;

use App\Models\Center;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class StudentFinanceReadService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FinanceReadService $finance
    ) {}

    /**
     * Return the authenticated Student's financial position
     * and immutable Payment history.
     *
     * Balance calculations are delegated to FinanceReadService.
     *
     * Historical Posted and Reversed Payments remain visible.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     balance: array<string, mixed>,
     *     payments: array<int, array<string, mixed>>
     * }
     */
    public function overview(
        User $actor
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $context['student'],
                $context['center']
            ),

            /*
             * FinanceReadService remains the authoritative
             * source for:
             *
             * - active financial obligations;
             * - Posted allocations;
             * - outstanding balances;
             * - overdue status;
             * - currency-separated totals.
             */
            'balance' =>
            $this->finance
                ->studentBalance(
                    $context['actor'],
                    $context['student']
                ),

            /*
             * Payment history is intentionally historical.
             *
             * Reversed Payments must remain visible instead
             * of disappearing from the Student's records.
             */
            'payments' =>
            $this->paymentHistory(
                $context['student'],
                $context['center_id']
            ),
        ];
    }

    /**
     * Return one Student-owned Receipt.
     *
     * FinanceReadService performs authoritative Receipt
     * validation and PaymentPolicy authorization.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     receipt: array<string, mixed>
     * }
     */
    public function receipt(
        User $actor,
        string $receiptNumber
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $receipt =
            $this->finance
            ->receipt(
                $context['actor'],
                $receiptNumber
            );

        /*
         * PaymentPolicy already enforces Student ownership.
         *
         * This additional invariant keeps the Student-facing
         * service fail-closed if that lower-level contract is
         * ever changed in the future.
         */
        if (
            (int) $receipt['student']['id']
            !== (int) $context['student']->id
        ) {
            throw new AuthorizationException(
                'The Receipt is outside the authenticated Student scope.'
            );
        }

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $context['student'],
                $context['center']
            ),

            'receipt' =>
            $receipt,
        ];
    }

    /**
     * Return Payment history for the exact Student record.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentHistory(
        Student $student,
        int $centerId
    ): array {
        return Payment::query()
            ->withoutGlobalScopes()
            ->with([
                'branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->orderByDesc(
                'paid_at'
            )
            ->orderByDesc(
                'id'
            )
            ->get()
            ->map(
                fn(
                    Payment $payment
                ): array =>
                $this->paymentPayload(
                    $payment
                )
            )
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(
        Payment $payment
    ): array {
        $branch =
            $payment->branch;

        if ($branch === null) {
            throw new LogicException(
                'The Student Payment references a missing Branch.'
            );
        }

        return [
            'payment_id' =>
            (int) $payment->id,

            'receipt_number' =>
            $payment
                ->receipt_number,

            'branch' => [
                'id' =>
                (int) $branch->id,

                'code' =>
                $branch->code,

                'name' =>
                $branch->name,
            ],

            'amount' =>
            $payment->amount,

            'currency_code' =>
            $payment
                ->currency_code,

            'payment_method' =>
            $payment
                ->payment_method,

            'paid_at' =>
            $payment
                ->paid_at
                ?->toDateTimeString(),

            'reference' =>
            $payment->reference,

            'notes' =>
            $payment->notes,

            'status' =>
            $payment
                ->status
                ->value,

            'status_label' =>
            $payment
                ->status
                ->label(),

            /*
             * Reversal history remains explicit instead of
             * altering or deleting the original Payment.
             */
            'reversal' =>
            $payment->isReversed()
                ? [
                    'reversed_at' =>
                    $payment
                        ->reversed_at
                        ?->toDateTimeString(),

                    'reason' =>
                    $payment
                        ->reversal_reason,
                ]
                : null,
        ];
    }

    /**
     * Resolve the exact persisted Active Student account.
     *
     * Never trust mutable in-memory User state.
     *
     * @return array{
     *     actor: User,
     *     student: Student,
     *     center: Center,
     *     center_id: int
     * }
     */
    private function authorizedStudentContext(
        User $actor
    ): array {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Student Finance reads require a persisted User Account.'
            );
        }

        $persistedActor =
            User::query()
            ->withoutGlobalScopes()
            ->with(
                'role'
            )
            ->whereKey(
                $actor->getKey()
            )
            ->first();

        if ($persistedActor === null) {
            throw new AuthorizationException(
                'The Student User Account could not be resolved.'
            );
        }

        if (
            $persistedActor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an Active User Account may read Student Finance.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'The account is not authorized for Student Finance.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Student Finance reads require a Center-scoped tenant context.'
            );
        }

        $center =
            $this->tenant
            ->center();

        $centerId =
            $this->tenant
            ->centerId();

        if (
            $center === null
            || $centerId === null
            || $persistedActor->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'Authenticated Student account and tenant context do not match.'
            );
        }

        if (
            $persistedActor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Student Finance requires a linked Person identity.'
            );
        }

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->with([
                'person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $persistedActor->id
            )
            ->where(
                'person_id',
                $persistedActor->person_id
            )
            ->where(
                'status',
                StudentStatus::Active->value
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'Student Finance requires an Active Student record linked to this exact account and Person.'
            );
        }

        return [
            'actor' =>
            $persistedActor,

            'student' =>
            $student,

            'center' =>
            $center,

            'center_id' =>
            (int) $centerId,
        ];
    }

    /**
     * Keep Student identity consistent with the other
     * Student-facing backend read services.
     *
     * @return array<string, mixed>
     */
    private function studentPayload(
        User $actor,
        Student $student,
        Center $center
    ): array {
        return [
            'id' =>
            (int) $student->id,

            'person_id' =>
            (int) $student->person_id,

            'user_id' =>
            (int) $student->user_id,

            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            'name' =>
            $student
                ->person
                ?->full_name
                ?? $actor->name,

            'status' =>
            $student
                ->status
                ->value,

            'center' => [
                'id' =>
                (int) $center->id,

                'code' =>
                $center->code,

                'name' =>
                $center->name,
            ],

            'branch' => [
                'id' =>
                (int) $student
                    ->branch_id,

                'code' =>
                $student
                    ->branch
                    ?->code,

                'name' =>
                $student
                    ->branch
                    ?->name,
            ],
        ];
    }
}
