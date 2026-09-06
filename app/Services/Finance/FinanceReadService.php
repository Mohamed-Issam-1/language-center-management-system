<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class FinanceReadService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext
    ) {}

    /**
     * Return the Student's financial position.
     *
     * Totals are intentionally separated by currency.
     *
     * @return array<string, mixed>
     */
    public function studentBalance(
        User $actor,
        Student $student
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $student =
            $this->persistedStudent(
                $student,
                $centerId
            );

        $branch =
            $this->summaryBranchForActor(
                $actor,
                $centerId
            );

        Gate::forUser($actor)
            ->authorize(
                'viewStudentSummary',
                [
                    EnrollmentFee::class,
                    $student,
                    $branch,
                ]
            );

        $enrollmentIds =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->pluck('id');

        $feeQuery =
            EnrollmentFee::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                EnrollmentFeeStatus::Active
                    ->value
            )
            ->whereIn(
                'enrollment_id',
                $enrollmentIds
            );

        if ($branch !== null) {
            $feeQuery->where(
                'branch_id',
                $branch->id
            );
        }

        $fees =
            $feeQuery
            ->orderBy('id')
            ->get();

        if ($fees->isEmpty()) {
            return [
                'center_id' =>
                $centerId,

                'student_id' =>
                (int) $student->id,

                'totals' =>
                [],

                'installments' =>
                [],
            ];
        }

        $feesById =
            $fees->keyBy('id');

        $installments =
            FeeInstallment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'enrollment_fee_id',
                $fees->pluck('id')
            )
            ->orderBy(
                'enrollment_fee_id'
            )
            ->orderBy(
                'sequence_number'
            )
            ->get();

        if ($installments->isEmpty()) {
            return [
                'center_id' =>
                $centerId,

                'student_id' =>
                (int) $student->id,

                'totals' =>
                [],

                'installments' =>
                [],
            ];
        }

        $enrollmentsById =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $fees
                    ->pluck(
                        'enrollment_id'
                    )
            )
            ->get([
                'id',
                'enrollment_number',
            ])
            ->keyBy(
                'id'
            );

        $branchesById =
            Branch::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $installments
                    ->pluck(
                        'branch_id'
                    )
                    ->unique()
                    ->values()
            )
            ->get([
                'id',
                'name',
            ])
            ->keyBy(
                'id'
            );

        $postedAmounts =
            DB::table(
                'payment_allocations'
            )
            ->join(
                'payments',
                'payments.id',
                '=',
                'payment_allocations.payment_id'
            )
            ->where(
                'payment_allocations.center_id',
                $centerId
            )
            ->whereIn(
                'payment_allocations.fee_installment_id',
                $installments->pluck('id')
            )
            ->where(
                'payments.center_id',
                $centerId
            )
            ->where(
                'payments.status',
                PaymentStatus::Posted->value
            )
            ->select(
                'payment_allocations.fee_installment_id',
                DB::raw(
                    'SUM(payment_allocations.amount) AS paid_amount'
                )
            )
            ->groupBy(
                'payment_allocations.fee_installment_id'
            )
            ->pluck(
                'paid_amount',
                'payment_allocations.fee_installment_id'
            );

        $totals = [];
        $rows = [];

        foreach (
            $installments as $installment
        ) {
            $fee =
                $feesById->get(
                    $installment
                        ->enrollment_fee_id
                );

            if ($fee === null) {
                throw new LogicException(
                    'Fee Installment is missing its active Enrollment Fee.'
                );
            }

            $enrollment =
                $enrollmentsById
                ->get(
                    $fee->enrollment_id
                );

            if ($enrollment === null) {
                throw new LogicException(
                    'Enrollment Fee is missing its Enrollment.'
                );
            }

            $financialBranch =
                $branchesById
                ->get(
                    $installment->branch_id
                );

            if ($financialBranch === null) {
                throw new LogicException(
                    'Fee Installment is missing its financial Branch.'
                );
            }

            if (
                $installment->center_id
                !== $fee->center_id
                || $installment->branch_id
                !== $fee->branch_id
            ) {
                throw new LogicException(
                    'Fee Installment scope does not match its Enrollment Fee.'
                );
            }

            $amountCents =
                $this->moneyToCents(
                    $installment->amount,
                    'Installment amount'
                );

            $paidCents =
                $this->moneyToCents(
                    $postedAmounts->get(
                        $installment->id,
                        '0.00'
                    ),
                    'Posted allocation amount'
                );

            if ($paidCents > $amountCents) {
                throw new LogicException(
                    'Posted Payment allocations exceed the Installment amount.'
                );
            }

            $balanceCents =
                $amountCents
                - $paidCents;
            $isOverdue =
                $balanceCents > 0
                && $installment
                ->due_date
                ->isBefore(
                    today()
                );

            $installmentStatus =
                match (true) {
                    $balanceCents === 0 =>
                    'paid',

                    $isOverdue =>
                    'overdue',

                    default =>
                    'outstanding',
                };

            $currency =
                strtoupper(
                    trim(
                        $fee->currency_code
                    )
                );

            if ($currency === '') {
                throw new LogicException(
                    'Enrollment Fee currency is missing.'
                );
            }

            if (
                ! array_key_exists(
                    $currency,
                    $totals
                )
            ) {
                $totals[$currency] = [
                    'obligation_cents' =>
                    0,

                    'paid_cents' =>
                    0,

                    'balance_cents' =>
                    0,
                ];
            }

            $totals[$currency]['obligation_cents'] += $amountCents;

            $totals[$currency]['paid_cents'] += $paidCents;

            $totals[$currency]['balance_cents'] += $balanceCents;

            $rows[] = [
                'fee_id' =>
                (int) $fee->id,

                'enrollment_id' =>
                (int) $fee
                    ->enrollment_id,

                'enrollment_number' =>
                $enrollment
                    ->enrollment_number,

                'installment_id' =>
                (int) $installment->id,

                'branch_id' =>
                (int) $installment
                    ->branch_id,

                'branch_name' =>
                $financialBranch->name,

                'sequence_number' =>
                (int) $installment
                    ->sequence_number,

                'due_date' =>
                $installment
                    ->due_date
                    ->toDateString(),

                'is_overdue' =>
                $isOverdue,

                'status' =>
                $installmentStatus,

                'currency_code' =>
                $currency,

                'amount' =>
                $this->centsToMoney(
                    $amountCents
                ),

                'paid' =>
                $this->centsToMoney(
                    $paidCents
                ),

                'balance' =>
                $this->centsToMoney(
                    $balanceCents
                ),
            ];
        }

        $formattedTotals = [];

        foreach (
            $totals as $currency =>
            $values
        ) {
            $formattedTotals[$currency] = [
                'obligation' =>
                $this->centsToMoney(
                    $values['obligation_cents']
                ),

                'paid' =>
                $this->centsToMoney(
                    $values['paid_cents']
                ),

                'balance' =>
                $this->centsToMoney(
                    $values['balance_cents']
                ),
            ];
        }

        ksort(
            $formattedTotals
        );

        return [
            'center_id' =>
            $centerId,

            'student_id' =>
            (int) $student->id,

            'totals' =>
            $formattedTotals,

            'installments' =>
            $rows,
        ];
    }

    /**
     * Resolve a persisted Payment by Receipt number and
     * return its immutable financial details.
     *
     * @return array<string, mixed>
     */
    public function receipt(
        User $actor,
        string $receiptNumber
    ): array {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $receiptNumber =
            trim(
                $receiptNumber
            );

        if ($receiptNumber === '') {
            throw new DomainException(
                'Receipt number is required.'
            );
        }

        if (
            mb_strlen(
                $receiptNumber
            ) > 50
        ) {
            throw new DomainException(
                'Receipt number must not exceed 50 characters.'
            );
        }

        $payment =
            Payment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'receipt_number',
                $receiptNumber
            )
            ->first();

        if ($payment === null) {
            throw new AuthorizationException(
                'The Receipt is not available in the authorized financial scope.'
            );
        }

        Gate::forUser($actor)
            ->authorize(
                'view',
                $payment
            );

        $this->ensureReceiptOperationalScope(
            $actor,
            $payment
        );

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $payment->student_id
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($student === null) {
            throw new LogicException(
                'Receipt Payment is missing its Student.'
            );
        }

        $allocations =
            PaymentAllocation::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'payment_id',
                $payment->id
            )
            ->orderBy('id')
            ->get();

        $installmentIds =
            $allocations->pluck(
                'fee_installment_id'
            );

        $installments =
            FeeInstallment::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $installmentIds
            )
            ->get()
            ->keyBy('id');

        $feeIds =
            $installments
            ->pluck(
                'enrollment_fee_id'
            )
            ->unique()
            ->values();

        $fees =
            EnrollmentFee::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $feeIds
            )
            ->get()
            ->keyBy('id');

        $allocationRows = [];

        foreach (
            $allocations as $allocation
        ) {
            $installment =
                $installments->get(
                    $allocation
                        ->fee_installment_id
                );

            if ($installment === null) {
                throw new LogicException(
                    'Receipt allocation is missing its Fee Installment.'
                );
            }

            $fee =
                $fees->get(
                    $installment
                        ->enrollment_fee_id
                );

            if ($fee === null) {
                throw new LogicException(
                    'Receipt allocation is missing its Enrollment Fee.'
                );
            }

            if (
                $allocation->branch_id
                !== $payment->branch_id
                || $installment->branch_id
                !== $payment->branch_id
                || $fee->branch_id
                !== $payment->branch_id
            ) {
                throw new LogicException(
                    'Receipt financial records have inconsistent Branch ownership.'
                );
            }

            if (
                $allocation->center_id
                !== $centerId
                || $installment->center_id
                !== $centerId
                || $fee->center_id
                !== $centerId
            ) {
                throw new LogicException(
                    'Receipt financial records have inconsistent Center ownership.'
                );
            }

            $enrollment =
                Enrollment::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    $fee->enrollment_id
                )
                ->where(
                    'center_id',
                    $centerId
                )
                ->first();

            if ($enrollment === null) {
                throw new LogicException(
                    'Receipt Fee is missing its Enrollment.'
                );
            }

            if (
                $enrollment->student_id
                !== $payment->student_id
            ) {
                throw new LogicException(
                    'Receipt allocation belongs to a different Student.'
                );
            }

            $allocationRows[] = [
                'allocation_id' =>
                (int) $allocation->id,

                'amount' =>
                $allocation->amount,

                'installment_id' =>
                (int) $installment->id,

                'installment_sequence' =>
                (int) $installment
                    ->sequence_number,

                'installment_due_date' =>
                $installment
                    ->due_date
                    ->toDateString(),

                'fee_id' =>
                (int) $fee->id,

                'enrollment_id' =>
                (int) $fee
                    ->enrollment_id,

                'currency_code' =>
                $fee->currency_code,
            ];
        }

        return [
            'payment_id' =>
            (int) $payment->id,

            'receipt_number' =>
            $payment->receipt_number,

            'center_id' =>
            (int) $payment->center_id,

            'branch_id' =>
            (int) $payment->branch_id,

            'student' => [
                'id' =>
                (int) $student->id,

                'person_id' =>
                (int) $student
                    ->person_id,

                'user_id' =>
                $student->user_id,
            ],

            'amount' =>
            $payment->amount,

            'currency_code' =>
            $payment->currency_code,

            'payment_method' =>
            $payment->payment_method,

            'paid_at' =>
            $payment->paid_at
                ?->toDateTimeString(),

            'received_by_user_id' =>
            (int) $payment
                ->received_by_user_id,

            'reference' =>
            $payment->reference,

            'notes' =>
            $payment->notes,

            'status' =>
            $payment->status->value,

            'reversal' =>
            $payment->isReversed()
                ? [
                    'reversed_at' =>
                    $payment->reversed_at
                        ?->toDateTimeString(),

                    'reversed_by_user_id' =>
                    $payment
                        ->reversed_by_user_id,

                    'reason' =>
                    $payment
                        ->reversal_reason,
                ]
                : null,

            'allocations' =>
            $allocationRows,
        ];
    }

    /**
     * Return Center/Branch financial aggregates for operational
     * reporting and dashboards.
     *
     * Student finance remains available through studentBalance().
     * Platform Owner and Teacher do not receive tenant financial
     * aggregates.
     *
     * @return array{
     *     center_id: int,
     *     branch_id: int|null,
     *     currencies: array<string, array{
     *         active_fee_count: int,
     *         active_fee_amount: string,
     *         installment_count: int,
     *         installment_amount: string,
     *         allocated_amount: string,
     *         outstanding_amount: string,
     *         posted_payment_count: int,
     *         posted_payment_amount: string,
     *         reversed_payment_count: int,
     *         reversed_payment_amount: string
     *     }>
     * }
     */
    public function operationalSummary(
        User $actor
    ): array {
        if (
            ! in_array(
                $actor->systemRole(),
                [
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                    SystemRole::FinanceEmployee,
                ],
                true
            )
        ) {
            throw new AuthorizationException(
                'The account cannot view operational financial reports.'
            );
        }

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branch =
            $this->summaryBranchForActor(
                $actor,
                $centerId
            );

        $branchId =
            $branch === null
            ? null
            : (int) $branch->id;

        $feeQuery =
            DB::table(
                'enrollment_fees'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                EnrollmentFeeStatus::Active->value
            );

        if ($branchId !== null) {
            $feeQuery->where(
                'branch_id',
                $branchId
            );
        }

        $feeRows =
            $feeQuery
            ->select(
                'currency_code'
            )
            ->selectRaw(
                'COUNT(*) as aggregate_count'
            )
            ->selectRaw(
                'COALESCE(SUM(amount), 0) as aggregate_amount'
            )
            ->groupBy(
                'currency_code'
            )
            ->get()
            ->keyBy(
                'currency_code'
            );

        $installmentQuery =
            DB::table(
                'fee_installments as fi'
            )
            ->join(
                'enrollment_fees as ef',
                function ($join): void {
                    $join
                        ->on(
                            'ef.id',
                            '=',
                            'fi.enrollment_fee_id'
                        )
                        ->on(
                            'ef.center_id',
                            '=',
                            'fi.center_id'
                        )
                        ->on(
                            'ef.branch_id',
                            '=',
                            'fi.branch_id'
                        );
                }
            )
            ->where(
                'ef.center_id',
                $centerId
            )
            ->where(
                'ef.status',
                EnrollmentFeeStatus::Active->value
            );

        if ($branchId !== null) {
            $installmentQuery->where(
                'ef.branch_id',
                $branchId
            );
        }

        $installmentRows =
            $installmentQuery
            ->select(
                'ef.currency_code'
            )
            ->selectRaw(
                'COUNT(fi.id) as aggregate_count'
            )
            ->selectRaw(
                'COALESCE(SUM(fi.amount), 0) as aggregate_amount'
            )
            ->groupBy(
                'ef.currency_code'
            )
            ->get()
            ->keyBy(
                'currency_code'
            );

        $allocationQuery =
            DB::table(
                'payment_allocations as pa'
            )
            ->join(
                'payments as p',
                function ($join): void {
                    $join
                        ->on(
                            'p.id',
                            '=',
                            'pa.payment_id'
                        )
                        ->on(
                            'p.center_id',
                            '=',
                            'pa.center_id'
                        )
                        ->on(
                            'p.branch_id',
                            '=',
                            'pa.branch_id'
                        );
                }
            )
            ->join(
                'fee_installments as fi',
                function ($join): void {
                    $join
                        ->on(
                            'fi.id',
                            '=',
                            'pa.fee_installment_id'
                        )
                        ->on(
                            'fi.center_id',
                            '=',
                            'pa.center_id'
                        )
                        ->on(
                            'fi.branch_id',
                            '=',
                            'pa.branch_id'
                        );
                }
            )
            ->join(
                'enrollment_fees as ef',
                function ($join): void {
                    $join
                        ->on(
                            'ef.id',
                            '=',
                            'fi.enrollment_fee_id'
                        )
                        ->on(
                            'ef.center_id',
                            '=',
                            'fi.center_id'
                        )
                        ->on(
                            'ef.branch_id',
                            '=',
                            'fi.branch_id'
                        );
                }
            )
            ->where(
                'pa.center_id',
                $centerId
            )
            ->where(
                'p.status',
                PaymentStatus::Posted->value
            )
            ->where(
                'ef.status',
                EnrollmentFeeStatus::Active->value
            );

        if ($branchId !== null) {
            $allocationQuery->where(
                'pa.branch_id',
                $branchId
            );
        }

        $allocationRows =
            $allocationQuery
            ->select(
                'ef.currency_code'
            )
            ->selectRaw(
                'COALESCE(SUM(pa.amount), 0) as aggregate_amount'
            )
            ->groupBy(
                'ef.currency_code'
            )
            ->get()
            ->keyBy(
                'currency_code'
            );

        $postedPaymentQuery =
            DB::table(
                'payments'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                PaymentStatus::Posted->value
            );

        $reversedPaymentQuery =
            DB::table(
                'payments'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                PaymentStatus::Reversed->value
            );

        if ($branchId !== null) {
            $postedPaymentQuery->where(
                'branch_id',
                $branchId
            );

            $reversedPaymentQuery->where(
                'branch_id',
                $branchId
            );
        }

        $postedPaymentRows =
            $postedPaymentQuery
            ->select(
                'currency_code'
            )
            ->selectRaw(
                'COUNT(*) as aggregate_count'
            )
            ->selectRaw(
                'COALESCE(SUM(amount), 0) as aggregate_amount'
            )
            ->groupBy(
                'currency_code'
            )
            ->get()
            ->keyBy(
                'currency_code'
            );

        $reversedPaymentRows =
            $reversedPaymentQuery
            ->select(
                'currency_code'
            )
            ->selectRaw(
                'COUNT(*) as aggregate_count'
            )
            ->selectRaw(
                'COALESCE(SUM(amount), 0) as aggregate_amount'
            )
            ->groupBy(
                'currency_code'
            )
            ->get()
            ->keyBy(
                'currency_code'
            );

        $currencyCodes =
            collect()
            ->merge(
                $feeRows->keys()
            )
            ->merge(
                $installmentRows->keys()
            )
            ->merge(
                $allocationRows->keys()
            )
            ->merge(
                $postedPaymentRows->keys()
            )
            ->merge(
                $reversedPaymentRows->keys()
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $currencies = [];

        foreach ($currencyCodes as $currencyCode) {
            $fee =
                $feeRows->get(
                    $currencyCode
                );

            $installments =
                $installmentRows->get(
                    $currencyCode
                );

            $allocations =
                $allocationRows->get(
                    $currencyCode
                );

            $postedPayments =
                $postedPaymentRows->get(
                    $currencyCode
                );

            $reversedPayments =
                $reversedPaymentRows->get(
                    $currencyCode
                );

            $installmentAmount =
                (float) (
                    $installments
                    ?->aggregate_amount
                    ?? 0
                );

            $allocatedAmount =
                (float) (
                    $allocations
                    ?->aggregate_amount
                    ?? 0
                );

            $outstandingAmount =
                max(
                    0,
                    $installmentAmount
                        - $allocatedAmount
                );

            $currencies[(string) $currencyCode] = [
                'active_fee_count' =>
                (int) (
                    $fee
                    ?->aggregate_count
                    ?? 0
                ),

                'active_fee_amount' =>
                $this->money(
                    $fee
                        ?->aggregate_amount
                        ?? 0
                ),

                'installment_count' =>
                (int) (
                    $installments
                    ?->aggregate_count
                    ?? 0
                ),

                'installment_amount' =>
                $this->money(
                    $installmentAmount
                ),

                'allocated_amount' =>
                $this->money(
                    $allocatedAmount
                ),

                'outstanding_amount' =>
                $this->money(
                    $outstandingAmount
                ),

                'posted_payment_count' =>
                (int) (
                    $postedPayments
                    ?->aggregate_count
                    ?? 0
                ),

                'posted_payment_amount' =>
                $this->money(
                    $postedPayments
                        ?->aggregate_amount
                        ?? 0
                ),

                'reversed_payment_count' =>
                (int) (
                    $reversedPayments
                    ?->aggregate_count
                    ?? 0
                ),

                'reversed_payment_amount' =>
                $this->money(
                    $reversedPayments
                        ?->aggregate_amount
                        ?? 0
                ),
            ];
        }

        return [
            'center_id' =>
            $centerId,

            'branch_id' =>
            $branchId,

            'currencies' =>
            $currencies,
        ];
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center =
            $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return (int) $center->id;
    }

    private function persistedStudent(
        Student $student,
        int $centerId
    ): Student {
        if (
            ! $student->exists
            || $student->getKey() === null
        ) {
            throw new AuthorizationException(
                'Student must be a persisted record in the current Center.'
            );
        }

        $persisted =
            Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $student->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Student is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function money(
        int|float|string|null $amount
    ): string {
        return number_format(
            (float) ($amount ?? 0),
            2,
            '.',
            ''
        );
    }

    private function summaryBranchForActor(
        User $actor,
        int $centerId
    ): ?Branch {
        return match ($actor->systemRole()) {
            SystemRole::CenterOwner =>
            $this->centerWideReadScope(),

            SystemRole::BranchManager,
            SystemRole::FinanceEmployee =>
            $this->branchReadScope(
                $centerId
            ),

            SystemRole::Student =>
            null,

            default =>
            throw new AuthorizationException(
                'The account cannot view financial summaries.'
            ),
        };
    }

    private function centerWideReadScope(): null
    {
        if (
            ! $this->branchContext
                ->isEstablished()
            || ! $this->branchContext
                ->isCenterWide()
        ) {
            throw new AuthorizationException(
                'Center Owner financial reads require center-wide Branch context.'
            );
        }

        return null;
    }

    private function branchReadScope(
        int $centerId
    ): Branch {
        if (
            ! $this->branchContext
                ->isEstablished()
            || ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch financial reads require an assigned Branch context.'
            );
        }

        $branch =
            $this->branchContext
            ->branch();

        if (
            $branch === null
            || $branch->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The financial read is outside the current Center scope.'
            );
        }

        return $branch;
    }

    private function ensureReceiptOperationalScope(
        User $actor,
        Payment $payment
    ): void {
        switch ($actor->systemRole()) {
            case SystemRole::CenterOwner:
                $this->centerWideReadScope();

                return;

            case SystemRole::BranchManager:
            case SystemRole::FinanceEmployee:
                $branch =
                    $this->branchReadScope(
                        $payment->center_id
                    );

                if (
                    $branch->id
                    !== $payment->branch_id
                ) {
                    throw new AuthorizationException(
                        'The Receipt is outside the current Branch scope.'
                    );
                }

                return;

            case SystemRole::Student:
                /*
                 * Student ownership is already validated by
                 * PaymentPolicy::view().
                 *
                 * Student requests intentionally do not require
                 * BranchContext so historical Receipts remain
                 * visible after a Branch move.
                 */
                return;

            default:
                throw new AuthorizationException(
                    'The account cannot view financial Receipts.'
                );
        }
    }

    private function moneyToCents(
        mixed $value,
        string $label
    ): int {
        if (
            is_int($value)
            || is_float($value)
        ) {
            $value =
                number_format(
                    $value,
                    2,
                    '.',
                    ''
                );
        }

        if (! is_string($value)) {
            throw new LogicException(
                "{$label} is not a valid stored monetary amount."
            );
        }

        $value =
            trim(
                $value
            );

        if (
            preg_match(
                '/\A\d+(?:\.\d{1,2})?\z/',
                $value
            ) !== 1
        ) {
            throw new LogicException(
                "{$label} is not a valid stored monetary amount."
            );
        }

        [
            $whole,
            $decimal,
        ] = array_pad(
            explode(
                '.',
                $value,
                2
            ),
            2,
            ''
        );

        $decimal =
            str_pad(
                $decimal,
                2,
                '0'
            );

        return ((int) $whole * 100)
            + (int) $decimal;
    }

    private function centsToMoney(
        int $cents
    ): string {
        return number_format(
            $cents / 100,
            2,
            '.',
            ''
        );
    }
}