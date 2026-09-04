<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FinanceLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_fee_can_be_voided_and_history_is_preserved(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $result = $this->service()
            ->voidFee(
                $owner,
                $fee,
                'Enrollment charge cancelled.'
            );

        $result->refresh();

        $this->assertSame(
            $fee->id,
            $result->id
        );

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $result->status
        );

        $this->assertSame(
            $owner->id,
            $result->voided_by_user_id
        );

        $this->assertNotNull(
            $result->voided_at
        );

        $this->assertSame(
            'Enrollment charge cancelled.',
            $result->void_reason
        );

        /*
         * Financial history is retained.
         */
        $this->assertDatabaseHas(
            'enrollment_fees',
            [
                'id' =>
                $fee->id,
            ]
        );

        $this->assertDatabaseHas(
            'fee_installments',
            [
                'id' =>
                $installment->id,

                'enrollment_fee_id' =>
                $fee->id,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'actor_user_id' =>
                $owner->id,

                'action_type' =>
                'finance.fee_voided',

                'subject_type' =>
                'enrollment_fees',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_fee_void_requires_reason(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Fee void reason is required.'
        );

        $this->service()
            ->voidFee(
                $owner,
                $fee
            );
    }

    public function test_fee_with_posted_payment_cannot_be_voided(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '25.00',
            idempotencyKey: 'posted-before-void'
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Fee with Posted Payments cannot be voided. Reverse its Payments first.'
        );

        $this->service()
            ->voidFee(
                $owner,
                $fee,
                'Cancel fee'
            );
    }

    public function test_posted_payment_can_be_reversed_without_deleting_financial_history(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '40.00',
            idempotencyKey: 'payment-to-reverse'
        );

        $allocationId =
            DB::table(
                'payment_allocations'
            )
            ->where(
                'payment_id',
                $payment->id
            )
            ->value('id');

        $result = $this->service()
            ->reversePayment(
                $owner,
                $payment,
                'Payment entered by mistake.'
            );

        $result->refresh();

        $this->assertSame(
            PaymentStatus::Reversed,
            $result->status
        );

        $this->assertSame(
            $owner->id,
            $result->reversed_by_user_id
        );

        $this->assertNotNull(
            $result->reversed_at
        );

        $this->assertSame(
            'Payment entered by mistake.',
            $result->reversal_reason
        );

        $this->assertDatabaseHas(
            'payments',
            [
                'id' =>
                $payment->id,

                'status' =>
                PaymentStatus::Reversed
                    ->value,
            ]
        );

        $this->assertDatabaseHas(
            'payment_allocations',
            [
                'id' =>
                $allocationId,

                'payment_id' =>
                $payment->id,

                'fee_installment_id' =>
                $installment->id,

                'amount' =>
                '40.00',
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_reversed',

                'subject_type' =>
                'payments',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_payment_reversal_requires_reason(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '25.00',
            idempotencyKey: 'reversal-reason-required'
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Payment reversal reason is required.'
        );

        $this->service()
            ->reversePayment(
                $owner,
                $payment
            );
    }

    public function test_repeated_payment_reversal_is_idempotent_without_duplicate_audit(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '25.00',
            idempotencyKey: 'idempotent-reversal'
        );

        $first = $this->service()
            ->reversePayment(
                $owner,
                $payment,
                'Duplicate payment'
            );

        /*
         * Repeating the already successful operation
         * does not require a second reason.
         */
        $second = $this->service()
            ->reversePayment(
                $owner,
                $payment
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            PaymentStatus::Reversed,
            $second->status
        );

        $this->assertSame(
            1,
            DB::table('audit_records')
                ->where(
                    'action_type',
                    'finance.payment_reversed'
                )
                ->where(
                    'subject_type',
                    'payments'
                )
                ->where(
                    'subject_id',
                    $payment->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            DB::table(
                'payment_allocations'
            )
                ->where(
                    'payment_id',
                    $payment->id
                )
                ->count()
        );
    }

    public function test_repeated_fee_void_is_idempotent_without_duplicate_audit(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $first = $this->service()
            ->voidFee(
                $owner,
                $fee,
                'Cancel charge'
            );

        $second = $this->service()
            ->voidFee(
                $owner,
                $fee
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $second->status
        );

        $this->assertSame(
            1,
            DB::table('audit_records')
                ->where(
                    'action_type',
                    'finance.fee_voided'
                )
                ->where(
                    'subject_type',
                    'enrollment_fees'
                )
                ->where(
                    'subject_id',
                    $fee->id
                )
                ->count()
        );
    }

    public function test_reversed_payment_restores_installment_outstanding_amount(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation(
            branch: $branch,
            amount: '100.00'
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $firstPayment =
            $this->postPayment(
                actor: $owner,
                student: $student,
                branch: $branch,
                installment: $installment,
                amount: '60.00',
                idempotencyKey: 'balance-before-reversal'
            );

        /*
         * Before reversal only 40.00 remains.
         */
        try {
            $this->postPayment(
                actor: $owner,
                student: $student,
                branch: $branch,
                installment: $installment,
                amount: '100.00',
                idempotencyKey: 'should-fail-before-reversal'
            );

            $this->fail(
                'Expected outstanding balance protection before reversal.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Payment allocation exceeds the Installment outstanding amount.',
                $exception->getMessage()
            );
        }

        $this->service()
            ->reversePayment(
                $owner,
                $firstPayment,
                'Reverse first payment'
            );

        /*
         * Reversed allocations no longer consume
         * outstanding balance.
         */
        $replacementPayment =
            $this->postPayment(
                actor: $owner,
                student: $student,
                branch: $branch,
                installment: $installment,
                amount: '100.00',
                idempotencyKey: 'full-payment-after-reversal'
            );

        $this->assertSame(
            PaymentStatus::Posted,
            $replacementPayment->status
        );

        $this->assertSame(
            '100.00',
            $replacementPayment->amount
        );

        $this->assertSame(
            2,
            DB::table('payments')
                ->where(
                    'student_id',
                    $student->id
                )
                ->count()
        );

        $this->assertSame(
            2,
            DB::table(
                'payment_allocations'
            )
                ->where(
                    'fee_installment_id',
                    $installment->id
                )
                ->count()
        );
    }

    public function test_fee_can_be_voided_after_all_related_posted_payments_are_reversed(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation($branch);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment =
            $this->postPayment(
                actor: $owner,
                student: $student,
                branch: $branch,
                installment: $installment,
                amount: '30.00',
                idempotencyKey: 'reverse-before-void'
            );

        $this->service()
            ->reversePayment(
                $owner,
                $payment,
                'Undo payment'
            );

        $result = $this->service()
            ->voidFee(
                $owner,
                $fee,
                'Fee no longer required'
            );

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $result->status
        );

        $this->assertDatabaseHas(
            'payments',
            [
                'id' =>
                $payment->id,

                'status' =>
                PaymentStatus::Reversed
                    ->value,
            ]
        );

        $this->assertDatabaseHas(
            'payment_allocations',
            [
                'payment_id' =>
                $payment->id,

                'fee_installment_id' =>
                $installment->id,
            ]
        );
    }

    public function test_cross_center_fee_void_is_rejected(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();

        $branchB = $this->branch(
            $centerB
        );

        [
            $enrollmentB,
            $feeB,
        ] = $this->obligation(
            $branchB
        );

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->voidFee(
                $ownerA,
                $feeB,
                'Invalid cross-center void'
            );
    }

    public function test_cross_center_payment_reversal_is_rejected(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();

        $branchB = $this->branch(
            $centerB
        );

        [
            $enrollmentB,
            $feeB,
            $installmentB,
            $studentB,
        ] = $this->obligation(
            $branchB
        );

        $ownerB = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerB
        );

        $this->establishCenterOwnerContext(
            $centerB
        );

        $paymentB = $this->postPayment(
            actor: $ownerB,
            student: $studentB,
            branch: $branchB,
            installment: $installmentB,
            amount: '25.00',
            idempotencyKey: 'cross-center-reversal-source'
        );

        /*
         * Replace the tenant context with Center A.
         */
        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->reversePayment(
                $ownerA,
                $paymentB,
                'Invalid cross-center reversal'
            );
    }

    public function test_branch_manager_lifecycle_operation_requires_current_assigned_branch_context(): void
    {
        $center = $this->center();

        $branchA = $this->branch(
            $center
        );

        $branchB = $this->branch(
            $center
        );

        [
            $enrollment,
            $fee,
        ] = $this->obligation(
            $branchA
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        /*
         * Persisted assignment is Branch A,
         * but request context is Branch B.
         */
        $this->establishBranchContext(
            $center,
            $branchB
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The financial operation is outside the current Branch scope.'
        );

        $this->service()
            ->voidFee(
                $manager,
                $fee,
                'Wrong request Branch'
            );
    }

    public function test_finance_employee_payment_reversal_requires_current_assigned_branch_context(): void
    {
        $center = $this->center();

        $branchA = $this->branch(
            $center
        );

        $branchB = $this->branch(
            $center
        );

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation(
            $branchA
        );

        $finance = $this->createUserForRole(
            SystemRole::FinanceEmployee,
            $center
        );

        $this->assignFinanceEmployee(
            $finance,
            $branchA
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $payment = $this->postPayment(
            actor: $finance,
            student: $student,
            branch: $branchA,
            installment: $installment,
            amount: '20.00',
            idempotencyKey: 'finance-lifecycle-payment'
        );

        /*
         * Switch only the operational context.
         */
        app(BranchContext::class)
            ->establishBranchScope(
                $branchB
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The financial operation is outside the current Branch scope.'
        );

        $this->service()
            ->reversePayment(
                $finance,
                $payment,
                'Wrong request Branch'
            );
    }

    public function test_fee_void_uses_persisted_record_instead_of_tampered_model_state(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
        ] = $this->obligation(
            $branch
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $fee->center_id = 999999;
        $fee->branch_id = 999999;
        $fee->status =
            EnrollmentFeeStatus::Voided;

        $result = $this->service()
            ->voidFee(
                $owner,
                $fee,
                'Persisted Fee wins'
            );

        $this->assertSame(
            $center->id,
            $result->center_id
        );

        $this->assertSame(
            $branch->id,
            $result->branch_id
        );

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $result->status
        );

        $this->assertSame(
            'Persisted Fee wins',
            $result->void_reason
        );
    }

    public function test_payment_reversal_uses_persisted_record_instead_of_tampered_model_state(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation(
            $branch
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '25.00',
            idempotencyKey: 'tampered-reversal-payment'
        );

        $payment->center_id = 999999;
        $payment->branch_id = 999999;
        $payment->status =
            PaymentStatus::Reversed;

        $result = $this->service()
            ->reversePayment(
                $owner,
                $payment,
                'Persisted Payment wins'
            );

        $this->assertSame(
            $center->id,
            $result->center_id
        );

        $this->assertSame(
            $branch->id,
            $result->branch_id
        );

        $this->assertSame(
            PaymentStatus::Reversed,
            $result->status
        );

        $this->assertSame(
            'Persisted Payment wins',
            $result->reversal_reason
        );
    }

    public function test_fee_void_audit_failure_rolls_back_lifecycle_change(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
        ] = $this->obligation(
            $branch
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated Fee void audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->voidFee(
                    $owner,
                    $fee,
                    'Rollback this void'
                );

            $this->fail(
                'Expected Fee void audit failure.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Fee void audit failure.',
                $exception->getMessage()
            );
        }

        $persisted =
            EnrollmentFee::withoutGlobalScopes()
            ->findOrFail(
                $fee->id
            );

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $persisted->status
        );

        $this->assertNull(
            $persisted->voided_at
        );

        $this->assertNull(
            $persisted->voided_by_user_id
        );

        $this->assertNull(
            $persisted->void_reason
        );
    }

    public function test_payment_reversal_audit_failure_rolls_back_lifecycle_change(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installment,
            $student,
        ] = $this->obligation(
            $branch
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->postPayment(
            actor: $owner,
            student: $student,
            branch: $branch,
            installment: $installment,
            amount: '25.00',
            idempotencyKey: 'reversal-audit-rollback'
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated Payment reversal audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->reversePayment(
                    $owner,
                    $payment,
                    'Rollback this reversal'
                );

            $this->fail(
                'Expected Payment reversal audit failure.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Payment reversal audit failure.',
                $exception->getMessage()
            );
        }

        $persisted =
            \App\Models\Payment::withoutGlobalScopes()
            ->findOrFail(
                $payment->id
            );

        $this->assertSame(
            PaymentStatus::Posted,
            $persisted->status
        );

        $this->assertNull(
            $persisted->reversed_at
        );

        $this->assertNull(
            $persisted->reversed_by_user_id
        );

        $this->assertNull(
            $persisted->reversal_reason
        );

        /*
         * Original allocation remains untouched.
         */
        $this->assertSame(
            1,
            DB::table(
                'payment_allocations'
            )
                ->where(
                    'payment_id',
                    $payment->id
                )
                ->count()
        );
    }

    private function service(): FinanceManagementService
    {
        return app(
            FinanceManagementService::class
        );
    }

    private function center(): Center
    {
        return Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->active()
            ->for($center)
            ->create();
    }

    /**
     * @return array{
     *     0: Enrollment,
     *     1: EnrollmentFee,
     *     2: FeeInstallment,
     *     3: Student
     * }
     */
    private function obligation(
        Branch $branch,
        string $amount = '100.00'
    ): array {
        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $fee =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            )
            ->active()
            ->create([
                'amount' =>
                $amount,

                'currency_code' =>
                'USD',
            ]);

        $installment =
            FeeInstallment::factory()
            ->forEnrollmentFee(
                $fee
            )
            ->withSequenceNumber(1)
            ->create([
                'amount' =>
                $amount,

                'due_date' =>
                '2026-10-01',
            ]);

        return [
            $enrollment,
            $fee,
            $installment,
            $student,
        ];
    }

    private function postPayment(
        User $actor,
        Student $student,
        Branch $branch,
        FeeInstallment $installment,
        string $amount,
        string $idempotencyKey
    ): \App\Models\Payment {
        return $this->service()
            ->postPayment(
                $actor,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    $idempotencyKey,

                    'amount' =>
                    $amount,

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 15:00:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            $amount,
                        ],
                    ],
                ]
            );
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchContext(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );
    }

    private function assignBranchManager(
        User $user,
        Branch $branch
    ): void {
        DB::table(
            'branch_manager_assignments'
        )->insert([
            'center_id' =>
            $branch->center_id,

            'user_id' =>
            $user->id,

            'branch_id' =>
            $branch->id,

            'started_at' =>
            now(),

            'ended_at' =>
            null,

            'active_marker' =>
            1,

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);
    }

    private function assignFinanceEmployee(
        User $user,
        Branch $branch
    ): void {
        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $user->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role ===
            SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this
                        ->role($role)
                        ->id,

                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        if ($center === null) {
            $center =
                $this->center();
        }

        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this
                    ->role($role)
                    ->id,

                'status' =>
                AccountStatus::Active,
            ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->firstOrCreate(
                [
                    'code' =>
                    $role->value,
                ],
                [
                    'name' =>
                    $role->label(),
                ]
            );
    }
}