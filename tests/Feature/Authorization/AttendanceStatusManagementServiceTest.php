<?php

namespace Tests\Feature\Authorization;

use App\Models\AttendanceStatus;
use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusManagementService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AttendanceStatusManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_attendance_status_for_current_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $status =
            $this->service()
            ->create(
                $owner,
                [
                    /*
                     * Request-supplied ownership and lifecycle
                     * values must be ignored.
                     */
                    'center_id' =>
                    999999,

                    'name' =>
                    '  Present  ',

                    'code' =>
                    '  PRESENT  ',

                    'contribution_value' =>
                    '100',

                    'is_active' =>
                    false,
                ]
            );

        $this->assertSame(
            $center->id,
            $status->center_id
        );

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

        $this->assertTrue(
            $status->is_active
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.created',
                $status
            )
        );
    }

    public function test_account_and_tenant_center_must_match(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->establishCenterContext(
            $centerB
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'Authenticated account and tenant context do not match.'
        );

        $this->service()
            ->create(
                $ownerA,
                $this->validAttributes()
            );
    }

    public function test_non_center_owner_cannot_create_attendance_status(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                $manager,
                $this->validAttributes()
            );
    }

    public function test_creation_rejects_invalid_domain_values(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $invalidCases = [
            'missing name' => [
                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ],

            'blank name' => [
                'name' =>
                '   ',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ],

            'long name' => [
                'name' =>
                str_repeat(
                    'A',
                    101
                ),

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ],

            'missing code' => [
                'name' =>
                'Present',

                'contribution_value' =>
                100,
            ],

            'blank code' => [
                'name' =>
                'Present',

                'code' =>
                '   ',

                'contribution_value' =>
                100,
            ],

            'long code' => [
                'name' =>
                'Present',

                'code' =>
                str_repeat(
                    'A',
                    51
                ),

                'contribution_value' =>
                100,
            ],

            'missing contribution' => [
                'name' =>
                'Present',

                'code' =>
                'PRESENT',
            ],

            'negative contribution' => [
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                -0.01,
            ],

            'contribution above 100' => [
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100.01,
            ],

            'non numeric contribution' => [
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                'invalid',
            ],
        ];

        foreach (
            $invalidCases
            as $label => $attributes
        ) {
            try {
                $this->service()
                    ->create(
                        $owner,
                        $attributes
                    );

                $this->fail(
                    "Expected {$label} validation to fail."
                );
            } catch (DomainException) {
                $this->assertDatabaseCount(
                    'attendance_statuses',
                    0
                );
            }
        }
    }

    public function test_status_code_must_be_unique_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->create(
                $owner,
                [
                    'name' =>
                    'Present',

                    'code' =>
                    'PRESENT',

                    'contribution_value' =>
                    100,
                ]
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                [
                    'name' =>
                    'Present Again',

                    'code' =>
                    'PRESENT',

                    'contribution_value' =>
                    100,
                ]
            );
    }

    public function test_same_status_code_is_allowed_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownerB =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerB
            );

        $this->establishCenterContext(
            $centerA
        );

        $statusA =
            $this->service()
            ->create(
                $ownerA,
                $this->validAttributes()
            );

        $this->establishCenterContext(
            $centerB
        );

        $statusB =
            $this->service()
            ->create(
                $ownerB,
                $this->validAttributes()
            );

        $this->assertSame(
            'PRESENT',
            $statusA->code
        );

        $this->assertSame(
            'PRESENT',
            $statusB->code
        );

        $this->assertNotSame(
            $statusA->center_id,
            $statusB->center_id
        );
    }

    public function test_center_owner_can_update_status_without_moving_center_or_changing_lifecycle(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter($centerA)
            ->active()
            ->create([
                'name' =>
                'Old Name',

                'code' =>
                'OLD',

                'contribution_value' =>
                50,
            ]);

        $this->establishCenterContext(
            $centerA
        );

        $updated =
            $this->service()
            ->update(
                $owner,
                $status,
                [
                    'center_id' =>
                    $centerB->id,

                    'name' =>
                    '  Late  ',

                    'code' =>
                    '  LATE  ',

                    'contribution_value' =>
                    75,

                    'is_active' =>
                    false,
                ]
            );

        $this->assertSame(
            $centerA->id,
            $updated->center_id
        );

        $this->assertSame(
            'Late',
            $updated->name
        );

        $this->assertSame(
            'LATE',
            $updated->code
        );

        $this->assertSame(
            '75.00',
            $updated
                ->contribution_value
        );

        $this->assertTrue(
            $updated->is_active
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.updated',
                $updated
            )
        );
    }

    public function test_no_change_update_creates_no_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->update(
                $owner,
                $status,
                [
                    'name' =>
                    'Present',

                    'code' =>
                    'PRESENT',

                    'contribution_value' =>
                    '100.00',
                ]
            );

        $this->assertSame(
            0,
            $this->auditCount(
                'attendance_status.updated',
                $status
            )
        );
    }

    public function test_center_owner_cannot_update_status_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $statusB =
            AttendanceStatus::factory()
            ->forCenter($centerB)
            ->create();

        $this->establishCenterContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $ownerA,
                $statusB,
                [
                    'name' =>
                    'Changed',
                ]
            );
    }

    public function test_activate_and_deactivate_are_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $deactivated =
            $this->service()
            ->deactivate(
                $owner,
                $status
            );

        $this->assertFalse(
            $deactivated->is_active
        );

        $this->service()
            ->deactivate(
                $owner,
                $deactivated
            );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.deactivated',
                $deactivated
            )
        );

        $activated =
            $this->service()
            ->activate(
                $owner,
                $deactivated
            );

        $this->assertTrue(
            $activated->is_active
        );

        $this->service()
            ->activate(
                $owner,
                $activated
            );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance_status.activated',
                $activated
            )
        );
    }

    public function test_creation_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->create(
                    $owner,
                    $this->validAttributes()
                );

            $this->fail(
                'Expected simulated audit failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'attendance_statuses',
            [
                'center_id' =>
                $center->id,

                'code' =>
                'PRESENT',
            ]
        );
    }

    public function test_update_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->update(
                    $owner,
                    $status,
                    [
                        'name' =>
                        'Changed',

                        'contribution_value' =>
                        50,
                    ]
                );

            $this->fail(
                'Expected simulated audit failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $status->refresh();

        $this->assertSame(
            'Present',
            $status->name
        );

        $this->assertSame(
            '100.00',
            $status
                ->contribution_value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validAttributes(): array
    {
        return [
            'name' =>
            'Present',

            'code' =>
            'PRESENT',

            'contribution_value' =>
            '100.00',
        ];
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function bindFailingAudit(): void
    {
        $failingAudit =
            \Mockery::mock(
                AuditRecorder::class
            );

        $failingAudit
            ->shouldReceive('record')
            ->once()
            ->andThrow(
                new LogicException(
                    'Simulated audit failure.'
                )
            );

        $this->app->instance(
            AuditRecorder::class,
            $failingAudit
        );
    }

    private function service(): AttendanceStatusManagementService
    {
        return app(
            AttendanceStatusManagementService::class
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

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        $center ??=
            Center::factory()
            ->active()
            ->create();

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
                    $role
                )->id,

                'status' =>
                AccountStatus::Active,
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
