<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BranchManagers\Pages\ListBranchManagers;
use App\Mail\RegistrationCredentialsMail;
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
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class BranchManagerAccountLifecycleActionTest
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
            $manager,
        ] = $this->manager();

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
                    'deactivateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reissueBranchManagerCredentials'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'activateBranchManagerAccount'
                )->table(
                    $manager
                )
            );
    }

    public function test_account_deactivation_preserves_operational_record_and_assignment(): void
    {
        [
            $center,
            $manager,
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
                    'deactivateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();
        $manager->refresh();
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
            $manager->status
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
            $manager,
        ] = $this->manager(
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
            ListBranchManagers::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'activateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueBranchManagerCredentials'
                )->table(
                    $manager
                )
            );
    }

    public function test_center_owner_can_activate_branch_manager_account(): void
    {
        [
            $center,
            $manager,
            $account,
        ] = $this->manager(
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
            ListBranchManagers::class
        )
            ->callAction(
                TestAction::make(
                    'activateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();
        $manager->refresh();

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );

        $this->assertSame(
            StaffStatus::Active,
            $manager->status
        );
    }

    public function test_deactivated_operational_record_does_not_expose_reissue_credentials(): void
    {
        [
            $center,
            $manager,
        ] = $this->manager(
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
            ListBranchManagers::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'deactivateBranchManagerAccount'
                )->table(
                    $manager
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueBranchManagerCredentials'
                )->table(
                    $manager
                )
            );
    }

    public function test_reissue_credentials_changes_password_and_sends_email(): void
    {
        Mail::fake();

        [
            $center,
            $manager,
            $account,
        ] = $this->manager();

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
            ListBranchManagers::class
        )
            ->callAction(
                TestAction::make(
                    'reissueBranchManagerCredentials'
                )->table(
                    $manager
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
     *     1:BranchManager,
     *     2:User
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

                'account_login_identifier' =>
                '41130001',

                'recovery_email' =>
                'manager@example.test',

                'status' =>
                $accountStatus,

                'password' =>
                'OldManagerPassword123!',

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