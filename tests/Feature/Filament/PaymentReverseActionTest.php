<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentReverseActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_reverse_posted_payment_through_filament_action(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            $this->createPayment(
                $branch
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
                    'reversePayment'
                )->table(
                    $payment
                )
            )
            ->callAction(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                ),
                [
                    'reason' =>
                    'Duplicate payment entry.',
                ]
            );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::Reversed,
            $payment->status
        );

        $this->assertSame(
            $owner->id,
            $payment
                ->reversed_by_user_id
        );

        $this->assertSame(
            'Duplicate payment entry.',
            $payment
                ->reversal_reason
        );

        $this->assertNotNull(
            $payment->reversed_at
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_reversed',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_branch_manager_can_reverse_payment_inside_assigned_branch(): void
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

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $payment =
            $this->createPayment(
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
            ->assertActionVisible(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                )
            )
            ->callAction(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                ),
                [
                    'reason' =>
                    'Incorrect payment amount.',
                ]
            );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::Reversed,
            $payment->status
        );

        $this->assertSame(
            $manager->id,
            $payment
                ->reversed_by_user_id
        );
    }

    public function test_finance_employee_can_reverse_payment_inside_assigned_branch(): void
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

        $financeEmployee =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $financeEmployee,
            $branch
        );

        $payment =
            $this->createPayment(
                $branch
            );

        $this->actingAs(
            $financeEmployee
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListPayments::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                )
            )
            ->callAction(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                ),
                [
                    'reason' =>
                    'Payment recorded twice.',
                ]
            );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::Reversed,
            $payment->status
        );

        $this->assertSame(
            $financeEmployee->id,
            $payment
                ->reversed_by_user_id
        );
    }

    public function test_reverse_action_is_hidden_for_already_reversed_payment(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            $this->createPayment(
                $branch
            );

        $payment->forceFill([
            'status' =>
            PaymentStatus::Reversed,

            'reversed_at' =>
            now(),

            'reversed_by_user_id' =>
            $owner->id,

            'reversal_reason' =>
            'Already reversed.',
        ])->save();

        $payment->refresh();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListPayments::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'reversePayment'
                )->table(
                    $payment
                )
            );
    }

    public function test_reversal_reason_is_required(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            $this->createPayment(
                $branch
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
                    'reversePayment'
                )->table(
                    $payment
                ),
                [
                    'reason' =>
                    '',
                ]
            );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::Posted,
            $payment->status
        );

        $this->assertNull(
            $payment
                ->reversed_at
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_reversed',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_reversal_reason_cannot_exceed_255_characters(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $payment =
            $this->createPayment(
                $branch
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
                    'reversePayment'
                )->table(
                    $payment
                ),
                [
                    'reason' =>
                    str_repeat(
                        'A',
                        256
                    ),
                ]
            );

        $payment->refresh();

        $this->assertSame(
            PaymentStatus::Posted,
            $payment->status
        );

        $this->assertNull(
            $payment
                ->reversed_at
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_reversed',

                'subject_id' =>
                $payment->id,
            ]
        );
    }

    public function test_branch_manager_cannot_access_reverse_action_for_other_branch_payment(): void
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

        $otherPayment =
            $this->createPayment(
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
            ListPayments::class
        )
            ->assertCanNotSeeTableRecords([
                $otherPayment,
            ]);

        $this->assertFalse(
            PaymentResource::canView(
                $otherPayment
            )
        );

        $this->assertNull(
            PaymentResource
                ::resolveRecordRouteBinding(
                    $otherPayment
                        ->getKey()
                )
        );

        $otherPayment->refresh();

        $this->assertSame(
            PaymentStatus::Posted,
            $otherPayment->status
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.payment_reversed',

                'subject_id' =>
                $otherPayment->id,
            ]
        );
    }

    private function createPayment(
        Branch $branch
    ): Payment {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        return Payment::factory()
            ->forBranch(
                $branch
            )
            ->forStudent(
                $student
            )
            ->posted()
            ->create();
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