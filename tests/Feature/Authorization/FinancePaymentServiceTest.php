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

class FinancePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_owner_posts_partial_payment_with_backend_controlled_financial_fields(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
            $student,
        ] = $this->obligation(
            branch: $branch,
            feeAmount: '100.00',
            installmentAmounts: [
                '100.00',
            ]
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'owner-partial-payment-001',

                    'amount' =>
                    '40.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 10:30:00',

                    'reference' =>
                    'REF-001',

                    'notes' =>
                    'First partial payment',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '40.00',
                        ],
                    ],
                ]
            );

        $payment->refresh();

        $this->assertSame(
            $center->id,
            $payment->center_id
        );

        $this->assertSame(
            $branch->id,
            $payment->branch_id
        );

        $this->assertSame(
            $student->id,
            $payment->student_id
        );

        $this->assertSame(
            '40.00',
            $payment->amount
        );

        $this->assertSame(
            $fee->currency_code,
            $payment->currency_code
        );

        $this->assertSame(
            'cash',
            $payment->payment_method
        );

        $this->assertSame(
            '2026-09-04 10:30:00',
            $payment->paid_at
                ->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            $owner->id,
            $payment->received_by_user_id
        );

        $this->assertSame(
            'REF-001',
            $payment->reference
        );

        $this->assertSame(
            'First partial payment',
            $payment->notes
        );

        $this->assertSame(
            PaymentStatus::Posted,
            $payment->status
        );

        $this->assertMatchesRegularExpression(
            '/\ARCT-[A-F0-9]{32}\z/',
            $payment->receipt_number
        );

        $this->assertDatabaseHas(
            'payment_allocations',
            [
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'payment_id' =>
                $payment->id,

                'fee_installment_id' =>
                $installments[0]->id,

                'amount' =>
                '40.00',
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
                'finance.payment_posted',

                'subject_type' =>
                'payments',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_payment_can_allocate_across_multiple_installments_for_same_student_and_branch(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
            $student,
        ] = $this->obligation(
            branch: $branch,
            feeAmount: '300.00',
            installmentAmounts: [
                '100.00',
                '200.00',
            ]
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $payment = $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'multi-allocation-001',

                    'amount' =>
                    '150.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 11:00:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '50.00',
                        ],
                        [
                            'fee_installment_id' =>
                            $installments[1]->id,

                            'amount' =>
                            '100.00',
                        ],
                    ],
                ]
            );

        $this->assertSame(
            '150.00',
            $payment->amount
        );

        $this->assertSame(
            2,
            DB::table('payment_allocations')
                ->where(
                    'payment_id',
                    $payment->id
                )
                ->count()
        );
    }

    public function test_payment_allocations_must_equal_payment_amount_and_operation_rolls_back(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        try {
            $this->service()
                ->postPayment(
                    $owner,
                    $student,
                    $branch,
                    [
                        'idempotency_key' =>
                        'allocation-total-mismatch',

                        'amount' =>
                        '50.00',

                        'payment_method' =>
                        'cash',

                        'paid_at' =>
                        '2026-09-04 11:15:00',

                        'allocations' => [
                            [
                                'fee_installment_id' =>
                                $installments[0]->id,

                                'amount' =>
                                '40.00',
                            ],
                        ],
                    ]
                );

            $this->fail(
                'Expected Payment allocation total validation to fail.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Payment allocations must equal the total Payment amount.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'payments',
            0
        );

        $this->assertDatabaseCount(
            'payment_allocations',
            0
        );
    }

    public function test_payment_allocation_cannot_exceed_installment_outstanding_amount(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'outstanding-payment-001',

                    'amount' =>
                    '60.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 11:30:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '60.00',
                        ],
                    ],
                ]
            );

        try {
            $this->service()
                ->postPayment(
                    $owner,
                    $student,
                    $branch,
                    [
                        'idempotency_key' =>
                        'outstanding-payment-002',

                        'amount' =>
                        '50.00',

                        'payment_method' =>
                        'cash',

                        'paid_at' =>
                        '2026-09-04 11:31:00',

                        'allocations' => [
                            [
                                'fee_installment_id' =>
                                $installments[0]->id,

                                'amount' =>
                                '50.00',
                            ],
                        ],
                    ]
                );

            $this->fail(
                'Expected overpayment protection to reject the Payment.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Payment allocation exceeds the Installment outstanding amount.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'payments',
            1
        );

        $this->assertDatabaseCount(
            'payment_allocations',
            1
        );
    }

    public function test_payment_cannot_allocate_fee_belonging_to_another_student(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollmentA,
            $feeA,
            $installmentsA,
            $studentA,
        ] = $this->obligation(
            $branch
        );

        [
            $enrollmentB,
            $feeB,
            $installmentsB,
            $studentB,
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

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Payment allocations must belong to the selected Student.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $studentA,
                $branch,
                [
                    'idempotency_key' =>
                    'wrong-student-allocation',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 12:00:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installmentsB[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );
    }

    public function test_payment_cannot_allocate_installment_from_another_branch(): void
    {
        $center = $this->center();
        $branchA = $this->branch($center);
        $branchB = $this->branch($center);

        [
            $enrollmentA,
            $feeA,
            $installmentsA,
            $studentA,
        ] = $this->obligation(
            $branchA
        );

        [
            $enrollmentB,
            $feeB,
            $installmentsB,
            $studentB,
        ] = $this->obligation(
            $branchB
        );

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The Fee Installment is outside the authorized Branch scope.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $studentA,
                $branchA,
                [
                    'idempotency_key' =>
                    'cross-branch-allocation',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 12:15:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installmentsB[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );
    }

    public function test_payment_cannot_allocate_to_voided_fee(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
            $student,
        ] = $this->obligation(
            branch: $branch,
            voided: true
        );

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
            'Payments cannot be allocated to a Voided Fee.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'voided-fee-payment',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 12:30:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );
    }

    public function test_one_payment_cannot_mix_fee_currencies(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        [
            $enrollmentUsd,
            $feeUsd,
            $installmentsUsd,
        ] = $this->obligation(
            branch: $branch,
            student: $student,
            feeAmount: '100.00',
            currencyCode: 'USD'
        );

        [
            $enrollmentEur,
            $feeEur,
            $installmentsEur,
        ] = $this->obligation(
            branch: $branch,
            student: $student,
            feeAmount: '100.00',
            currencyCode: 'EUR'
        );

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
            'All Fees allocated to one Payment must use the same currency.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'mixed-currency-payment',

                    'amount' =>
                    '100.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 12:45:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installmentsUsd[0]->id,

                            'amount' =>
                            '50.00',
                        ],
                        [
                            'fee_installment_id' =>
                            $installmentsEur[0]->id,

                            'amount' =>
                            '50.00',
                        ],
                    ],
                ]
            );
    }

    public function test_same_installment_cannot_appear_twice_in_one_payment_request(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Each Fee Installment may appear only once in a Payment request.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'duplicate-installment-request',

                    'amount' =>
                    '50.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 13:00:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );
    }

    public function test_payment_rejects_backend_controlled_fields(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Payment posting received unsupported fields: receipt_number.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'backend-field-payment',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 13:15:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],

                    'receipt_number' =>
                    'USER-CONTROLLED',
                ]
            );
    }

    public function test_exact_idempotent_retry_returns_same_payment_without_duplicate_allocation_or_audit(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $attributes = [
            'idempotency_key' =>
            'idempotent-payment-001',

            'amount' =>
            '40.00',

            'payment_method' =>
            'cash',

            'paid_at' =>
            '2026-09-04 13:30:00',

            'reference' =>
            'IDEMPOTENT-REF',

            'notes' =>
            'Retry-safe payment',

            'allocations' => [
                [
                    'fee_installment_id' =>
                    $installments[0]->id,

                    'amount' =>
                    '40.00',
                ],
            ],
        ];

        $first = $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                $attributes
            );

        $second = $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                $attributes
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            $first->receipt_number,
            $second->receipt_number
        );

        $this->assertSame(
            1,
            DB::table('payments')
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'idempotency_key',
                    'idempotent-payment-001'
                )
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('payment_allocations')
                ->where(
                    'payment_id',
                    $first->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('audit_records')
                ->where(
                    'action_type',
                    'finance.payment_posted'
                )
                ->where(
                    'subject_type',
                    'payments'
                )
                ->where(
                    'subject_id',
                    $first->id
                )
                ->count()
        );
    }

    public function test_reusing_idempotency_key_with_different_payload_is_rejected(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'conflicting-idempotency-key',

                    'amount' =>
                    '40.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 13:45:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '40.00',
                        ],
                    ],
                ]
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Payment idempotency key has already been used for a different request.'
        );

        $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'conflicting-idempotency-key',

                    'amount' =>
                    '30.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 13:45:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '30.00',
                        ],
                    ],
                ]
            );
    }

    public function test_branch_manager_payment_requires_matching_operational_branch_context(): void
    {
        $center = $this->center();
        $branchA = $this->branch($center);
        $branchB = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
            $student,
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
            ->postPayment(
                $manager,
                $student,
                $branchA,
                [
                    'idempotency_key' =>
                    'manager-wrong-context',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 14:00:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );
    }

    public function test_finance_employee_can_post_payment_in_assigned_branch(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
            $student,
        ] = $this->obligation(
            $branch
        );

        $finance = $this->createUserForRole(
            SystemRole::FinanceEmployee,
            $center
        );

        $this->assignFinanceEmployee(
            $finance,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $payment = $this->service()
            ->postPayment(
                $finance,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'finance-employee-payment',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 14:15:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );

        $this->assertSame(
            $finance->id,
            $payment->received_by_user_id
        );

        $this->assertSame(
            $branch->id,
            $payment->branch_id
        );
    }

    public function test_payment_uses_persisted_student_and_branch_instead_of_tampered_model_state(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $student->center_id = 999999;
        $student->branch_id = 999999;
        $branch->center_id = 999999;

        $payment = $this->service()
            ->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'tampered-model-payment',

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 14:30:00',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments[0]->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],
                ]
            );

        $this->assertSame(
            $center->id,
            $payment->center_id
        );

        $this->assertSame(
            $student->getKey(),
            $payment->student_id
        );

        $this->assertSame(
            $branch->getKey(),
            $payment->branch_id
        );
    }

    public function test_audit_failure_rolls_back_payment_and_allocations(): void
    {
        $center = $this->center();
        $branch = $this->branch($center);

        [
            $enrollment,
            $fee,
            $installments,
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

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated Payment audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->postPayment(
                    $owner,
                    $student,
                    $branch,
                    [
                        'idempotency_key' =>
                        'payment-audit-rollback',

                        'amount' =>
                        '25.00',

                        'payment_method' =>
                        'cash',

                        'paid_at' =>
                        '2026-09-04 14:45:00',

                        'allocations' => [
                            [
                                'fee_installment_id' =>
                                $installments[0]->id,

                                'amount' =>
                                '25.00',
                            ],
                        ],
                    ]
                );

            $this->fail(
                'Expected Payment audit failure.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Payment audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'payments',
            0
        );

        $this->assertDatabaseCount(
            'payment_allocations',
            0
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
     *     2: array<int, FeeInstallment>,
     *     3: Student
     * }
     */
    private function obligation(
        Branch $branch,
        ?Student $student = null,
        string $feeAmount = '100.00',
        string $currencyCode = 'USD',
        array $installmentAmounts = [
            '100.00',
        ],
        bool $voided = false
    ): array {
        $student ??=
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

        $feeFactory =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            );

        $feeFactory = $voided
            ? $feeFactory->voided()
            : $feeFactory->active();

        $fee = $feeFactory
            ->create([
                'amount' =>
                $feeAmount,

                'currency_code' =>
                $currencyCode,
            ]);

        $installments = [];

        foreach (
            array_values(
                $installmentAmounts
            ) as $index => $amount
        ) {
            $installments[] =
                FeeInstallment::factory()
                ->forEnrollmentFee(
                    $fee
                )
                ->withSequenceNumber(
                    $index + 1
                )
                ->create([
                    'amount' =>
                    $amount,

                    'due_date' =>
                    sprintf(
                        '2026-10-%02d',
                        $index + 1
                    ),
                ]);
        }

        return [
            $enrollment,
            $fee,
            $installments,
            $student,
        ];
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