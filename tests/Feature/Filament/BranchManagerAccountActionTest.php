<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BranchManagers\Pages\ListBranchManagers;
use App\Mail\RegistrationCredentialsMail;
use App\Models\BranchManager;
use App\Models\Center;
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

class BranchManagerAccountActionTest
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

    public function test_center_owner_can_create_link_and_send_branch_manager_credentials(): void
    {
        Mail::fake();

        [
            $center,
            $manager,
            $person,
        ] = $this->managerWithoutAccount();

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
            ListBranchManagers::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'createBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->callAction(
                TestAction::make(
                    'createBranchManagerAccount'
                )->table(
                    $manager
                ),
                [
                    'recovery_email' =>
                    'MANAGER.RECOVERY@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $manager->refresh();

        $this->assertNotNull(
            $manager->user_id
        );

        $account =
            User::withoutGlobalScopes()
            ->with('role')
            ->findOrFail(
                $manager->user_id
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
            SystemRole::BranchManager,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'manager.recovery@example.test',
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
         * Account creation never performs Branch
         * assignment implicitly.
         */
        $this->assertDatabaseCount(
            'branch_manager_assignments',
            0
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    public function test_existing_active_branch_manager_account_is_linked_without_creating_assignment(): void
    {
        [
            $center,
            $manager,
            $person,
        ] = $this->managerWithoutAccount();

        $existing =
            $this->managerAccount(
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
            ListBranchManagers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'linkExistingBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->callAction(
                TestAction::make(
                    'linkExistingBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $manager->refresh();

        $this->assertSame(
            $existing->id,
            $manager->user_id
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
                        SystemRole::BranchManager
                    )->id
                )
                ->count()
        );

        $this->assertDatabaseCount(
            'branch_manager_assignments',
            0
        );
    }

    public function test_existing_deactivated_branch_manager_account_prevents_duplicate_creation(): void
    {
        [
            $center,
            $manager,
            $person,
        ] = $this->managerWithoutAccount();

        $this->managerAccount(
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
            ListBranchManagers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingBranchManagerAccount'
                )->table(
                    $manager
                )
            );
    }

    public function test_deactivated_branch_manager_exposes_no_create_or_link_account_action(): void
    {
        [
            $center,
            $manager,
        ] = $this->managerWithoutAccount(
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
            ListBranchManagers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingBranchManagerAccount'
                )->table(
                    $manager
                )
            );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:BranchManager,
     *     2:Person
     * }
     */
    private function managerWithoutAccount(
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
                'manager@example.test',
            ]);

        $manager =
            BranchManager::factory()
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
            $manager,
            $person,
        ];
    }

    private function managerAccount(
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
                    SystemRole::BranchManager
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