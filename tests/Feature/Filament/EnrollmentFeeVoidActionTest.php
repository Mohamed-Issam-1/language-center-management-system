<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EnrollmentFees\EnrollmentFeeResource;
use App\Filament\Resources\EnrollmentFees\Pages\ListEnrollmentFees;
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
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentFeeVoidActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_void_active_fee_through_filament_action(): void
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
            $fee,
            $installment,
        ] = $this->createObligation(
            $branch
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
            ListEnrollmentFees::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                )
            )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    'Enrollment charge cancelled.',
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $fee->status
        );

        $this->assertSame(
            $owner->id,
            $fee->voided_by_user_id
        );

        $this->assertSame(
            'Enrollment charge cancelled.',
            $fee->void_reason
        );

        $this->assertNotNull(
            $fee->voided_at
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
                'action_type' =>
                'finance.fee_voided',

                'subject_type' =>
                'enrollment_fees',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_branch_manager_can_void_fee_inside_assigned_branch(): void
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
            $fee,
        ] = $this->createObligation(
            $branch
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
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    'Manager approved cancellation.',
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $fee->status
        );

        $this->assertSame(
            $manager->id,
            $fee->voided_by_user_id
        );
    }

    public function test_finance_employee_can_void_fee_inside_assigned_branch(): void
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
            $fee,
        ] = $this->createObligation(
            $branch
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
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    'Financial correction.',
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Voided,
            $fee->status
        );

        $this->assertSame(
            $finance->id,
            $fee->voided_by_user_id
        );
    }

    public function test_void_action_is_hidden_for_already_voided_fee(): void
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
            $fee,
        ] = $this->createObligation(
            $branch
        );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $fee->forceFill([
            'status' =>
            EnrollmentFeeStatus::Voided,

            'voided_by_user_id' =>
            $owner->id,

            'voided_at' =>
            now(),

            'void_reason' =>
            'Already voided.',
        ])->save();

        $fee->refresh();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                )
            );
    }

    public function test_void_reason_is_required(): void
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
            $fee,
        ] = $this->createObligation(
            $branch
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
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    '',
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $fee->status
        );

        $this->assertNull(
            $fee->voided_at
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_voided',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_void_reason_cannot_exceed_255_characters(): void
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
            $fee,
        ] = $this->createObligation(
            $branch
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
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    str_repeat(
                        'A',
                        256
                    ),
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $fee->status
        );

        $this->assertNull(
            $fee->voided_at
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_voided',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_fee_with_posted_payment_cannot_be_voided(): void
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
            $fee,
            $installment,
            $student,
        ] = $this->createObligation(
            $branch,
            '100.00'
        );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            Payment::factory()
            ->forBranch(
                $branch
            )
            ->forStudent(
                $student
            )
            ->posted()
            ->create([
                'amount' =>
                '25.00',

                'currency_code' =>
                $fee->currency_code,
            ]);

        PaymentAllocation::factory()
            ->forPayment(
                $payment
            )
            ->forFeeInstallment(
                $installment
            )
            ->create([
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,

                'amount' =>
                '25.00',
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                )
            )
            ->callAction(
                TestAction::make(
                    'voidFee'
                )->table(
                    $fee
                ),
                [
                    'reason' =>
                    'Attempt cancellation.',
                ]
            );

        $fee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $fee->status
        );

        $this->assertNull(
            $fee->voided_at
        );

        $this->assertDatabaseHas(
            'payments',
            [
                'id' =>
                $payment->id,
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_voided',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_branch_manager_cannot_access_void_action_for_other_branch_fee(): void
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

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        [
            $otherFee,
        ] = $this->createObligation(
            $otherBranch
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->assertCanNotSeeTableRecords([
                $otherFee,
            ]);

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $otherFee
                )
        );

        $this->assertNull(
            EnrollmentFeeResource
                ::resolveRecordRouteBinding(
                    $otherFee
                        ->getKey()
                )
        );

        $otherFee->refresh();

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $otherFee->status
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_voided',

                'subject_id' =>
                $otherFee->id,
            ]
        );
    }

    /**
     * @return array{0: EnrollmentFee, 1: FeeInstallment, 2: Student}
     */
    private function createObligation(
        Branch $branch,
        string $amount = '100.00'
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
            $fee,
            $installment,
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