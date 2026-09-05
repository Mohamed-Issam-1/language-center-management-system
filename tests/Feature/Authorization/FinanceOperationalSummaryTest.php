<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
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
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_summary_combines_own_center_branches_only(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        [$studentA, $installmentA] =
            $this->createObligation(
                $center,
                $branchA,
                $actor,
                100
            );

        [$studentB] =
            $this->createObligation(
                $center,
                $branchB,
                $actor,
                50
            );

        $this->createPostedPayment(
            $center,
            $branchA,
            $studentA,
            $actor,
            $installmentA,
            40
        );

        $this->createReversedPayment(
            $center,
            $branchB,
            $studentB,
            $actor,
            20
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $summary =
            $this->service()
            ->operationalSummary(
                $actor
            );

        $this->assertSame(
            $center->id,
            $summary['center_id']
        );

        $this->assertNull(
            $summary['branch_id']
        );

        $currency =
            $summary['currencies'][$center->operating_currency_code];

        $this->assertSame(
            2,
            $currency['active_fee_count']
        );

        $this->assertSame(
            '150.00',
            $currency['installment_amount']
        );

        $this->assertSame(
            '40.00',
            $currency['allocated_amount']
        );

        $this->assertSame(
            '110.00',
            $currency['outstanding_amount']
        );

        $this->assertSame(
            1,
            $currency['posted_payment_count']
        );

        $this->assertSame(
            '40.00',
            $currency['posted_payment_amount']
        );

        $this->assertSame(
            1,
            $currency['reversed_payment_count']
        );

        $this->assertSame(
            '20.00',
            $currency['reversed_payment_amount']
        );
    }

    public function test_branch_manager_summary_is_limited_to_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->createObligation(
            $center,
            $branchA,
            $actor,
            80
        );

        $this->createObligation(
            $center,
            $branchB,
            $actor,
            120
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $summary =
            $this->service()
            ->operationalSummary(
                $actor
            );

        $this->assertSame(
            $branchA->id,
            $summary['branch_id']
        );

        $currency =
            $summary['currencies'][$center->operating_currency_code];

        $this->assertSame(
            1,
            $currency['active_fee_count']
        );

        $this->assertSame(
            '80.00',
            $currency['outstanding_amount']
        );
    }

    public function test_finance_employee_can_read_assigned_branch_summary(): void
    {
        $center =
            $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branch->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->createObligation(
            $center,
            $branch,
            $actor,
            75
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        $summary =
            $this->service()
            ->operationalSummary(
                $actor
            );

        $this->assertSame(
            $branch->id,
            $summary['branch_id']
        );

        $this->assertSame(
            '75.00',
            $summary['currencies'][$center->operating_currency_code]['outstanding_amount']
        );
    }

    public function test_student_cannot_read_operational_financial_summary(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $actor =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->operationalSummary(
                $actor
            );
    }

    /**
     * @return array{Student, FeeInstallment}
     */
    private function createObligation(
        Center $center,
        Branch $branch,
        User $creator,
        float $amount
    ): array {
        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,
            ]);

        $fee =
            EnrollmentFee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'enrollment_id' =>
                $enrollment->id,

                'amount' =>
                $amount,

                'currency_code' =>
                $center->operating_currency_code,

                'status' =>
                EnrollmentFeeStatus::Active,

                'created_by_user_id' =>
                $creator->id,
            ]);

        $installment =
            FeeInstallment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'enrollment_fee_id' =>
                $fee->id,

                'sequence_number' =>
                1,

                'amount' =>
                $amount,
            ]);

        return [
            $student,
            $installment,
        ];
    }

    private function createPostedPayment(
        Center $center,
        Branch $branch,
        Student $student,
        User $receiver,
        FeeInstallment $installment,
        float $amount
    ): Payment {
        $payment =
            Payment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'student_id' =>
                $student->id,

                'amount' =>
                $amount,

                'currency_code' =>
                $center->operating_currency_code,

                'received_by_user_id' =>
                $receiver->id,

                'status' =>
                PaymentStatus::Posted,
            ]);

        PaymentAllocation::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'payment_id' =>
                $payment->id,

                'fee_installment_id' =>
                $installment->id,

                'amount' =>
                $amount,
            ]);

        return $payment;
    }

    private function createReversedPayment(
        Center $center,
        Branch $branch,
        Student $student,
        User $receiver,
        float $amount
    ): Payment {
        return Payment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'student_id' =>
                $student->id,

                'amount' =>
                $amount,

                'currency_code' =>
                $center->operating_currency_code,

                'received_by_user_id' =>
                $receiver->id,

                'status' =>
                PaymentStatus::Reversed,

                'reversed_at' =>
                now(),

                'reversed_by_user_id' =>
                $receiver->id,

                'reversal_reason' =>
                'Test reversal',
            ]);
    }

    private function service(): FinanceReadService
    {
        return app(
            FinanceReadService::class
        );
    }

    private function tenant(): TenantContext
    {
        return app(
            TenantContext::class
        );
    }

    private function branchContext(): BranchContext
    {
        return app(
            BranchContext::class
        );
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }

    private function createUserForRole(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    $role
                )->id,
            ]);
    }
}