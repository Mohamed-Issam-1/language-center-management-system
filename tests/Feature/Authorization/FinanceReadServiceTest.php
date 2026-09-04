<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceReadService;
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
use Tests\TestCase;

class FinanceReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_owner_reads_unpaid_student_balance(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student,
            '100.00',
            'USD'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            $center->id,
            $result['center_id']
        );

        $this->assertSame(
            $student->id,
            $result['student_id']
        );

        $this->assertSame(
            [
                'USD' => [
                    'obligation' =>
                    '100.00',

                    'paid' =>
                    '0.00',

                    'balance' =>
                    '100.00',
                ],
            ],
            $result['totals']
        );

        $this->assertCount(
            1,
            $result['installments']
        );

        $this->assertSame(
            '100.00',
            $result['installments'][0]['balance']
        );
    }

    public function test_posted_partial_payment_reduces_student_balance(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student,
            '100.00'
        );

        $this->payment(
            $branch,
            $student,
            $installment,
            '40.00',
            PaymentStatus::Posted,
            'RCT-READ-PARTIAL'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            [
                'obligation' =>
                '100.00',

                'paid' =>
                '40.00',

                'balance' =>
                '60.00',
            ],
            $result['totals']['USD']
        );

        $this->assertSame(
            '40.00',
            $result['installments'][0]['paid']
        );

        $this->assertSame(
            '60.00',
            $result['installments'][0]['balance']
        );
    }

    public function test_reversed_payment_does_not_reduce_balance(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student,
            '100.00'
        );

        $this->payment(
            $branch,
            $student,
            $installment,
            '75.00',
            PaymentStatus::Reversed,
            'RCT-READ-REVERSED'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            '0.00',
            $result['totals']['USD']['paid']
        );

        $this->assertSame(
            '100.00',
            $result['totals']['USD']['balance']
        );
    }

    public function test_voided_fee_is_excluded_from_balance(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        $this->obligation(
            $branch,
            $student,
            '100.00',
            'USD',
            EnrollmentFeeStatus::Voided
        );

        $this->obligation(
            $branch,
            $student,
            '50.00',
            'USD',
            EnrollmentFeeStatus::Active
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            [
                'USD' => [
                    'obligation' =>
                    '50.00',

                    'paid' =>
                    '0.00',

                    'balance' =>
                    '50.00',
                ],
            ],
            $result['totals']
        );

        $this->assertCount(
            1,
            $result['installments']
        );
    }

    public function test_balance_totals_are_separated_by_currency(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $usdFee,
            $usdInstallment,
        ] = $this->obligation(
            $branch,
            $student,
            '300.00',
            'USD'
        );

        $this->obligation(
            $branch,
            $student,
            '200.00',
            'EUR'
        );

        $this->payment(
            $branch,
            $student,
            $usdInstallment,
            '100.00',
            PaymentStatus::Posted,
            'RCT-CURRENCY-USD'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            [
                'EUR' => [
                    'obligation' =>
                    '200.00',

                    'paid' =>
                    '0.00',

                    'balance' =>
                    '200.00',
                ],

                'USD' => [
                    'obligation' =>
                    '300.00',

                    'paid' =>
                    '100.00',

                    'balance' =>
                    '200.00',
                ],
            ],
            $result['totals']
        );
    }

    public function test_center_owner_balance_is_center_wide_across_historical_branches(): void
    {
        $center =
            $this->center();

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branchA
            );

        $this->obligation(
            $branchA,
            $student,
            '100.00'
        );

        $this->obligation(
            $branchB,
            $student,
            '150.00'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->assertSame(
            '250.00',
            $result['totals']['USD']['obligation']
        );

        $this->assertCount(
            2,
            $result['installments']
        );
    }

    public function test_branch_manager_balance_is_limited_to_assigned_financial_branch_even_after_student_moves(): void
    {
        $center =
            $this->center();

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branchA
            );

        $this->obligation(
            $branchA,
            $student,
            '100.00'
        );

        $this->obligation(
            $branchB,
            $student,
            '200.00'
        );

        /*
         * Student later moves operationally to Branch B.
         * Historical Branch A finance must remain readable
         * by Branch A staff.
         */
        $student->forceFill([
            'branch_id' =>
            $branchB->id,
        ])->save();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $result =
            $this->service()
            ->studentBalance(
                $manager,
                $student
            );

        $this->assertSame(
            '100.00',
            $result['totals']['USD']['obligation']
        );

        $this->assertCount(
            1,
            $result['installments']
        );

        $this->assertSame(
            $branchA->id,
            $result['installments'][0]['branch_id']
        );
    }

    public function test_finance_employee_balance_is_limited_to_assigned_branch(): void
    {
        $center =
            $this->center();

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branchA
            );

        $this->obligation(
            $branchA,
            $student,
            '125.00'
        );

        $this->obligation(
            $branchB,
            $student,
            '225.00'
        );

        $finance =
            $this->createUserForRole(
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

        $result =
            $this->service()
            ->studentBalance(
                $finance,
                $student
            );

        $this->assertSame(
            '125.00',
            $result['totals']['USD']['balance']
        );

        $this->assertCount(
            1,
            $result['installments']
        );
    }

    public function test_student_can_read_own_balance_across_historical_branches_without_branch_context(): void
    {
        $center =
            $this->center();

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $student =
            $this->student(
                $branchA,
                $studentUser
            );

        $this->obligation(
            $branchA,
            $student,
            '100.00'
        );

        $this->obligation(
            $branchB,
            $student,
            '200.00'
        );

        /*
         * Student has TenantContext but intentionally
         * no BranchContext.
         */
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $result =
            $this->service()
            ->studentBalance(
                $studentUser,
                $student
            );

        $this->assertSame(
            '300.00',
            $result['totals']['USD']['obligation']
        );

        $this->assertCount(
            2,
            $result['installments']
        );
    }

    public function test_student_cannot_read_another_students_balance(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $studentUserA =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $studentUserB =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $studentA =
            $this->student(
                $branch,
                $studentUserA
            );

        $studentB =
            $this->student(
                $branch,
                $studentUserB
            );

        $this->obligation(
            $branch,
            $studentB,
            '100.00'
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->studentBalance(
                $studentUserA,
                $studentB
            );
    }

    public function test_cross_center_and_tampered_student_balance_access_are_rejected(): void
    {
        $centerA =
            $this->center();

        $centerB =
            $this->center();

        $branchB =
            $this->branch(
                $centerB
            );

        $studentB =
            $this->student(
                $branchB
            );

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $studentB->center_id =
            $centerA->id;

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->studentBalance(
                $ownerA,
                $studentB
            );
    }

    public function test_posted_receipt_returns_payment_student_and_allocation_details(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student,
            '100.00'
        );

        $payment =
            $this->payment(
                $branch,
                $student,
                $installment,
                '40.00',
                PaymentStatus::Posted,
                'RCT-READ-DETAILS'
            );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->receipt(
                $owner,
                'RCT-READ-DETAILS'
            );

        $this->assertSame(
            $payment->id,
            $result['payment_id']
        );

        $this->assertSame(
            'RCT-READ-DETAILS',
            $result['receipt_number']
        );

        $this->assertSame(
            $student->id,
            $result['student']['id']
        );

        $this->assertSame(
            '40.00',
            $result['amount']
        );

        $this->assertSame(
            'USD',
            $result['currency_code']
        );

        $this->assertSame(
            PaymentStatus::Posted->value,
            $result['status']
        );

        $this->assertNull(
            $result['reversal']
        );

        $this->assertCount(
            1,
            $result['allocations']
        );

        $this->assertSame(
            $installment->id,
            $result['allocations'][0]['installment_id']
        );

        $this->assertSame(
            $fee->id,
            $result['allocations'][0]['fee_id']
        );
    }

    public function test_reversed_receipt_remains_visible_with_reversal_details(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student
        );

        $reversedBy =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            $this->payment(
                $branch,
                $student,
                $installment,
                '30.00',
                PaymentStatus::Reversed,
                'RCT-READ-REVERSED-DETAIL',
                $reversedBy
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $result =
            $this->service()
            ->receipt(
                $reversedBy,
                'RCT-READ-REVERSED-DETAIL'
            );

        $this->assertSame(
            PaymentStatus::Reversed->value,
            $result['status']
        );

        $this->assertNotNull(
            $result['reversal']
        );

        $this->assertSame(
            $reversedBy->id,
            $result['reversal']['reversed_by_user_id']
        );

        $this->assertSame(
            'Read test reversal',
            $result['reversal']['reason']
        );

        $this->assertCount(
            1,
            $result['allocations']
        );
    }

    public function test_student_can_read_only_own_receipt(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $userA =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $userB =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $studentA =
            $this->student(
                $branch,
                $userA
            );

        $studentB =
            $this->student(
                $branch,
                $userB
            );

        [
            $feeA,
            $installmentA,
        ] = $this->obligation(
            $branch,
            $studentA
        );

        [
            $feeB,
            $installmentB,
        ] = $this->obligation(
            $branch,
            $studentB
        );

        $this->payment(
            $branch,
            $studentA,
            $installmentA,
            '20.00',
            PaymentStatus::Posted,
            'RCT-STUDENT-OWN'
        );

        $this->payment(
            $branch,
            $studentB,
            $installmentB,
            '20.00',
            PaymentStatus::Posted,
            'RCT-STUDENT-OTHER'
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $own =
            $this->service()
            ->receipt(
                $userA,
                'RCT-STUDENT-OWN'
            );

        $this->assertSame(
            $studentA->id,
            $own['student']['id']
        );

        try {
            $this->service()
                ->receipt(
                    $userA,
                    'RCT-STUDENT-OTHER'
                );

            $this->fail(
                'Student accessed another Student receipt.'
            );
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_branch_staff_cannot_read_receipt_from_another_branch(): void
    {
        $center =
            $this->center();

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        $studentB =
            $this->student(
                $branchB
            );

        [
            $feeB,
            $installmentB,
        ] = $this->obligation(
            $branchB,
            $studentB
        );

        $this->payment(
            $branchB,
            $studentB,
            $installmentB,
            '25.00',
            PaymentStatus::Posted,
            'RCT-BRANCH-B'
        );

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->receipt(
                $manager,
                'RCT-BRANCH-B'
            );
    }

    public function test_financial_reads_do_not_create_audit_records(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        $student =
            $this->student(
                $branch
            );

        [
            $fee,
            $installment,
        ] = $this->obligation(
            $branch,
            $student
        );

        $this->payment(
            $branch,
            $student,
            $installment,
            '20.00',
            PaymentStatus::Posted,
            'RCT-NO-AUDIT'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $before =
            DB::table(
                'audit_records'
            )->count();

        $this->service()
            ->studentBalance(
                $owner,
                $student
            );

        $this->service()
            ->receipt(
                $owner,
                'RCT-NO-AUDIT'
            );

        $after =
            DB::table(
                'audit_records'
            )->count();

        $this->assertSame(
            $before,
            $after
        );
    }

    public function test_receipt_number_validation_is_enforced(): void
    {
        $center =
            $this->center();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        try {
            $this->service()
                ->receipt(
                    $owner,
                    '   '
                );

            $this->fail(
                'Expected empty Receipt validation.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Receipt number is required.',
                $exception->getMessage()
            );
        }

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Receipt number must not exceed 50 characters.'
        );

        $this->service()
            ->receipt(
                $owner,
                str_repeat(
                    'R',
                    51
                )
            );
    }

    private function service(): FinanceReadService
    {
        return app(
            FinanceReadService::class
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

    private function student(
        Branch $branch,
        ?User $user = null
    ): Student {
        $attributes = [];

        if ($user !== null) {
            $attributes = [
                'person_id' =>
                $user->person_id,

                'user_id' =>
                $user->id,
            ];
        }

        return Student::factory()
            ->forBranch($branch)
            ->active()
            ->create(
                $attributes
            );
    }

    /**
     * @return array{
     *     0: EnrollmentFee,
     *     1: FeeInstallment
     * }
     */
    private function obligation(
        Branch $branch,
        Student $student,
        string $amount = '100.00',
        string $currencyCode = 'USD',
        EnrollmentFeeStatus $status =
        EnrollmentFeeStatus::Active
    ): array {
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
            ->create([
                'amount' =>
                $amount,

                'currency_code' =>
                $currencyCode,

                'status' =>
                $status,
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
            $fee,
            $installment,
        ];
    }

    private function payment(
        Branch $branch,
        Student $student,
        FeeInstallment $installment,
        string $amount,
        PaymentStatus $status,
        string $receiptNumber,
        ?User $reversedBy = null
    ): Payment {
        $payment =
            Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->create([
                'receipt_number' =>
                $receiptNumber,

                'idempotency_key' =>
                'read-'
                    . strtolower(
                        $receiptNumber
                    ),

                'amount' =>
                $amount,

                'currency_code' =>
                'USD',

                'payment_method' =>
                'cash',

                'paid_at' =>
                '2026-09-04 16:00:00',

                'status' =>
                $status,

                'reversed_at' =>
                $status
                    === PaymentStatus::Reversed
                    ? now()
                    : null,

                'reversed_by_user_id' =>
                $status
                    === PaymentStatus::Reversed
                    ? $reversedBy?->id
                    : null,

                'reversal_reason' =>
                $status
                    === PaymentStatus::Reversed
                    ? 'Read test reversal'
                    : null,
            ]);

        PaymentAllocation::factory()
            ->forPayment(
                $payment
            )
            ->forFeeInstallment(
                $installment
            )
            ->create([
                'amount' =>
                $amount,
            ]);

        return $payment;
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