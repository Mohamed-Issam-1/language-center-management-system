<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentRecordActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_record_partial_payment_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $student,
            $installment,
        ] = $this->createObligation(
            $branch,
            '100.00'
        );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListPayments::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'recordPayment'
                )
            )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'filament-owner-payment-001',

                    'branch_id' =>
                    $branch->id,

                    'student_id' =>
                    $student->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            '40.00',
                        ],
                    ],

                    'amount' =>
                    '40.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:00:00',

                    'reference' =>
                    'UI-REF-001',

                    'notes' =>
                    'Recorded through Filament.',
                ]
            );

        $payment =
            Payment::withoutGlobalScopes()
            ->firstOrFail();

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
            $owner->id,
            $payment
                ->received_by_user_id
        );

        $this->assertDatabaseHas(
            'payment_allocations',
            [
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
                'finance.payment_posted',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_branch_manager_can_record_payment_inside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $student,
            $installment,
        ] = $this->createObligation(
            $branch,
            '75.00'
        );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListPayments::class
        )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'filament-manager-payment',

                    'branch_id' =>
                    $branch->id,

                    'student_id' =>
                    $student->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:10:00',
                ]
            );

        $payment =
            Payment::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $manager->id,
            $payment
                ->received_by_user_id
        );

        $this->assertSame(
            $branch->id,
            $payment->branch_id
        );
    }

    public function test_finance_employee_can_record_payment_inside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $student,
            $installment,
        ] = $this->createObligation(
            $branch,
            '60.00'
        );

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $branch
        );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListPayments::class
        )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'filament-finance-payment',

                    'branch_id' =>
                    $branch->id,

                    'student_id' =>
                    $student->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            '30.00',
                        ],
                    ],

                    'amount' =>
                    '30.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:20:00',
                ]
            );

        $payment =
            Payment::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $finance->id,
            $payment
                ->received_by_user_id
        );

        $this->assertSame(
            $branch->id,
            $payment->branch_id
        );
    }

    public function test_payment_can_target_historical_financial_branch_after_student_moves(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $financialBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $newStudentBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $student,
            $installment,
        ] = $this->createObligation(
            $financialBranch,
            '80.00'
        );

        /*
         * Student moved after the historical Enrollment.
         * The Fee remains financially owned by the old Branch.
         */
        $student->forceFill([
            'branch_id' =>
            $newStudentBranch->id,
        ])->save();

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $financialBranch
        );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $financialBranch
        );

        Livewire::test(
            ListPayments::class
        )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'historical-branch-payment',

                    'branch_id' =>
                    $financialBranch->id,

                    'student_id' =>
                    $student->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            '20.00',
                        ],
                    ],

                    'amount' =>
                    '20.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:30:00',
                ]
            );

        $payment =
            Payment::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $financialBranch->id,
            $payment->branch_id
        );

        $this->assertSame(
            $student->id,
            $payment->student_id
        );

        $this->assertNotSame(
            $student->branch_id,
            $payment->branch_id
        );
    }

    public function test_branch_manager_cannot_inject_payment_into_other_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $otherStudent,
            $otherInstallment,
        ] = $this->createObligation(
            $otherBranch,
            '50.00'
        );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListPayments::class
        )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'cross-branch-ui-payment',

                    'branch_id' =>
                    $otherBranch->id,

                    'student_id' =>
                    $otherStudent->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $otherInstallment->id,

                            'amount' =>
                            '25.00',
                        ],
                    ],

                    'amount' =>
                    '25.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:40:00',
                ]
            );

        $this->assertDatabaseCount(
            'payments',
            0
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_posted',
            ]
        );
    }

    public function test_payment_total_must_equal_allocation_total(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $student,
            $installment,
        ] = $this->createObligation(
            $branch,
            '100.00'
        );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListPayments::class
        )
            ->callAction(
                TestAction::make(
                    'recordPayment'
                ),
                [
                    'idempotency_key' =>
                    'mismatch-ui-payment',

                    'branch_id' =>
                    $branch->id,

                    'student_id' =>
                    $student->id,

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installment->id,

                            'amount' =>
                            '40.00',
                        ],
                    ],

                    'amount' =>
                    '50.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-04 19:50:00',
                ]
            );

        $this->assertDatabaseCount(
            'payments',
            0
        );

        $this->assertDatabaseCount(
            'payment_allocations',
            0
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_posted',
            ]
        );
    }

    /**
     * @return array{0: Student, 1: FeeInstallment}
     */
    private function createObligation(
        Branch $branch,
        string $amount
    ): array {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

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
            ->create();

        $fee =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            )
            ->active()
            ->create([
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,

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
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,

                'amount' =>
                $amount,

                'due_date' =>
                now()
                    ->toDateString(),
            ]);

        return [
            $student,
            $installment,
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
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $manager->id,

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

    private function assignFinanceEmployee(
        User $user,
        Branch $branch
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment
            ::withoutGlobalScopes()
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

    private function createCenterUser(
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
                $this
                    ->role(
                        $role
                    )
                    ->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
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
}