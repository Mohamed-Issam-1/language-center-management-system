<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\PaymentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class FinanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_factories_create_valid_records(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->create();

        $installment =
            FeeInstallment::factory()
            ->create();

        $payment =
            Payment::factory()
            ->create();

        $allocation =
            PaymentAllocation::factory()
            ->create();

        $this->assertDatabaseHas(
            'enrollment_fees',
            [
                'id' => $fee->id,
            ]
        );

        $this->assertDatabaseHas(
            'fee_installments',
            [
                'id' => $installment->id,
            ]
        );

        $this->assertDatabaseHas(
            'payments',
            [
                'id' => $payment->id,
            ]
        );

        $this->assertDatabaseHas(
            'payment_allocations',
            [
                'id' => $allocation->id,
            ]
        );
    }

    public function test_finance_models_cast_values_and_relationships(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->active()
            ->create([
                'amount' => '300.00',
                'currency_code' => 'USD',
            ]);

        $installment =
            FeeInstallment::factory()
            ->forEnrollmentFee($fee)
            ->withSequenceNumber(1)
            ->create([
                'amount' => '150.00',
                'due_date' => '2026-10-01',
            ]);

        $enrollment =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $fee->enrollment_id
            );

        $student =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $enrollment->student_id
            );

        $branch =
            Branch::withoutGlobalScopes()
            ->findOrFail(
                $fee->branch_id
            );

        $center =
            Center::query()
            ->findOrFail(
                $fee->center_id
            );

        $payment =
            Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->posted()
            ->create([
                'amount' => '150.00',
                'currency_code' => 'USD',
                'paid_at' => '2026-09-03 12:30:00',
            ]);

        $allocation =
            PaymentAllocation::factory()
            ->forPayment($payment)
            ->forFeeInstallment($installment)
            ->create([
                'amount' => '150.00',
            ]);

        $fee->refresh();
        $installment->refresh();
        $payment->refresh();
        $allocation->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $fee->status
        );

        $this->assertSame(
            '300.00',
            $fee->amount
        );

        $this->assertTrue(
            $fee->isActive()
        );

        $this->assertFalse(
            $fee->isVoided()
        );

        $this->assertSame(
            1,
            $installment->sequence_number
        );

        $this->assertSame(
            '150.00',
            $installment->amount
        );

        $this->assertInstanceOf(
            Carbon::class,
            $installment->due_date
        );

        $this->assertSame(
            PaymentStatus::Posted,
            $payment->status
        );

        $this->assertSame(
            '150.00',
            $payment->amount
        );

        $this->assertInstanceOf(
            Carbon::class,
            $payment->paid_at
        );

        $this->assertTrue(
            $payment->isPosted()
        );

        $this->assertFalse(
            $payment->isReversed()
        );

        $this->assertSame(
            '150.00',
            $allocation->amount
        );

        $this->assertTrue(
            $fee->center->is($center)
        );

        $this->assertTrue(
            $fee->branch->is($branch)
        );

        $this->assertTrue(
            $fee->enrollment->is($enrollment)
        );

        $this->assertTrue(
            $fee->installments
                ->contains($installment)
        );

        $this->assertTrue(
            $installment->enrollmentFee
                ->is($fee)
        );

        $this->assertTrue(
            $installment->allocations
                ->contains($allocation)
        );

        $this->assertTrue(
            $payment->student
                ->is($student)
        );

        $this->assertTrue(
            $payment->allocations
                ->contains($allocation)
        );

        $this->assertTrue(
            $allocation->payment
                ->is($payment)
        );

        $this->assertTrue(
            $allocation->feeInstallment
                ->is($installment)
        );

        $this->assertTrue(
            $enrollment
                ->enrollmentFee
                ->is($fee)
        );

        $this->assertTrue(
            $student
                ->payments
                ->contains($payment)
        );

        $this->assertTrue(
            $center
                ->enrollmentFees
                ->contains($fee)
        );

        $this->assertTrue(
            $center
                ->feeInstallments
                ->contains($installment)
        );

        $this->assertTrue(
            $center
                ->payments
                ->contains($payment)
        );

        $this->assertTrue(
            $center
                ->paymentAllocations
                ->contains($allocation)
        );
    }

    public function test_voided_fee_and_reversed_payment_cast_lifecycle_state(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->voided()
            ->create();

        $payment =
            Payment::factory()
            ->reversed()
            ->create();

        $fee->refresh();
        $payment->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $fee->status
        );

        $this->assertTrue(
            $fee->isVoided()
        );

        $this->assertFalse(
            $fee->isActive()
        );

        $this->assertNotNull(
            $fee->voided_by_user_id
        );

        $this->assertInstanceOf(
            Carbon::class,
            $fee->voided_at
        );

        $this->assertSame(
            PaymentStatus::Reversed,
            $payment->status
        );

        $this->assertTrue(
            $payment->isReversed()
        );

        $this->assertFalse(
            $payment->isPosted()
        );

        $this->assertNotNull(
            $payment->reversed_by_user_id
        );

        $this->assertInstanceOf(
            Carbon::class,
            $payment->reversed_at
        );
    }

    public function test_database_allows_only_one_fee_per_enrollment(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->create();

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'enrollment_fees'
        )->insert([
            'center_id' =>
            $fee->center_id,

            'branch_id' =>
            $fee->branch_id,

            'enrollment_id' =>
            $fee->enrollment_id,

            'amount' =>
            '200.00',

            'currency_code' =>
            'USD',

            'status' =>
            EnrollmentFeeStatus::Active->value,

            'created_by_user_id' =>
            $fee->created_by_user_id,
        ]);
    }

    public function test_database_rejects_fee_for_enrollment_from_another_center(): void
    {
        $feeA =
            EnrollmentFee::factory()
            ->create();

        $feeB =
            EnrollmentFee::factory()
            ->create();

        $this->assertNotSame(
            $feeA->center_id,
            $feeB->center_id
        );

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'enrollment_fees'
        )->insert([
            'center_id' =>
            $feeA->center_id,

            'branch_id' =>
            $feeA->branch_id,

            'enrollment_id' =>
            $feeB->enrollment_id,

            'amount' =>
            '100.00',

            'currency_code' =>
            'USD',

            'status' =>
            EnrollmentFeeStatus::Active->value,

            'created_by_user_id' =>
            $feeA->created_by_user_id,
        ]);
    }

    public function test_installment_sequence_must_be_unique_per_fee(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->create();

        FeeInstallment::factory()
            ->forEnrollmentFee($fee)
            ->withSequenceNumber(1)
            ->create();

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'fee_installments'
        )->insert([
            'center_id' =>
            $fee->center_id,

            'branch_id' =>
            $fee->branch_id,

            'enrollment_fee_id' =>
            $fee->id,

            'sequence_number' =>
            1,

            'due_date' =>
            now()
                ->addMonth()
                ->toDateString(),

            'amount' =>
            '100.00',
        ]);
    }

    public function test_database_rejects_installment_using_another_branch_of_same_center(): void
    {
        $fee =
            EnrollmentFee::factory()
            ->create();

        $center =
            Center::query()
            ->findOrFail(
                $fee->center_id
            );

        $otherBranch =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $this->assertNotSame(
            $fee->branch_id,
            $otherBranch->id
        );

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'fee_installments'
        )->insert([
            'center_id' =>
            $fee->center_id,

            'branch_id' =>
            $otherBranch->id,

            'enrollment_fee_id' =>
            $fee->id,

            'sequence_number' =>
            1,

            'due_date' =>
            now()
                ->addMonth()
                ->toDateString(),

            'amount' =>
            '100.00',
        ]);
    }

    public function test_database_rejects_payment_for_student_from_another_center(): void
    {
        $paymentA =
            Payment::factory()
            ->create();

        $paymentB =
            Payment::factory()
            ->create();

        $this->assertNotSame(
            $paymentA->center_id,
            $paymentB->center_id
        );

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'payments'
        )->insert(
            $this->paymentInsertData(
                centerId: $paymentA->center_id,

                branchId: $paymentA->branch_id,

                studentId: $paymentB->student_id,

                receiverId: $paymentA->received_by_user_id
            )
        );
    }

    public function test_database_rejects_payment_receiver_from_another_center(): void
    {
        $paymentA =
            Payment::factory()
            ->create();

        $paymentB =
            Payment::factory()
            ->create();

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'payments'
        )->insert(
            $this->paymentInsertData(
                centerId: $paymentA->center_id,

                branchId: $paymentA->branch_id,

                studentId: $paymentA->student_id,

                receiverId: $paymentB->received_by_user_id
            )
        );
    }

    public function test_receipt_number_must_be_unique_inside_same_center(): void
    {
        $payment =
            Payment::factory()
            ->create([
                'receipt_number' =>
                'RCT-SAME-CENTER',
            ]);

        $branch =
            Branch::withoutGlobalScopes()
            ->findOrFail(
                $payment->branch_id
            );

        $student =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $payment->student_id
            );

        $this->expectException(
            QueryException::class
        );

        Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->create([
                'receipt_number' =>
                'RCT-SAME-CENTER',
            ]);
    }

    public function test_idempotency_key_must_be_unique_inside_same_center(): void
    {
        $payment =
            Payment::factory()
            ->create([
                'idempotency_key' =>
                'payment-operation-123',
            ]);

        $branch =
            Branch::withoutGlobalScopes()
            ->findOrFail(
                $payment->branch_id
            );

        $student =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $payment->student_id
            );

        $this->expectException(
            QueryException::class
        );

        Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->create([
                'idempotency_key' =>
                'payment-operation-123',
            ]);
    }

    public function test_same_receipt_and_idempotency_key_are_allowed_in_different_centers(): void
    {
        $paymentA =
            Payment::factory()
            ->create([
                'receipt_number' =>
                'RCT-SHARED',

                'idempotency_key' =>
                'shared-operation-key',
            ]);

        $paymentB =
            Payment::factory()
            ->create([
                'receipt_number' =>
                'RCT-SHARED',

                'idempotency_key' =>
                'shared-operation-key',
            ]);

        $this->assertNotSame(
            $paymentA->center_id,
            $paymentB->center_id
        );

        $this->assertNotSame(
            $paymentA->id,
            $paymentB->id
        );
    }

    public function test_database_rejects_allocation_across_branches(): void
    {
        $payment =
            Payment::factory()
            ->create();

        $center =
            Center::query()
            ->findOrFail(
                $payment->center_id
            );

        $otherBranch =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $fee =
            EnrollmentFee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $otherBranch->id,
            ]);

        $installment =
            FeeInstallment::factory()
            ->forEnrollmentFee($fee)
            ->withSequenceNumber(1)
            ->create();

        $this->assertNotSame(
            $payment->branch_id,
            $installment->branch_id
        );

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'payment_allocations'
        )->insert([
            'center_id' =>
            $payment->center_id,

            'branch_id' =>
            $payment->branch_id,

            'payment_id' =>
            $payment->id,

            'fee_installment_id' =>
            $installment->id,

            'amount' =>
            '10.00',
        ]);
    }

    public function test_payment_and_installment_pair_can_only_be_allocated_once(): void
    {
        [
            $payment,
            $installment,
            $allocation,
        ] = $this->financialChain();

        $this->assertDatabaseHas(
            'payment_allocations',
            [
                'id' =>
                $allocation->id,
            ]
        );

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'payment_allocations'
        )->insert([
            'center_id' =>
            $payment->center_id,

            'branch_id' =>
            $payment->branch_id,

            'payment_id' =>
            $payment->id,

            'fee_installment_id' =>
            $installment->id,

            'amount' =>
            '25.00',
        ]);
    }

    public function test_finance_models_respect_current_tenant_scope(): void
    {
        [
            $paymentA,
            $installmentA,
            $allocationA,
        ] = $this->financialChain();

        [
            $paymentB,
            $installmentB,
            $allocationB,
        ] = $this->financialChain();

        $feeA =
            EnrollmentFee::withoutGlobalScopes()
            ->findOrFail(
                $installmentA->enrollment_fee_id
            );

        $feeB =
            EnrollmentFee::withoutGlobalScopes()
            ->findOrFail(
                $installmentB->enrollment_fee_id
            );

        $this->assertNotSame(
            $feeA->center_id,
            $feeB->center_id
        );

        $centerA =
            Center::query()
            ->findOrFail(
                $feeA->center_id
            );

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $feeIds =
            EnrollmentFee::query()
            ->forCurrentTenant()
            ->pluck('id');

        $installmentIds =
            FeeInstallment::query()
            ->forCurrentTenant()
            ->pluck('id');

        $paymentIds =
            Payment::query()
            ->forCurrentTenant()
            ->pluck('id');

        $allocationIds =
            PaymentAllocation::query()
            ->forCurrentTenant()
            ->pluck('id');

        $this->assertTrue(
            $feeIds->contains($feeA->id)
        );

        $this->assertFalse(
            $feeIds->contains($feeB->id)
        );

        $this->assertTrue(
            $installmentIds->contains(
                $installmentA->id
            )
        );

        $this->assertFalse(
            $installmentIds->contains(
                $installmentB->id
            )
        );

        $this->assertTrue(
            $paymentIds->contains(
                $paymentA->id
            )
        );

        $this->assertFalse(
            $paymentIds->contains(
                $paymentB->id
            )
        );

        $this->assertTrue(
            $allocationIds->contains(
                $allocationA->id
            )
        );

        $this->assertFalse(
            $allocationIds->contains(
                $allocationB->id
            )
        );
    }

    public function test_finance_models_respect_branch_and_center_wide_scopes(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $branchB =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $feeA =
            EnrollmentFee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $feeB =
            EnrollmentFee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $installmentA =
            FeeInstallment::factory()
            ->forEnrollmentFee($feeA)
            ->withSequenceNumber(1)
            ->create();

        $installmentB =
            FeeInstallment::factory()
            ->forEnrollmentFee($feeB)
            ->withSequenceNumber(1)
            ->create();

        $enrollmentA =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $feeA->enrollment_id
            );

        $enrollmentB =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $feeB->enrollment_id
            );

        $studentA =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $enrollmentA->student_id
            );

        $studentB =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $enrollmentB->student_id
            );

        $paymentA =
            Payment::factory()
            ->forBranch($branchA)
            ->forStudent($studentA)
            ->create();

        $paymentB =
            Payment::factory()
            ->forBranch($branchB)
            ->forStudent($studentB)
            ->create();

        $allocationA =
            PaymentAllocation::factory()
            ->forPayment($paymentA)
            ->forFeeInstallment(
                $installmentA
            )
            ->create();

        $allocationB =
            PaymentAllocation::factory()
            ->forPayment($paymentB)
            ->forFeeInstallment(
                $installmentB
            )
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $this->assertEqualsCanonicalizing(
            [$feeA->id],
            EnrollmentFee::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [$installmentA->id],
            FeeInstallment::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [$paymentA->id],
            Payment::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [$allocationA->id],
            PaymentAllocation::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        /*
     * Center Owner style context:
     * both branches inside this Center are visible.
     */
        app(BranchContext::class)
            ->establishCenterWideScope();

        $this->assertEqualsCanonicalizing(
            [
                $feeA->id,
                $feeB->id,
            ],
            EnrollmentFee::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [
                $installmentA->id,
                $installmentB->id,
            ],
            FeeInstallment::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [
                $paymentA->id,
                $paymentB->id,
            ],
            Payment::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );

        $this->assertEqualsCanonicalizing(
            [
                $allocationA->id,
                $allocationB->id,
            ],
            PaymentAllocation::query()
                ->forCurrentBranch()
                ->pluck('id')
                ->all()
        );
    }

    public function test_finance_branch_scope_rejects_branch_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->active()
            ->for($centerB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        /*
     * Deliberately establish an inconsistent context.
     */
        app(BranchContext::class)
            ->establishBranchScope(
                $branchB
            );

        $this->expectException(
            AuthorizationException::class
        );

        Payment::query()
            ->forCurrentBranch()
            ->count();
    }

    /**
     * @return array{
     *     0: Payment,
     *     1: FeeInstallment,
     *     2: PaymentAllocation
     * }
     */
    private function financialChain(): array
    {
        $fee =
            EnrollmentFee::factory()
            ->create([
                'amount' => '200.00',
            ]);

        $installment =
            FeeInstallment::factory()
            ->forEnrollmentFee($fee)
            ->withSequenceNumber(1)
            ->create([
                'amount' => '100.00',
            ]);

        $enrollment =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $fee->enrollment_id
            );

        $student =
            Student::withoutGlobalScopes()
            ->findOrFail(
                $enrollment->student_id
            );

        $branch =
            Branch::withoutGlobalScopes()
            ->findOrFail(
                $fee->branch_id
            );

        $payment =
            Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->create([
                'amount' => '100.00',
            ]);

        $allocation =
            PaymentAllocation::factory()
            ->forPayment($payment)
            ->forFeeInstallment($installment)
            ->create([
                'amount' => '100.00',
            ]);

        return [
            $payment,
            $installment,
            $allocation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentInsertData(
        int $centerId,
        int $branchId,
        int $studentId,
        int $receiverId
    ): array {
        return [
            'center_id' =>
            $centerId,

            'branch_id' =>
            $branchId,

            'student_id' =>
            $studentId,

            'receipt_number' =>
            'RCT-'
                . Str::upper(
                    Str::random(16)
                ),

            'idempotency_key' =>
            (string) Str::uuid(),

            'amount' =>
            '100.00',

            'currency_code' =>
            'USD',

            'payment_method' =>
            'cash',

            'paid_at' =>
            now()
                ->startOfSecond(),

            'received_by_user_id' =>
            $receiverId,

            'status' =>
            PaymentStatus::Posted->value,
        ];
    }
}
