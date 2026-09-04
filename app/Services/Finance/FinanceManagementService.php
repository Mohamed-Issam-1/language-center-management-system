<?php

namespace App\Services\Finance;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Support\Enums\PaymentStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class FinanceManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function createFee(
        User $actor,
        Enrollment $enrollment,
        array $attributes = []
    ): EnrollmentFee {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'amount',
                'installments',
            ],
            'Fee creation'
        );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $enrollment,
                $attributes,
                $centerId
            ): EnrollmentFee {
                /*
                 * Lock Enrollment first because it is the
                 * financial obligation owner.
                 */
                $enrollment =
                    $this->lockEnrollment(
                        $enrollment,
                        $centerId
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $enrollment->class_id,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            EnrollmentFee::class,
                            $enrollment,
                        ]
                    );

                $branchId =
                    (int) $courseClass->branch_id;

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $branchId
                );

                /*
                 * One Enrollment has exactly one Fee in
                 * the current MVP.
                 */
                $existing =
                    EnrollmentFee::query()
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'enrollment_id',
                        $enrollment->id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new DomainException(
                        'This Enrollment already has a Fee.'
                    );
                }

                $course =
                    $this->lockCourse(
                        $courseClass->course_id,
                        $centerId
                    );

                $center =
                    Center::query()
                    ->whereKey(
                        $centerId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $this->requiredCurrencyCode(
                        $center->operating_currency_code
                    );

                /*
                 * Course.default_fee is only the default source.
                 * The resulting EnrollmentFee is a historical
                 * financial snapshot.
                 */
                $amountCents =
                    array_key_exists(
                        'amount',
                        $attributes
                    )
                    ? $this->moneyToCents(
                        $attributes['amount'],
                        'Fee amount'
                    )
                    : $this->moneyToCents(
                        $course->default_fee,
                        'Course default fee'
                    );

                if ($amountCents <= 0) {
                    throw new DomainException(
                        'Fee amount must be greater than zero.'
                    );
                }

                $installments =
                    $this->normalizedInstallments(
                        $attributes['installments']
                            ?? null,
                        $amountCents,
                        $enrollment
                    );

                $fee =
                    EnrollmentFee::query()
                    ->withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'branch_id' =>
                        $branchId,

                        'enrollment_id' =>
                        $enrollment->id,

                        'amount' =>
                        $this->centsToMoney(
                            $amountCents
                        ),

                        'currency_code' =>
                        $currencyCode,

                        'status' =>
                        EnrollmentFeeStatus::Active,

                        'created_by_user_id' =>
                        $actor->id,

                        'voided_by_user_id' =>
                        null,

                        'voided_at' =>
                        null,

                        'void_reason' =>
                        null,
                    ]);

                foreach (
                    $installments as $index => $installment
                ) {
                    FeeInstallment::query()
                        ->withoutGlobalScopes()
                        ->create([
                            'center_id' =>
                            $centerId,

                            'branch_id' =>
                            $branchId,

                            'enrollment_fee_id' =>
                            $fee->id,

                            /*
                             * Sequence is backend-controlled.
                             */
                            'sequence_number' =>
                            $index + 1,

                            'due_date' =>
                            $installment['due_date'],

                            'amount' =>
                            $this->centsToMoney(
                                $installment['amount_cents']
                            ),
                        ]);
                }

                $fee->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'finance.fee_created',
                    subject: $fee,
                    afterValues: $this->feeAuditValues(
                        $fee
                    ),
                    metadata: [
                        'installment_count' =>
                        count($installments),
                    ]
                );

                return $fee;
            },
            3
        );
    }

    public function postPayment(
        User $actor,
        Student $student,
        Branch $branch,
        array $attributes
    ): Payment {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'idempotency_key',
                'amount',
                'payment_method',
                'paid_at',
                'allocations',
                'reference',
                'notes',
            ],
            'Payment posting'
        );

        $idempotencyKey =
            $this->requiredLimitedText(
                $attributes['idempotency_key']
                    ?? null,
                'Payment idempotency key',
                100
            );

        if (
            ! array_key_exists(
                'amount',
                $attributes
            )
        ) {
            throw new DomainException(
                'Payment amount is required.'
            );
        }

        $amountCents =
            $this->moneyToCents(
                $attributes['amount'],
                'Payment amount'
            );

        if ($amountCents <= 0) {
            throw new DomainException(
                'Payment amount must be greater than zero.'
            );
        }

        $paymentMethod =
            $this->requiredLimitedText(
                $attributes['payment_method']
                    ?? null,
                'Payment method',
                50
            );

        if (
            ! array_key_exists(
                'paid_at',
                $attributes
            )
        ) {
            throw new DomainException(
                'Payment date and time are required.'
            );
        }

        $paidAt =
            $this->requiredDateTime(
                $attributes['paid_at'],
                'Payment date and time'
            );

        $reference =
            $this->nullableText(
                $attributes['reference']
                    ?? null,
                'Payment reference',
                100
            );

        $notes =
            $this->nullableText(
                $attributes['notes']
                    ?? null,
                'Payment notes'
            );

        $allocations =
            $this->normalizedPaymentAllocations(
                $attributes['allocations']
                    ?? null
            );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        /*
     * The operation closure is intentionally reusable.
     *
     * If two identical requests race on the same
     * idempotency key, the database unique constraint
     * remains the final protection. After the winner
     * commits, the losing request retries once and
     * resolves the persisted Payment.
     */
        $operation =
            function () use (
                $actor,
                $student,
                $branch,
                $centerId,
                $idempotencyKey,
                $amountCents,
                $paymentMethod,
                $paidAt,
                $reference,
                $notes,
                $allocations
            ): Payment {
                return DB::transaction(
                    function () use (
                        $actor,
                        $student,
                        $branch,
                        $centerId,
                        $idempotencyKey,
                        $amountCents,
                        $paymentMethod,
                        $paidAt,
                        $reference,
                        $notes,
                        $allocations
                    ): Payment {
                        $branch =
                            $this->lockBranch(
                                $branch,
                                $centerId
                            );

                        $student =
                            $this->lockStudent(
                                $student,
                                $centerId
                            );

                        Gate::forUser($actor)
                            ->authorize(
                                'create',
                                [
                                    Payment::class,
                                    $student,
                                    $branch,
                                ]
                            );

                        $this->ensureOperationalBranchScope(
                            $actor,
                            $centerId,
                            $branch->id
                        );

                        /*
                     * Resolve a previous successful submission
                     * before running outstanding-balance checks.
                     *
                     * A retry must still return the same Payment
                     * even if balances changed after the original
                     * operation.
                     */
                        $existing =
                            Payment::query()
                            ->withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $centerId
                            )
                            ->where(
                                'idempotency_key',
                                $idempotencyKey
                            )
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            $this->ensureIdempotentPaymentMatches(
                                $existing,
                                $student,
                                $branch,
                                $amountCents,
                                $paymentMethod,
                                $paidAt,
                                $reference,
                                $notes,
                                $allocations
                            );

                            return $existing;
                        }

                        [
                            $currencyCode,
                            $resolvedAllocations,
                        ] = $this
                            ->resolvePaymentAllocations(
                                $student,
                                $branch,
                                $centerId,
                                $allocations
                            );

                        $allocationTotalCents =
                            array_sum(
                                array_column(
                                    $resolvedAllocations,
                                    'amount_cents'
                                )
                            );

                        if (
                            $allocationTotalCents
                            !== $amountCents
                        ) {
                            throw new DomainException(
                                'Payment allocations must equal the total Payment amount.'
                            );
                        }

                        /*
                     * receipt_number, currency_code, status,
                     * receiver, Student, Center, and Branch
                     * are exclusively backend-controlled.
                     */
                        $payment =
                            Payment::query()
                            ->withoutGlobalScopes()
                            ->create([
                                'center_id' =>
                                $centerId,

                                'branch_id' =>
                                $branch->id,

                                'student_id' =>
                                $student->id,

                                'receipt_number' =>
                                $this
                                    ->generateReceiptNumber(),

                                'idempotency_key' =>
                                $idempotencyKey,

                                'amount' =>
                                $this->centsToMoney(
                                    $amountCents
                                ),

                                'currency_code' =>
                                $currencyCode,

                                'payment_method' =>
                                $paymentMethod,

                                'paid_at' =>
                                $paidAt,

                                'received_by_user_id' =>
                                $actor->id,

                                'reference' =>
                                $reference,

                                'notes' =>
                                $notes,

                                'status' =>
                                PaymentStatus::Posted,

                                'reversed_at' =>
                                null,

                                'reversed_by_user_id' =>
                                null,

                                'reversal_reason' =>
                                null,
                            ]);

                        foreach (
                            $resolvedAllocations
                            as $allocation
                        ) {
                            PaymentAllocation::query()
                                ->withoutGlobalScopes()
                                ->create([
                                    'center_id' =>
                                    $centerId,

                                    'branch_id' =>
                                    $branch->id,

                                    'payment_id' =>
                                    $payment->id,

                                    'fee_installment_id' =>
                                    $allocation['fee_installment_id'],

                                    'amount' =>
                                    $this->centsToMoney(
                                        $allocation['amount_cents']
                                    ),
                                ]);
                        }

                        $payment->refresh();

                        $this->audit->record(
                            actor: $actor,
                            actionType: 'finance.payment_posted',
                            subject: $payment,
                            afterValues: $this
                                ->paymentAuditValues(
                                    $payment
                                ),
                            metadata: [
                                'allocation_count' =>
                                count(
                                    $resolvedAllocations
                                ),
                            ]
                        );

                        return $payment;
                    },
                    3
                );
            };

        try {
            return $operation();
        } catch (
            UniqueConstraintViolationException
            $exception
        ) {
            /*
         * Only an idempotency-key collision is eligible
         * for the one-time retry.
         *
         * A receipt-number or unrelated unique violation
         * must propagate normally.
         */
            $idempotentWinnerExists =
                Payment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $centerId
                )
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->exists();

            if (! $idempotentWinnerExists) {
                throw $exception;
            }

            return $operation();
        }
    }

    public function voidFee(
        User $actor,
        EnrollmentFee $fee,
        ?string $reason = null
    ): EnrollmentFee {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $fee,
                $reason,
                $centerId
            ): EnrollmentFee {
                $fee =
                    $this->lockEnrollmentFee(
                        $fee,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'void',
                        $fee
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $fee->branch_id
                );

                /*
             * Repeating an already successful void
             * is idempotent and must not create
             * another Audit record.
             */
                if ($fee->isVoided()) {
                    return $fee;
                }

                /*
             * A Fee with active Posted money against it
             * cannot simply disappear financially.
             *
             * Payments must be reversed first.
             */
                if (
                    $this->feeHasPostedAllocations(
                        $fee
                    )
                ) {
                    throw new DomainException(
                        'A Fee with Posted Payments cannot be voided. Reverse its Payments first.'
                    );
                }

                $reason =
                    $this->requiredLifecycleReason(
                        $reason,
                        'Fee void reason'
                    );

                $beforeValues =
                    $this->feeAuditValues(
                        $fee
                    );

                $fee->forceFill([
                    'status' =>
                    EnrollmentFeeStatus::Voided,

                    'voided_by_user_id' =>
                    $actor->id,

                    'voided_at' =>
                    now(),

                    'void_reason' =>
                    $reason,
                ])->save();

                $fee->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'finance.fee_voided',
                    subject: $fee,
                    beforeValues: $beforeValues,
                    afterValues: $this->feeAuditValues(
                        $fee
                    )
                );

                return $fee;
            },
            3
        );
    }

    public function reversePayment(
        User $actor,
        Payment $payment,
        ?string $reason = null
    ): Payment {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $payment,
                $reason,
                $centerId
            ): Payment {
                $payment =
                    $this->lockPayment(
                        $payment,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'reverse',
                        $payment
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $payment->branch_id
                );

                /*
             * Repeating the same successful reversal
             * is idempotent.
             *
             * Allocations remain preserved as immutable
             * financial history.
             */
                if ($payment->isReversed()) {
                    return $payment;
                }

                $reason =
                    $this->requiredLifecycleReason(
                        $reason,
                        'Payment reversal reason'
                    );

                $beforeValues =
                    $this->paymentAuditValues(
                        $payment
                    );

                $payment->forceFill([
                    'status' =>
                    PaymentStatus::Reversed,

                    'reversed_at' =>
                    now(),

                    'reversed_by_user_id' =>
                    $actor->id,

                    'reversal_reason' =>
                    $reason,
                ])->save();

                $payment->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'finance.payment_reversed',
                    subject: $payment,
                    beforeValues: $beforeValues,
                    afterValues: $this->paymentAuditValues(
                        $payment
                    )
                );

                return $payment;
            },
            3
        );
    }

    private function lockBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        if (
            ! $branch->exists
            || $branch->getKey() === null
        ) {
            throw new AuthorizationException(
                'Branch must be a persisted record in the current Center.'
            );
        }

        $persisted =
            Branch::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $branch->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Branch is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockStudent(
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
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Student is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    /**
     * @param array<int, array{
     *     fee_installment_id: int,
     *     amount_cents: int
     * }> $allocations
     *
     * @return array{
     *     0: string,
     *     1: array<int, array{
     *         fee_installment_id: int,
     *         amount_cents: int
     *     }>
     * }
     */
    private function resolvePaymentAllocations(
        Student $student,
        Branch $branch,
        int $centerId,
        array $allocations
    ): array {
        $currencyCode = null;
        $resolved = [];

        /*
     * normalizedPaymentAllocations() sorts by
     * Installment ID, giving every Payment operation
     * a deterministic Installment lock order.
     */
        foreach (
            $allocations as $allocation
        ) {
            $installment =
                FeeInstallment::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    $allocation['fee_installment_id']
                )
                ->where(
                    'center_id',
                    $centerId
                )
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->lockForUpdate()
                ->first();

            if ($installment === null) {
                throw new AuthorizationException(
                    'The Fee Installment is outside the authorized Branch scope.'
                );
            }

            $fee =
                EnrollmentFee::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    $installment
                        ->enrollment_fee_id
                )
                ->where(
                    'center_id',
                    $centerId
                )
                ->where(
                    'branch_id',
                    $branch->id
                )
                ->lockForUpdate()
                ->first();

            if ($fee === null) {
                throw new AuthorizationException(
                    'The Enrollment Fee is outside the authorized Branch scope.'
                );
            }

            if (! $fee->isActive()) {
                throw new DomainException(
                    'Payments cannot be allocated to a Voided Fee.'
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
                throw new AuthorizationException(
                    'The Fee Enrollment is outside the authorized Center scope.'
                );
            }

            /*
         * This is the critical relationship that the
         * database cannot encode:
         *
         * Payment Student
         * === Fee -> Enrollment -> Student.
         */
            if (
                (int) $enrollment->student_id
                !== (int) $student->id
            ) {
                throw new DomainException(
                    'Payment allocations must belong to the selected Student.'
                );
            }

            $feeCurrency =
                $this->requiredCurrencyCode(
                    $fee->currency_code
                );

            if ($currencyCode === null) {
                $currencyCode =
                    $feeCurrency;
            } elseif (
                $currencyCode
                !== $feeCurrency
            ) {
                throw new DomainException(
                    'All Fees allocated to one Payment must use the same currency.'
                );
            }

            $installmentAmountCents =
                $this->moneyToCents(
                    $installment->amount,
                    'Installment amount'
                );

            $alreadyAllocatedCents =
                $this
                ->postedAllocationTotalCents(
                    $centerId,
                    $branch->id,
                    $installment->id
                );

            $outstandingCents =
                $installmentAmountCents
                - $alreadyAllocatedCents;

            if ($outstandingCents < 0) {
                throw new DomainException(
                    'Installment financial history exceeds its configured amount.'
                );
            }

            if (
                $allocation['amount_cents']
                > $outstandingCents
            ) {
                throw new DomainException(
                    'Payment allocation exceeds the Installment outstanding amount.'
                );
            }

            $resolved[] = [
                'fee_installment_id' =>
                (int) $installment->id,

                'amount_cents' =>
                $allocation['amount_cents'],
            ];
        }

        if ($currencyCode === null) {
            throw new DomainException(
                'At least one Payment allocation is required.'
            );
        }

        return [
            $currencyCode,
            $resolved,
        ];
    }

    private function postedAllocationTotalCents(
        int $centerId,
        int $branchId,
        int $installmentId
    ): int {
        $allocated =
            PaymentAllocation::query()
            ->withoutGlobalScopes()
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
            ->where(
                'payment_allocations.branch_id',
                $branchId
            )
            ->where(
                'payment_allocations.fee_installment_id',
                $installmentId
            )
            ->where(
                'payments.center_id',
                $centerId
            )
            ->where(
                'payments.branch_id',
                $branchId
            )
            ->where(
                'payments.status',
                PaymentStatus::Posted->value
            )
            ->sum(
                'payment_allocations.amount'
            );

        return $this->moneyToCents(
            $allocated,
            'Allocated Payment amount'
        );
    }

    /**
     * @return array<int, array{
     *     fee_installment_id: int,
     *     amount_cents: int
     * }>
     */
    private function normalizedPaymentAllocations(
        mixed $value
    ): array {
        if (
            ! is_array($value)
            || $value === []
        ) {
            throw new DomainException(
                'At least one Payment allocation is required.'
            );
        }

        $normalized = [];
        $seenInstallmentIds = [];

        foreach (
            array_values($value)
            as $allocation
        ) {
            if (! is_array($allocation)) {
                throw new DomainException(
                    'Each Payment allocation must be an array.'
                );
            }

            $this->assertOnlyAllowedKeys(
                $allocation,
                [
                    'fee_installment_id',
                    'amount',
                ],
                'Payment allocation'
            );

            $installmentId =
                $this->requiredPositiveInteger(
                    $allocation['fee_installment_id'] ?? null,
                    'Fee Installment'
                );

            if (
                isset(
                    $seenInstallmentIds[$installmentId]
                )
            ) {
                throw new DomainException(
                    'Each Fee Installment may appear only once in a Payment request.'
                );
            }

            $seenInstallmentIds[$installmentId] = true;

            if (
                ! array_key_exists(
                    'amount',
                    $allocation
                )
            ) {
                throw new DomainException(
                    'Payment allocation amount is required.'
                );
            }

            $amountCents =
                $this->moneyToCents(
                    $allocation['amount'],
                    'Payment allocation amount'
                );

            if ($amountCents <= 0) {
                throw new DomainException(
                    'Payment allocation amount must be greater than zero.'
                );
            }

            $normalized[] = [
                'fee_installment_id' =>
                $installmentId,

                'amount_cents' =>
                $amountCents,
            ];
        }

        usort(
            $normalized,
            static fn(
                array $left,
                array $right
            ): int =>
            $left['fee_installment_id']
                <=>
                $right['fee_installment_id']
        );

        return $normalized;
    }

    /**
     * @param array<int, array{
     *     fee_installment_id: int,
     *     amount_cents: int
     * }> $requestedAllocations
     */
    private function ensureIdempotentPaymentMatches(
        Payment $payment,
        Student $student,
        Branch $branch,
        int $amountCents,
        string $paymentMethod,
        string $paidAt,
        ?string $reference,
        ?string $notes,
        array $requestedAllocations
    ): void {
        $existingAllocations =
            PaymentAllocation::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $payment->center_id
            )
            ->where(
                'payment_id',
                $payment->id
            )
            ->orderBy(
                'fee_installment_id'
            )
            ->get()
            ->map(
                fn(
                    PaymentAllocation $allocation
                ): array => [
                    'fee_installment_id' =>
                    (int) $allocation
                        ->fee_installment_id,

                    'amount_cents' =>
                    $this->moneyToCents(
                        $allocation->amount,
                        'Existing Payment allocation'
                    ),
                ]
            )
            ->all();

        $matches =
            (int) $payment->student_id
            === (int) $student->id
            && (int) $payment->branch_id
            === (int) $branch->id
            && $this->moneyToCents(
                $payment->amount,
                'Existing Payment amount'
            ) === $amountCents
            && $payment->payment_method
            === $paymentMethod
            && $payment->paid_at
            ?->format(
                'Y-m-d H:i:s'
            ) === $paidAt
            && $payment->reference
            === $reference
            && $payment->notes
            === $notes
            && $existingAllocations
            === $requestedAllocations;

        if (! $matches) {
            throw new DomainException(
                'The Payment idempotency key has already been used for a different request.'
            );
        }
    }

    private function requiredPositiveInteger(
        mixed $value,
        string $label
    ): int {
        if (
            is_bool($value)
            || ! (
                is_int($value)
                || (
                    is_string($value)
                    && ctype_digit(
                        trim($value)
                    )
                )
            )
        ) {
            throw new DomainException(
                "{$label} must be a positive integer."
            );
        }

        $value =
            (int) $value;

        if ($value <= 0) {
            throw new DomainException(
                "{$label} must be a positive integer."
            );
        }

        return $value;
    }

    private function requiredLimitedText(
        mixed $value,
        string $label,
        int $maxLength
    ): string {
        if (! is_string($value)) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value =
            trim($value);

        if ($value === '') {
            throw new DomainException(
                "{$label} is required."
            );
        }

        if (
            mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function nullableText(
        mixed $value,
        string $label,
        ?int $maxLength = null
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must be a string or null."
            );
        }

        $value =
            trim($value);

        if ($value === '') {
            return null;
        }

        if (
            $maxLength !== null
            && mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function requiredDateTime(
        mixed $value,
        string $label
    ): string {
        if (
            $value instanceof DateTimeInterface
        ) {
            return $value->format(
                'Y-m-d H:i:s'
            );
        }

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD HH:MM:SS format."
            );
        }

        $value =
            trim($value);

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value
            );

        if (
            $date === false
            || $date->format(
                'Y-m-d H:i:s'
            ) !== $value
        ) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD HH:MM:SS format."
            );
        }

        return $value;
    }

    private function generateReceiptNumber(): string
    {
        return 'RCT-'
            . Str::upper(
                str_replace(
                    '-',
                    '',
                    (string) Str::uuid()
                )
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentAuditValues(
        Payment $payment
    ): array {
        return [
            'id' =>
            (int) $payment->id,

            'center_id' =>
            (int) $payment->center_id,

            'branch_id' =>
            (int) $payment->branch_id,

            'student_id' =>
            (int) $payment->student_id,

            'receipt_number' =>
            $payment->receipt_number,

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

            'status' =>
            $payment->status,
        ];
    }

    private function lockEnrollmentFee(
        EnrollmentFee $fee,
        int $centerId
    ): EnrollmentFee {
        if (
            ! $fee->exists
            || $fee->getKey() === null
        ) {
            throw new AuthorizationException(
                'Enrollment Fee must be a persisted record in the current Center.'
            );
        }

        $persisted =
            EnrollmentFee::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $fee->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Enrollment Fee is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockPayment(
        Payment $payment,
        int $centerId
    ): Payment {
        if (
            ! $payment->exists
            || $payment->getKey() === null
        ) {
            throw new AuthorizationException(
                'Payment must be a persisted record in the current Center.'
            );
        }

        $persisted =
            Payment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $payment->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Payment is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function feeHasPostedAllocations(
        EnrollmentFee $fee
    ): bool {
        return PaymentAllocation::query()
            ->withoutGlobalScopes()
            ->join(
                'fee_installments',
                'fee_installments.id',
                '=',
                'payment_allocations.fee_installment_id'
            )
            ->join(
                'payments',
                'payments.id',
                '=',
                'payment_allocations.payment_id'
            )
            ->where(
                'fee_installments.center_id',
                $fee->center_id
            )
            ->where(
                'fee_installments.branch_id',
                $fee->branch_id
            )
            ->where(
                'fee_installments.enrollment_fee_id',
                $fee->id
            )
            ->where(
                'payments.center_id',
                $fee->center_id
            )
            ->where(
                'payments.branch_id',
                $fee->branch_id
            )
            ->where(
                'payments.status',
                PaymentStatus::Posted->value
            )
            ->exists();
    }

    private function requiredLifecycleReason(
        ?string $reason,
        string $label
    ): string {
        if ($reason === null) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $reason =
            trim($reason);

        if ($reason === '') {
            throw new DomainException(
                "{$label} is required."
            );
        }

        if (
            mb_strlen($reason)
            > 255
        ) {
            throw new DomainException(
                "{$label} must not exceed 255 characters."
            );
        }

        return $reason;
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

    private function lockEnrollment(
        Enrollment $enrollment,
        int $centerId
    ): Enrollment {
        if (
            ! $enrollment->exists
            || $enrollment->getKey() === null
        ) {
            throw new AuthorizationException(
                'Enrollment must be a persisted record in the current Center.'
            );
        }

        $persisted =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Enrollment is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockCourseClass(
        int $classId,
        int $centerId
    ): CourseClass {
        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $classId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($courseClass === null) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        return $courseClass;
    }

    private function lockCourse(
        int $courseId,
        int $centerId
    ): Course {
        $course =
            Course::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($course === null) {
            throw new AuthorizationException(
                'The Course is outside the authorized Center scope.'
            );
        }

        return $course;
    }

    private function ensureOperationalBranchScope(
        User $actor,
        int $centerId,
        int $branchId
    ): void {
        if (
            ! $this->branchContext
                ->isEstablished()
        ) {
            throw new AuthorizationException(
                'Branch operational context has not been established.'
            );
        }

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $this->branchContext
                    ->isCenterWide()
            ) {
                throw new AuthorizationException(
                    'Center Owner financial operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            ! in_array(
                $actor->systemRole(),
                [
                    SystemRole::BranchManager,
                    SystemRole::FinanceEmployee,
                ],
                true
            )
        ) {
            throw new AuthorizationException(
                'The account cannot manage financial operations.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch-scoped financial operations require an assigned Branch context.'
            );
        }

        $contextBranch =
            $this->branchContext
            ->branch();

        if (
            $contextBranch === null
            || $contextBranch->center_id
            !== $centerId
            || $contextBranch->id
            !== $branchId
        ) {
            throw new AuthorizationException(
                'The financial operation is outside the current Branch scope.'
            );
        }
    }

    /**
     * @return array<int, array{
     *     due_date: string,
     *     amount_cents: int
     * }>
     */
    private function normalizedInstallments(
        mixed $value,
        int $feeAmountCents,
        Enrollment $enrollment
    ): array {
        /*
         * No plan supplied:
         * create one full-value installment.
         *
         * The Enrollment date is used as the immediate
         * financial due date instead of inventing a future date.
         */
        if ($value === null || $value === []) {
            return [
                [
                    'due_date' =>
                    $enrollment
                        ->enrollment_date
                        ->toDateString(),

                    'amount_cents' =>
                    $feeAmountCents,
                ],
            ];
        }

        if (! is_array($value)) {
            throw new DomainException(
                'Installments must be an array.'
            );
        }

        $normalized = [];
        $totalCents = 0;

        foreach (
            array_values($value) as $index => $installment
        ) {
            if (! is_array($installment)) {
                throw new DomainException(
                    'Each installment must be an array.'
                );
            }

            $this->assertOnlyAllowedKeys(
                $installment,
                [
                    'amount',
                    'due_date',
                ],
                'Installment'
            );

            if (
                ! array_key_exists(
                    'amount',
                    $installment
                )
            ) {
                throw new DomainException(
                    'Installment amount is required.'
                );
            }

            if (
                ! array_key_exists(
                    'due_date',
                    $installment
                )
            ) {
                throw new DomainException(
                    'Installment due date is required.'
                );
            }

            $amountCents =
                $this->moneyToCents(
                    $installment['amount'],
                    'Installment amount'
                );

            if ($amountCents <= 0) {
                throw new DomainException(
                    'Installment amount must be greater than zero.'
                );
            }

            $dueDate =
                $this->requiredDate(
                    $installment['due_date'],
                    'Installment due date'
                );

            $normalized[] = [
                'due_date' =>
                $dueDate,

                'amount_cents' =>
                $amountCents,
            ];

            $totalCents +=
                $amountCents;
        }

        if ($normalized === []) {
            throw new DomainException(
                'At least one installment is required.'
            );
        }

        if (
            $totalCents !==
            $feeAmountCents
        ) {
            throw new DomainException(
                'Installment amounts must equal the total Fee amount.'
            );
        }

        return $normalized;
    }

    private function requiredCurrencyCode(
        mixed $value
    ): string {
        if (! is_string($value)) {
            throw new DomainException(
                'The Center operating currency must be configured before creating Fees.'
            );
        }

        $value =
            strtoupper(
                trim($value)
            );

        if (
            $value === ''
            || mb_strlen($value) > 10
        ) {
            throw new DomainException(
                'The Center operating currency must be configured before creating Fees.'
            );
        }

        return $value;
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
            throw new DomainException(
                "{$label} must be a valid monetary amount."
            );
        }

        $value =
            trim($value);

        if (
            preg_match(
                '/\A\d+(?:\.\d{1,2})?\z/',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                "{$label} must use at most two decimal places."
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

    private function requiredDate(
        mixed $value,
        string $label
    ): string {
        if (
            $value instanceof DateTimeInterface
        ) {
            return $value->format(
                'Y-m-d'
            );
        }

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD format."
            );
        }

        $value =
            trim($value);

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

        if (
            $date === false
            || $date->format('Y-m-d')
            !== $value
        ) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD format."
            );
        }

        return $value;
    }

    private function assertOnlyAllowedKeys(
        array $attributes,
        array $allowedKeys,
        string $operation
    ): void {
        $unexpected =
            array_diff(
                array_keys($attributes),
                $allowedKeys
            );

        if ($unexpected === []) {
            return;
        }

        sort($unexpected);

        throw new DomainException(
            "{$operation} received unsupported fields: "
                . implode(', ', $unexpected)
                . '.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function feeAuditValues(
        EnrollmentFee $fee
    ): array {
        return [
            'id' =>
            (int) $fee->id,

            'center_id' =>
            (int) $fee->center_id,

            'branch_id' =>
            (int) $fee->branch_id,

            'enrollment_id' =>
            (int) $fee->enrollment_id,

            'amount' =>
            $fee->amount,

            'currency_code' =>
            $fee->currency_code,

            'status' =>
            $fee->status,

            'created_by_user_id' =>
            (int) $fee->created_by_user_id,

            'voided_by_user_id' =>
            $fee->voided_by_user_id,

            'voided_at' =>
            $fee->voided_at
                ?->toDateTimeString(),

            'void_reason' =>
            $fee->void_reason,
        ];
    }
}