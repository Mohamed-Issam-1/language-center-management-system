<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentFinanceReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFinanceReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_overview_returns_own_authoritative_balance_and_posted_payment_history(): void
    {
        $context =
            $this->studentContext(
                'Finance Student'
            );

        $obligation =
            $this->obligation(
                $context['student'],
                $context['branch'],
                '100.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $payment =
            $this->payment(
                student: $context['student'],
                branch: $context['branch'],
                receiver: $receiver,
                amount: '40.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-STUDENT-001'
            );

        $this->allocation(
            $payment,
            $obligation['installment'],
            '40.00'
        );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            'Finance Student',
            $result['student']['name']
        );

        $this->assertSame(
            $context['center']->id,
            $result['balance']['center_id']
        );

        $this->assertSame(
            $context['student']->id,
            $result['balance']['student_id']
        );

        $this->assertArrayHasKey(
            'USD',
            $result['balance']['totals']
        );

        $this->assertSame(
            '100.00',
            $result['balance']['totals']['USD']['obligation']
        );

        $this->assertSame(
            '40.00',
            $result['balance']['totals']['USD']['paid']
        );

        $this->assertSame(
            '60.00',
            $result['balance']['totals']['USD']['balance']
        );

        $this->assertCount(
            1,
            $result['balance']['installments']
        );

        $this->assertSame(
            '40.00',
            $result['balance']['installments'][0]['paid']
        );

        $this->assertSame(
            '60.00',
            $result['balance']['installments'][0]['balance']
        );

        $this->assertCount(
            1,
            $result['payments']
        );

        $history =
            $result['payments'][0];

        $this->assertSame(
            $payment->id,
            $history['payment_id']
        );

        $this->assertSame(
            'RCT-STUDENT-001',
            $history['receipt_number']
        );

        $this->assertSame(
            '40.00',
            $history['amount']
        );

        $this->assertSame(
            'USD',
            $history['currency_code']
        );

        $this->assertSame(
            PaymentStatus::Posted->value,
            $history['status']
        );

        $this->assertSame(
            PaymentStatus::Posted->label(),
            $history['status_label']
        );

        $this->assertNull(
            $history['reversal']
        );

        $this->assertSame(
            $context['branch']->id,
            $history['branch']['id']
        );
    }

    public function test_reversed_payment_remains_in_history_but_does_not_reduce_balance(): void
    {
        $context =
            $this->studentContext(
                'Reversed Finance Student'
            );

        $obligation =
            $this->obligation(
                $context['student'],
                $context['branch'],
                '100.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $payment =
            $this->payment(
                student: $context['student'],
                branch: $context['branch'],
                receiver: $receiver,
                amount: '60.00',
                status: PaymentStatus::Reversed,
                receiptNumber: 'RCT-REVERSED-001'
            );

        $this->allocation(
            $payment,
            $obligation['installment'],
            '60.00'
        );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        /*
         * Reversed money must not satisfy the obligation.
         */
        $this->assertSame(
            '0.00',
            $result['balance']['totals']['USD']['paid']
        );

        $this->assertSame(
            '100.00',
            $result['balance']['totals']['USD']['balance']
        );

        /*
         * But immutable financial history remains visible.
         */
        $this->assertCount(
            1,
            $result['payments']
        );

        $history =
            $result['payments'][0];

        $this->assertSame(
            PaymentStatus::Reversed->value,
            $history['status']
        );

        $this->assertNotNull(
            $history['reversal']
        );

        $this->assertSame(
            'Test reversal',
            $history['reversal']['reason']
        );
    }

    public function test_historical_branch_finance_remains_visible_after_student_branch_move(): void
    {
        $context =
            $this->studentContext(
                'Moved Student'
            );

        $oldBranch =
            $context['branch'];

        $newBranch =
            Branch::factory()
            ->for(
                $context['center']
            )
            ->active()
            ->create();

        $obligation =
            $this->obligation(
                $context['student'],
                $oldBranch,
                '80.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $payment =
            $this->payment(
                student: $context['student'],
                branch: $oldBranch,
                receiver: $receiver,
                amount: '30.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-HISTORICAL-BRANCH'
            );

        $this->allocation(
            $payment,
            $obligation['installment'],
            '30.00'
        );

        /*
         * Administrative Student Branch changes.
         *
         * Historical financial ownership must remain attached
         * to the Branch where the financial record originated.
         */
        $context['student']
            ->forceFill([
                'branch_id' =>
                $newBranch->id,
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            $newBranch->id,
            $result['student']['branch']['id']
        );

        $this->assertSame(
            '80.00',
            $result['balance']['totals']['USD']['obligation']
        );

        $this->assertSame(
            '30.00',
            $result['balance']['totals']['USD']['paid']
        );

        $this->assertSame(
            '50.00',
            $result['balance']['totals']['USD']['balance']
        );

        $this->assertCount(
            1,
            $result['payments']
        );

        $this->assertSame(
            $oldBranch->id,
            $result['payments'][0]['branch']['id']
        );

        $this->assertSame(
            'RCT-HISTORICAL-BRANCH',
            $result['payments'][0]['receipt_number']
        );
    }

    public function test_overview_does_not_include_another_students_financial_records(): void
    {
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

        $studentA =
            $this->studentContext(
                'Finance Student A',
                $center,
                $branch
            );

        $studentB =
            $this->studentContext(
                'Finance Student B',
                $center,
                $branch
            );

        $obligationA =
            $this->obligation(
                $studentA['student'],
                $branch,
                '100.00'
            );

        $obligationB =
            $this->obligation(
                $studentB['student'],
                $branch,
                '500.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $paymentA =
            $this->payment(
                student: $studentA['student'],
                branch: $branch,
                receiver: $receiver,
                amount: '25.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-OWN'
            );

        $paymentB =
            $this->payment(
                student: $studentB['student'],
                branch: $branch,
                receiver: $receiver,
                amount: '400.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-OTHER'
            );

        $this->allocation(
            $paymentA,
            $obligationA['installment'],
            '25.00'
        );

        $this->allocation(
            $paymentB,
            $obligationB['installment'],
            '400.00'
        );

        $this->establishCenterContext(
            $center
        );

        $result =
            $this->service()
            ->overview(
                $studentA['user']
            );

        $this->assertSame(
            '100.00',
            $result['balance']['totals']['USD']['obligation']
        );

        $this->assertSame(
            '25.00',
            $result['balance']['totals']['USD']['paid']
        );

        $this->assertSame(
            '75.00',
            $result['balance']['totals']['USD']['balance']
        );

        $paymentIds =
            collect(
                $result['payments']
            )
            ->pluck(
                'payment_id'
            )
            ->all();

        $this->assertSame(
            [
                $paymentA->id,
            ],
            $paymentIds
        );

        $this->assertNotContains(
            $paymentB->id,
            $paymentIds
        );
    }

    public function test_receipt_returns_only_owned_receipt_with_allocation_details(): void
    {
        $context =
            $this->studentContext(
                'Receipt Student'
            );

        $obligation =
            $this->obligation(
                $context['student'],
                $context['branch'],
                '120.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $payment =
            $this->payment(
                student: $context['student'],
                branch: $context['branch'],
                receiver: $receiver,
                amount: '45.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-OWN-RECEIPT'
            );

        $allocation =
            $this->allocation(
                $payment,
                $obligation['installment'],
                '45.00'
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->receipt(
                $context['user'],
                'RCT-OWN-RECEIPT'
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $receipt =
            $result['receipt'];

        $this->assertSame(
            $payment->id,
            $receipt['payment_id']
        );

        $this->assertSame(
            'RCT-OWN-RECEIPT',
            $receipt['receipt_number']
        );

        $this->assertSame(
            $context['student']->id,
            $receipt['student']['id']
        );

        $this->assertSame(
            '45.00',
            $receipt['amount']
        );

        $this->assertSame(
            PaymentStatus::Posted->value,
            $receipt['status']
        );

        $this->assertCount(
            1,
            $receipt['allocations']
        );

        $receiptAllocation =
            $receipt['allocations'][0];

        $this->assertSame(
            $allocation->id,
            $receiptAllocation['allocation_id']
        );

        $this->assertSame(
            $obligation['installment']->id,
            $receiptAllocation['installment_id']
        );

        $this->assertSame(
            $obligation['fee']->id,
            $receiptAllocation['fee_id']
        );

        $this->assertSame(
            $obligation['enrollment']->id,
            $receiptAllocation['enrollment_id']
        );

        $this->assertSame(
            '45.00',
            $receiptAllocation['amount']
        );
    }

    public function test_receipt_rejects_another_students_payment(): void
    {
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

        $studentA =
            $this->studentContext(
                'Receipt Student A',
                $center,
                $branch
            );

        $studentB =
            $this->studentContext(
                'Receipt Student B',
                $center,
                $branch
            );

        $obligationB =
            $this->obligation(
                $studentB['student'],
                $branch,
                '100.00'
            );

        $receiver =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $paymentB =
            $this->payment(
                student: $studentB['student'],
                branch: $branch,
                receiver: $receiver,
                amount: '20.00',
                status: PaymentStatus::Posted,
                receiptNumber: 'RCT-FOREIGN'
            );

        $this->allocation(
            $paymentB,
            $obligationB['installment'],
            '20.00'
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->receipt(
                $studentA['user'],
                'RCT-FOREIGN'
            );
    }

    public function test_student_without_financial_records_receives_explicit_empty_state(): void
    {
        $context =
            $this->studentContext(
                'Empty Finance Student'
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            [],
            $result['balance']['totals']
        );

        $this->assertSame(
            [],
            $result['balance']['installments']
        );

        $this->assertSame(
            [],
            $result['payments']
        );
    }

    public function test_student_finance_read_service_uses_persisted_account_state(): void
    {
        $context =
            $this->studentContext(
                'Deactivated Finance Student'
            );

        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        User::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $context['user']->id
            )
            ->update([
                'status' =>
                AccountStatus::Deactivated
                    ->value,

                'deactivated_at' =>
                now(),
            ]);

        /*
         * Supplied object is deliberately stale.
         */
        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->overview(
                $context['user']
            );
    }

    public function test_student_finance_read_service_fails_closed_on_tenant_mismatch(): void
    {
        $context =
            $this->studentContext(
                'Tenant Finance Student'
            );

        $otherCenter =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $this->establishCenterContext(
            $otherCenter
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->overview(
                $context['user']
            );
    }

    /**
     * @return array{
     *     center: Center,
     *     branch: Branch,
     *     person: Person,
     *     user: User,
     *     student: Student
     * }
     */
    private function studentContext(
        string $name,
        ?Center $center = null,
        ?Branch $branch = null
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch ??=
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                $name,
            ]);

        $user =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        $student =
            Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        return [
            'center' =>
            $center,

            'branch' =>
            $branch,

            'person' =>
            $person,

            'user' =>
            $user,

            'student' =>
            $student,
        ];
    }

    /**
     * @return array{
     *     enrollment: Enrollment,
     *     fee: EnrollmentFee,
     *     installment: FeeInstallment
     * }
     */
    private function obligation(
        Student $student,
        Branch $branch,
        string $amount
    ): array {
        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'enrollment_date' =>
                '2026-08-01',
            ]);

        $creator =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $branch->center
            );

        $fee =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            )
            ->createdBy(
                $creator
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
                '2026-12-01',
            ]);

        return [
            'enrollment' =>
            $enrollment,

            'fee' =>
            $fee,

            'installment' =>
            $installment,
        ];
    }

    private function payment(
        Student $student,
        Branch $branch,
        User $receiver,
        string $amount,
        PaymentStatus $status,
        string $receiptNumber
    ): Payment {
        $factory =
            Payment::factory()
            ->forBranch(
                $branch
            )
            ->forStudent(
                $student
            )
            ->receivedBy(
                $receiver
            );

        $factory =
            $status === PaymentStatus::Posted
            ? $factory->posted()
            : $factory->reversed(
                $receiver
            );

        return $factory
            ->create([
                'receipt_number' =>
                $receiptNumber,

                'amount' =>
                $amount,

                'currency_code' =>
                'USD',

                'payment_method' =>
                'cash',

                'paid_at' =>
                '2026-09-08 10:00:00',

                'reference' =>
                'REF-' . $receiptNumber,

                'notes' =>
                'Student finance read test.',

                'reversal_reason' =>
                $status === PaymentStatus::Reversed
                    ? 'Test reversal'
                    : null,
            ]);
    }

    private function allocation(
        Payment $payment,
        FeeInstallment $installment,
        string $amount
    ): PaymentAllocation {
        return PaymentAllocation::factory()
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
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );
    }

    private function createUserForRole(
        SystemRole $role,
        Center $center,
        ?Person $person = null
    ): User {
        $person ??=
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

                'status' =>
                AccountStatus::Active,
            ]);
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

    private function service(): StudentFinanceReadService
    {
        return app(
            StudentFinanceReadService::class
        );
    }
}
