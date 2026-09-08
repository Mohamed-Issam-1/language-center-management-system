<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FinanceEmployees\Pages\ListFinanceEmployees;
use App\Models\Branch;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceEmployeeAdministrativeActionTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_update_finance_employee_identity(): void
    {
        [
            $center,
            $financeEmployee,
            $person,
            $account,
        ] = $this->financeEmployee();

        $account->forceFill([
            'recovery_email' =>
            'recovery@example.test',
        ])->save();

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListFinanceEmployees::class
        )
            ->callAction(
                TestAction::make(
                    'editFinanceEmployeeIdentity'
                )->table(
                    $financeEmployee
                ),
                [
                    'full_name' =>
                    'Updated Finance Employee',

                    'date_of_birth' =>
                    '1994-06-12',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'UPDATED.FINANCE@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599123456',
                ]
            )
            ->assertHasNoActionErrors();

        $person->refresh();
        $account->refresh();

        $this->assertSame(
            'Updated Finance Employee',
            $person->full_name
        );

        $this->assertSame(
            'updated.finance@example.test',
            $person->email
        );

        $this->assertSame(
            '+970599123456',
            $person->phone_number
        );

        /*
         * Person email and account recovery email remain
         * deliberately independent.
         */
        $this->assertSame(
            'recovery@example.test',
            $account->recovery_email
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.identity_updated',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_deactivating_finance_employee_ends_active_assignment(): void
    {
        [
            $center,
            $financeEmployee,
            $person,
            $account,
        ] = $this->financeEmployee();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $assignment =
            FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $account->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListFinanceEmployees::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateFinanceEmployee'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $financeEmployee->refresh();
        $assignment->refresh();
        $account->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $financeEmployee->status
        );

        $this->assertNotNull(
            $financeEmployee->deactivated_at
        );

        $this->assertNull(
            $assignment->active_marker
        );

        $this->assertNotNull(
            $assignment->ended_at
        );

        /*
         * Authentication lifecycle is separate.
         */
        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );
    }

    public function test_reactivation_does_not_recreate_finance_assignment(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployee(
            StaffStatus::Deactivated
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListFinanceEmployees::class
        )
            ->callAction(
                TestAction::make(
                    'activateFinanceEmployee'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $financeEmployee->refresh();

        $this->assertSame(
            StaffStatus::Active,
            $financeEmployee->status
        );

        $this->assertNull(
            $financeEmployee->deactivated_at
        );

        $this->assertDatabaseCount(
            'finance_employee_assignments',
            0
        );
    }

    public function test_finance_employee_cannot_be_reactivated_while_linked_account_is_deactivated(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployee(
            StaffStatus::Deactivated,
            AccountStatus::Deactivated
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListFinanceEmployees::class
        )
            ->callAction(
                TestAction::make(
                    'activateFinanceEmployee'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $financeEmployee->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $financeEmployee->status
        );

        $this->assertNotNull(
            $financeEmployee->deactivated_at
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:FinanceEmployee,
     *     2:Person,
     *     3:User
     * }
     */
    private function financeEmployee(
        StaffStatus $staffStatus =
        StaffStatus::Active,
        AccountStatus $accountStatus =
        AccountStatus::Active
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900000001',

                'full_name' =>
                'Original Finance Employee',

                'date_of_birth' =>
                '1990-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'finance@example.test',

                'phone_number' =>
                '+970590000000',
            ]);

        $account =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    SystemRole::FinanceEmployee
                )->id,

                'status' =>
                $accountStatus,

                'deactivated_at' =>
                $accountStatus
                    === AccountStatus::Deactivated
                    ? now()
                    : null,

                'must_change_password' =>
                false,
            ]);

        $financeEmployee =
            FinanceEmployee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $account->id,

                'status' =>
                $staffStatus,

                'deactivated_at' =>
                $staffStatus
                    === StaffStatus::Deactivated
                    ? now()
                    : null,
            ]);

        return [
            $center,
            $financeEmployee,
            $person,
            $account,
        ];
    }

    private function centerOwner(
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
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function centerWide(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
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