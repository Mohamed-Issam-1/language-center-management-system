<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FinanceEmployees\Pages\ListFinanceEmployees;
use App\Mail\RegistrationCredentialsMail;
use App\Models\Center;
use App\Models\FinanceEmployee;
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

class FinanceEmployeeAccountActionTest
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

    public function test_center_owner_can_create_link_and_send_finance_employee_credentials(): void
    {
        Mail::fake();

        [
            $center,
            $financeEmployee,
            $person,
        ] = $this->financeEmployeeWithoutAccount();

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
                    'createFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->callAction(
                TestAction::make(
                    'createFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                ),
                [
                    'recovery_email' =>
                    'FINANCE.RECOVERY@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $financeEmployee->refresh();

        $this->assertNotNull(
            $financeEmployee->user_id
        );

        $account =
            User::withoutGlobalScopes()
            ->with('role')
            ->findOrFail(
                $financeEmployee->user_id
            );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $person->id,
            $account->person_id
        );

        $this->assertSame(
            SystemRole::FinanceEmployee,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'finance.recovery@example.test',
            $account->recovery_email
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $identifier =
            (string)
            $account
                ->account_login_identifier;

        $this->assertSame(
            8,
            strlen(
                $identifier
            )
        );

        $this->assertTrue(
            ctype_digit(
                $identifier
            )
        );

        /*
         * Branch assignment remains separate.
         */
        $this->assertDatabaseCount(
            'finance_employee_assignments',
            0
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    public function test_existing_active_finance_account_is_linked_without_creating_assignment(): void
    {
        [
            $center,
            $financeEmployee,
            $person,
        ] = $this->financeEmployeeWithoutAccount();

        $existing =
            $this->financeAccount(
                $center,
                $person
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
            ->assertActionHidden(
                TestAction::make(
                    'createFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'linkExistingFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->callAction(
                TestAction::make(
                    'linkExistingFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertHasNoActionErrors();

        $financeEmployee->refresh();

        $this->assertSame(
            $existing->id,
            $financeEmployee->user_id
        );

        $this->assertSame(
            1,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'person_id',
                    $person->id
                )
                ->where(
                    'role_id',
                    $this->role(
                        SystemRole::FinanceEmployee
                    )->id
                )
                ->count()
        );

        $this->assertDatabaseCount(
            'finance_employee_assignments',
            0
        );
    }

    public function test_existing_deactivated_finance_account_prevents_duplicate_creation(): void
    {
        [
            $center,
            $financeEmployee,
            $person,
        ] = $this->financeEmployeeWithoutAccount();

        $this->financeAccount(
            $center,
            $person,
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
            ->assertActionHidden(
                TestAction::make(
                    'createFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            );
    }

    public function test_deactivated_finance_employee_exposes_no_create_or_link_account_action(): void
    {
        [
            $center,
            $financeEmployee,
        ] = $this->financeEmployeeWithoutAccount(
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
            ->assertActionHidden(
                TestAction::make(
                    'createFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingFinanceEmployeeAccount'
                )->table(
                    $financeEmployee
                )
            );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:FinanceEmployee,
     *     2:Person
     * }
     */
    private function financeEmployeeWithoutAccount(
        StaffStatus $status =
        StaffStatus::Active
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

        $financeEmployee =
            FinanceEmployee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                null,

                'status' =>
                $status,

                'deactivated_at' =>
                $status
                    === StaffStatus::Deactivated
                    ? now()
                    : null,
            ]);

        return [
            $center,
            $financeEmployee,
            $person,
        ];
    }

    private function financeAccount(
        Center $center,
        Person $person,
        AccountStatus $status =
        AccountStatus::Active
    ): User {
        return User::factory()
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
                $status,

                'deactivated_at' =>
                $status
                    === AccountStatus::Deactivated
                    ? now()
                    : null,

                'must_change_password' =>
                false,
            ]);
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