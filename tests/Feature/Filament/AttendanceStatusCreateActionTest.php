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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceStatusCreateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_attendance_status_through_filament_action(): void
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
                'createAttendanceStatus'
            )
            ->callAction(
                'createAttendanceStatus',
                data: [
                    'name' =>
                    '  Late  ',

                    'code' =>
                    '  LATE  ',

                    'contribution_value' =>
                    '75.50',
                ]
            )
            ->assertHasNoActionErrors();

        $status =
            AttendanceStatus::query()
            ->withoutGlobalScopes()
            ->sole();

        $this->assertSame(
            $center->id,
            $status->center_id
        );

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

        $this->assertTrue(
            $status->is_active
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'attendance_status.created'
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

    public function test_create_attendance_status_rejects_contribution_above_one_hundred(): void
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
                'createAttendanceStatus',
                data: [
                    'name' =>
                    'Invalid',

                    'code' =>
                    'INVALID',

                    'contribution_value' =>
                    100.01,
                ]
            )
            ->assertHasActionErrors([
                'contribution_value',
            ]);

        $this->assertSame(
            0,
            AttendanceStatus::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_create_attendance_status_rejects_negative_contribution(): void
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
                'createAttendanceStatus',
                data: [
                    'name' =>
                    'Invalid',

                    'code' =>
                    'INVALID',

                    'contribution_value' =>
                    -0.01,
                ]
            )
            ->assertHasActionErrors([
                'contribution_value',
            ]);

        $this->assertSame(
            0,
            AttendanceStatus::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_duplicate_code_inside_same_center_does_not_create_second_status(): void
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
            ->active()
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
                'createAttendanceStatus',
                data: [
                    'name' =>
                    'Present Again',

                    /*
                     * Service trimming should make this
                     * conflict with the persisted code.
                     */
                    'code' =>
                    '  PRESENT  ',

                    'contribution_value' =>
                    100,
                ]
            );

        $this->assertSame(
            1,
            AttendanceStatus::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'code',
                    'PRESENT'
                )
                ->count()
        );

        $this->assertSame(
            1,
            AttendanceStatus::query()
                ->withoutGlobalScopes()
                ->count()
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