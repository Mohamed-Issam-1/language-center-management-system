<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AttendanceStatuses\Pages\ListAttendanceStatuses;
use App\Filament\Resources\AttendanceStatuses\AttendanceStatusResource;
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

class AttendanceStatusUpdateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_update_attendance_status_through_filament_action(): void
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
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

        $originalCenterId =
            $status->center_id;

        $originalActiveState =
            $status->is_active;

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $action =
            TestAction::make(
                'updateAttendanceStatus'
            )->table(
                $status
            );

        Livewire::test(
            ListAttendanceStatuses::class
        )
            ->assertActionVisible(
                $action
            )
            ->callAction(
                $action,
                [
                    'name' =>
                    '  Late  ',

                    'code' =>
                    '  LATE  ',

                    'contribution_value' =>
                    '75.50',
                ]
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
            '75.50',
            $status
                ->contribution_value
        );

        $this->assertSame(
            $originalCenterId,
            $status->center_id
        );

        $this->assertSame(
            $originalActiveState,
            $status->is_active
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'attendance_status.updated'
                )
                ->where(
                    'subject_type',
                    $status->getTable()
                )
                ->where(
                    'subject_id',
                    $status->id
                )
                ->count()
        );
    }

    public function test_duplicate_code_does_not_update_attendance_status(): void
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

        AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
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
                    'updateAttendanceStatus'
                )->table(
                    $status
                ),
                [
                    'name' =>
                    'Changed',

                    'code' =>
                    '  PRESENT  ',

                    'contribution_value' =>
                    50,
                ]
            );

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
    }

    public function test_update_action_rejects_contribution_above_one_hundred(): void
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
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
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
                    'updateAttendanceStatus'
                )->table(
                    $status
                ),
                [
                    'name' =>
                    'Changed',

                    'code' =>
                    'CHANGED',

                    'contribution_value' =>
                    100.01,
                ]
            );

        $status->refresh();

        $this->assertSame(
            'Present',
            $status->name
        );

        $this->assertSame(
            'PRESENT',
            $status->code
        );

        $this->assertSame(
            '100.00',
            $status
                ->contribution_value
        );
    }

    public function test_status_from_another_center_is_not_exposed_to_update_action(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $centerA
            )
            ->create();

        $otherStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $centerB
            )
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $visibleIds =
            AttendanceStatusResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $ownStatus->id,
            $visibleIds
        );

        $this->assertNotContains(
            $otherStatus->id,
            $visibleIds
        );

        $this->assertTrue(
            AttendanceStatusResource
                ::canView(
                    $ownStatus
                )
        );

        $this->assertFalse(
            AttendanceStatusResource
                ::canView(
                    $otherStatus
                )
        );

        $this->assertNull(
            AttendanceStatusResource
                ::resolveRecordRouteBinding(
                    $otherStatus
                        ->getKey()
                )
        );
    }

    public function test_update_action_does_not_change_lifecycle_state(): void
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
            ->create([
                'name' =>
                'Absent',

                'code' =>
                'ABSENT',

                'contribution_value' =>
                0,
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
                    'updateAttendanceStatus'
                )->table(
                    $status
                ),
                [
                    'name' =>
                    'Absent Updated',

                    'code' =>
                    'ABSENT_UPDATED',

                    'contribution_value' =>
                    10,
                ]
            )
            ->assertHasNoActionErrors();

        $status->refresh();

        $this->assertSame(
            'Absent Updated',
            $status->name
        );

        $this->assertFalse(
            $status->is_active
        );
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