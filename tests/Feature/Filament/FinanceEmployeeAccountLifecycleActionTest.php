<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FinanceEmployees\Pages\ListFinanceEmployees;
use App\Mail\RegistrationCredentialsMail;
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
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceEmployeeAccountLifecycleActionTest
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

    public function test_active_account_exposes_deactivate_and_reissue_only(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployee();

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
            ->assertActionVisible(
                TestAction::make(
                    'deactivateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reissueFinanceEmployeeCredentials'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'activateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            );
    }

    public function test_account_deactivation_preserves_operational_record_and_assignment(): void
    {
        [
            $center,
            $financeEmployee,
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
                    'deactivateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();
        $financeEmployee->refresh();
        $assignment->refresh();

        $this->assertSame(
            AccountStatus::Deactivated,
            $account->status
        );

        $this->assertNotNull(
            $account->deactivated_at
        );

        $this->assertSame(
            StaffStatus::Active,
            $financeEmployee->status
        );

        $this->assertSame(
            1,
            $assignment->active_marker
        );

        $this->assertNull(
            $assignment->ended_at
        );
    }

    public function test_deactivated_account_exposes_activate_only(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployee(
            StaffStatus::Active,
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
            ->assertActionVisible(
                TestAction::make(
                    'activateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueFinanceEmployeeCredentials'
                )->table(
                    $financeEmployee
                )
            );
    }

    public function test_center_owner_can_activate_finance_employee_account(): void
    {
        [
            $center,
            $financeEmployee,
            $account,
        ] = $this->financeEmployee(
            StaffStatus::Active,
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
                    'activateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();
        $financeEmployee->refresh();

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );

        $this->assertSame(
            StaffStatus::Active,
            $financeEmployee->status
        );
    }

    public function test_deactivated_operational_record_does_not_expose_reissue_credentials(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployee(
            StaffStatus::Deactivated,
            AccountStatus::Active
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
            ->assertActionVisible(
                TestAction::make(
                    'deactivateFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueFinanceEmployeeCredentials'
                )->table(
                    $financeEmployee
                )
            );
    }

    public function test_reissue_credentials_changes_password_and_sends_email(): void
    {
        Mail::fake();

        [
            $center,
            $financeEmployee,
            $account,
        ] = $this->financeEmployee();

        $oldPasswordHash =
            (string)
            $account->password;

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
                    'reissueFinanceEmployeeCredentials'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();

        $this->assertFalse(
            hash_equals(
                $oldPasswordHash,
                (string)
                $account->password
            )
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertSame(
            0,
            $account->failed_login_attempts
        );

        $this->assertNull(
            $account->locked_until
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:FinanceEmployee,
     *     2:User
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
            ->create([
                'identifier_code' =>
                '41',
            ]);

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900000001',

                'email' =>
                'finance@example.test',
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

                'account_login_identifier' =>
                '41140001',

                'recovery_email' =>
                'finance@example.test',

                'status' =>
                $accountStatus,

                'password' =>
                'OldFinancePassword123!',

                'must_change_password' =>
                false,

                'failed_login_attempts' =>
                3,

                'locked_until' =>
                now()->addMinutes(
                    5
                ),

                'deactivated_at' =>
                $accountStatus
                    === AccountStatus::Deactivated
                    ? now()
                    : null,
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