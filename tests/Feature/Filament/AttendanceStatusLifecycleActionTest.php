<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AttendanceStatuses\Pages\ListAttendanceStatuses;
use App\Models\AttendanceStatus;
use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceStatusLifecycleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_deactivate_active_attendance_status(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $deactivateAction =
            TestAction::make(
                'deactivateAttendanceStatus'
            )->table(
                $status
            );

        $activateAction =
            TestAction::make(
                'activateAttendanceStatus'
            )->table(
                $status
            );

        Livewire::test(
            ListAttendanceStatuses::class
        )
            ->assertActionVisible(
                $deactivateAction
            )
            ->assertActionHidden(
                $activateAction
            )
            ->callAction(
                $deactivateAction
            )
            ->assertHasNoActionErrors();

        $status->refresh();

        $this->assertFalse(
            $status->is_active
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.deactivated',
                $status
            )
        );
    }

    public function test_center_owner_can_activate_inactive_attendance_status(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->inactive()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $activateAction =
            TestAction::make(
                'activateAttendanceStatus'
            )->table(
                $status
            );

        $deactivateAction =
            TestAction::make(
                'deactivateAttendanceStatus'
            )->table(
                $status
            );

        Livewire::test(
            ListAttendanceStatuses::class
        )
            ->assertActionVisible(
                $activateAction
            )
            ->assertActionHidden(
                $deactivateAction
            )
            ->callAction(
                $activateAction
            )
            ->assertHasNoActionErrors();

        $status->refresh();

        $this->assertTrue(
            $status->is_active
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.activated',
                $status
            )
        );
    }

    public function test_lifecycle_actions_do_not_modify_status_configuration(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                'Late',

                'code' =>
                'LATE',

                'contribution_value' =>
                75,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAttendanceStatuses::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateAttendanceStatus'
                )->table(
                    $status
                )
            )
            ->assertHasNoActionErrors();

        $status->refresh();

        $this->assertSame(
            'Late',
            $status->name
        );

        $this->assertSame(
            'LATE',
            $status->code
        );

        $this->assertSame(
            '75.00',
            $status
                ->contribution_value
        );

        $this->assertSame(
            $center->id,
            $status->center_id
        );

        $this->assertFalse(
            $status->is_active
        );
    }

    public function test_lifecycle_action_visibility_matches_current_state(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $activeStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create();

        $inactiveStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->inactive()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAttendanceStatuses::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'deactivateAttendanceStatus'
                )->table(
                    $activeStatus
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'activateAttendanceStatus'
                )->table(
                    $activeStatus
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'activateAttendanceStatus'
                )->table(
                    $inactiveStatus
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateAttendanceStatus'
                )->table(
                    $inactiveStatus
                )
            );
    }

    private function auditCount(
        string $actionType,
        AttendanceStatus $status
    ): int {
        return AuditRecord::query()
            ->where(
                'action_type',
                $actionType
            )
            ->where(
                'subject_type',
                $status->getTable()
            )
            ->where(
                'subject_id',
                $status->id
            )
            ->count();
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
                $this->role(
                    $role
                )->id,

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