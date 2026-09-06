<?php

namespace Tests\Feature\Filament;

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
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_payments_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownPayment =
            $this->createPayment(
                $branchA
            );

        $otherPayment =
            $this->createPayment(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            PaymentResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownPayment->id,
            $ids
        );

        $this->assertNotContains(
            $otherPayment->id,
            $ids
        );

        $this->assertTrue(
            PaymentResource
                ::canViewAny()
        );

        $this->assertTrue(
            PaymentResource
                ::canView(
                    $ownPayment
                )
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
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
    }

    public function test_branch_manager_sees_only_payments_from_assigned_branch(): void
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

        $ownPayment =
            $this->createPayment(
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

        $ids =
            PaymentResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $ownPayment->id,
            ],
            $ids
        );

        $this->assertTrue(
            PaymentResource
                ::canViewAny()
        );

        $this->assertTrue(
            PaymentResource
                ::canView(
                    $ownPayment
                )
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
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
    }

    public function test_finance_employee_sees_only_payments_from_assigned_branch(): void
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

        $financeEmployee =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $financeEmployee,
            $ownBranch
        );

        $ownPayment =
            $this->createPayment(
                $ownBranch
            );

        $otherPayment =
            $this->createPayment(
                $otherBranch
            );

        $this->actingAs(
            $financeEmployee
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        $ids =
            PaymentResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $ownPayment->id,
            ],
            $ids
        );

        $this->assertTrue(
            PaymentResource
                ::canViewAny()
        );

        $this->assertTrue(
            PaymentResource
                ::canView(
                    $ownPayment
                )
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
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
    }

    public function test_ended_branch_manager_assignment_fails_closed_even_with_stale_context(): void
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

        $assignment =
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

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            PaymentResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            PaymentResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
                    $payment
                )
        );

        $this->assertNull(
            PaymentResource
                ::resolveRecordRouteBinding(
                    $payment
                        ->getKey()
                )
        );
    }

    public function test_ended_finance_employee_assignment_fails_closed_even_with_stale_context(): void
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

        $assignment =
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

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            PaymentResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            PaymentResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
                    $payment
                )
        );
    }

    public function test_other_roles_cannot_access_payment_resource(): void
    {
        $platformOwner =
            User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this
                    ->role(
                        SystemRole::PlatformOwner
                    )
                    ->id,

                'status' =>
                AccountStatus::Active,
            ]);

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            PaymentResource
                ::canViewAny()
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        foreach (
            [
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user =
                $this->createCenterUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $user
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            app(BranchContext::class)
                ->clear();

            $this->assertFalse(
                PaymentResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                PaymentResource
                    ::getEloquentQuery()
                    ->count()
            );
        }
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
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

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            PaymentResource
                ::canViewAny()
        );

        $this->assertFalse(
            PaymentResource
                ::canView(
                    $payment
                )
        );

        $this->assertSame(
            0,
            PaymentResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_payment_crud_is_disabled(): void
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

        $this->assertFalse(
            PaymentResource
                ::canCreate()
        );

        $this->assertFalse(
            PaymentResource
                ::canEdit(
                    $payment
                )
        );

        $this->assertFalse(
            PaymentResource
                ::canDelete(
                    $payment
                )
        );

        $this->assertFalse(
            PaymentResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            PaymentResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                PaymentResource
                    ::getPages()
            )
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