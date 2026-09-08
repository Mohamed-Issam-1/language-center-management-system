<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BranchManagers\Pages\ListBranchManagers;
use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\BranchManagerAssignment;
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
use Livewire\Livewire;
use Tests\TestCase;

class BranchManagerAdministrativeActionTest
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

    public function test_center_owner_can_update_branch_manager_identity(): void
    {
        [
            $center,
            $manager,
            $person,
            $account,
        ] = $this->manager();

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
            ListBranchManagers::class
        )
            ->callAction(
                TestAction::make(
                    'editBranchManagerIdentity'
                )->table(
                    $manager
                ),
                [
                    'full_name' =>
                    'Updated Manager',

                    'date_of_birth' =>
                    '1995-05-10',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'UPDATED.MANAGER@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599123456',
                ]
            )
            ->assertHasNoActionErrors();

        $person->refresh();
        $account->refresh();

        $this->assertSame(
            'Updated Manager',
            $person->full_name
        );

        $this->assertSame(
            'updated.manager@example.test',
            $person->email
        );

        $this->assertSame(
            'recovery@example.test',
            $account->recovery_email
        );
    }

    public function test_deactivating_branch_manager_ends_active_assignment(): void
    {
        [
            $center,
            $manager,
            $person,
            $account,
        ] = $this->manager();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $assignment =
            BranchManagerAssignment::query()
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
            ListBranchManagers::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateBranchManager'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $manager->refresh();
        $assignment->refresh();
        $account->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $manager->status
        );

        $this->assertNotNull(
            $manager->deactivated_at
        );

        $this->assertNull(
            $assignment->active_marker
        );

        $this->assertNotNull(
            $assignment->ended_at
        );

        /*
         * Account lifecycle remains separate.
         */
        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );
    }

    public function test_reactivation_does_not_recreate_branch_assignment(): void
    {
        [
            $center,
            $manager,
        ] = $this->manager(
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
            ->callAction(
                TestAction::make(
                    'activateBranchManager'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $manager->refresh();

        $this->assertSame(
            StaffStatus::Active,
            $manager->status
        );

        $this->assertNull(
            $manager->deactivated_at
        );

        $this->assertDatabaseCount(
            'branch_manager_assignments',
            0
        );
    }

    public function test_branch_manager_cannot_be_reactivated_while_linked_account_is_deactivated(): void
    {
        [
            $center,
            $manager,
        ] = $this->manager(
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
            ListBranchManagers::class
        )
            ->callAction(
                TestAction::make(
                    'activateBranchManager'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $manager->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $manager->status
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:BranchManager,
     *     2:Person,
     *     3:User
     * }
     */
    private function manager(
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
                'Original Manager',

                'date_of_birth' =>
                '1990-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'manager@example.test',

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
                    SystemRole::BranchManager
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

        $manager =
            BranchManager::factory()
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
            $manager,
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