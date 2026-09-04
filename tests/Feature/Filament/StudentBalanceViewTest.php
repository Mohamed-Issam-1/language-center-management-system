<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StudentBalances\Pages\ViewStudentBalance;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
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
use App\Support\Enums\AccountStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentBalanceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_views_financial_summary_and_installments(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $student,
            $fee,
            $installment,
            $enrollment,
        ] = $this->obligation(
            $branch,
            '100.00',
            today()
                ->addDay()
                ->toDateString()
        );

        $this->payment(
            $branch,
            $student,
            $installment,
            '40.00'
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
            ViewStudentBalance::class,
            [
                'record' =>
                $student
                    ->getRouteKey(),
            ]
        )
            ->assertSuccessful()
            ->assertSee(
                'Financial Summary'
            )
            ->assertSee(
                'USD'
            )
            ->assertSee(
                '100.00'
            )
            ->assertSee(
                '40.00'
            )
            ->assertSee(
                '60.00'
            )
            ->assertSee(
                $enrollment
                    ->enrollment_number
            )
            ->assertSee(
                $branch->name
            )
            ->assertSee(
                'Outstanding'
            );
    }

    public function test_overdue_installment_is_displayed_from_read_service(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $student,
        ] = $this->obligation(
            $branch,
            '100.00',
            today()
                ->subDay()
                ->toDateString()
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
            ViewStudentBalance::class,
            [
                'record' =>
                $student
                    ->getRouteKey(),
            ]
        )
            ->assertSuccessful()
            ->assertSee(
                'Overdue'
            );
    }

    public function test_branch_manager_views_only_assigned_historical_financial_branch(): void
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

        [
            $student,
            $feeA,
            $installmentA,
            $enrollmentA,
        ] = $this->obligation(
            $branchA,
            '100.00'
        );

        [
            $feeB,
            $installmentB,
            $enrollmentB,
        ] = $this->additionalObligation(
            $branchB,
            $student,
            '200.00'
        );

        /*
         * Student moved operationally to Branch B.
         * Branch A finance remains historical.
         */
        $student->forceFill([
            'branch_id' =>
            $branchB->id,
        ])->save();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        Livewire::test(
            ViewStudentBalance::class,
            [
                'record' =>
                $student
                    ->getRouteKey(),
            ]
        )
            ->assertSuccessful()
            ->assertSee(
                $enrollmentA
                    ->enrollment_number
            )
            ->assertSee(
                $branchA->name
            )
            ->assertSee(
                '100.00'
            )
            ->assertDontSee(
                $enrollmentB
                    ->enrollment_number
            )
            ->assertDontSee(
                '200.00'
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
            ->for(
                $center
            )
            ->active()
            ->create();
    }

    /**
     * @return array{
     *     0: Student,
     *     1: EnrollmentFee,
     *     2: FeeInstallment,
     *     3: Enrollment
     * }
     */
    private function obligation(
        Branch $branch,
        string $amount,
        ?string $dueDate = null
    ): array {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        [
            $fee,
            $installment,
            $enrollment,
        ] = $this->additionalObligation(
            $branch,
            $student,
            $amount,
            $dueDate
        );

        return [
            $student,
            $fee,
            $installment,
            $enrollment,
        ];
    }

    /**
     * @return array{
     *     0: EnrollmentFee,
     *     1: FeeInstallment,
     *     2: Enrollment
     * }
     */
    private function additionalObligation(
        Branch $branch,
        Student $student,
        string $amount,
        ?string $dueDate = null
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
                $dueDate
                    ?? today()
                    ->addMonth()
                    ->toDateString(),
            ]);

        return [
            $fee,
            $installment,
            $enrollment,
        ];
    }

    private function payment(
        Branch $branch,
        Student $student,
        FeeInstallment $installment,
        string $amount
    ): void {
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
                $amount,

                'currency_code' =>
                'USD',

                'status' =>
                PaymentStatus::Posted,
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
                $amount,
            ]);
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

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for(
                $center
            )
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