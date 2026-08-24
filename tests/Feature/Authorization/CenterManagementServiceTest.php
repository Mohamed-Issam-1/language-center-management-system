<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Centers\CenterManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;
use DomainException;

class CenterManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_platform_owner_can_create_center_and_new_center_is_suspended(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->establishPlatformContext();

        $center = $this->service()
            ->create(
                $platformOwner,
                [
                    'code' => 'GZA-100',
                    'identifier_code' => '01',
                    'name' => 'Gaza Language Center',
                    'email' => 'center@example.test',
                    'phone' => '0599000000',
                    'address' => 'Gaza',
                    'timezone' => 'Asia/Gaza',
                    'operating_currency_code' => 'ILS',

                    /*
                     * Creation must not bypass the explicit
                     * Center lifecycle.
                     */
                    'status' => CenterStatus::Active,
                ]
            );

        $this->assertSame(
            'GZA-100',
            $center->code
        );

        $this->assertSame(
            '01',
            $center->identifier_code
        );

        $this->assertSame(
            'Gaza Language Center',
            $center->name
        );

        $this->assertSame(
            'ILS',
            $center->operating_currency_code
        );

        $this->assertSame(
            CenterStatus::Suspended,
            $center->status
        );

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,
                'status' =>
                CenterStatus::Suspended->value,
            ]
        );
    }

    public function test_general_center_update_cannot_bypass_status_lifecycle(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->suspended()
            ->create([
                'name' => 'Old Center Name',
                'timezone' => 'Asia/Gaza',
            ]);

        $this->establishPlatformContext();

        $updated = $this->service()
            ->update(
                $platformOwner,
                $center,
                [
                    'name' => 'Updated Center Name',
                    'timezone' => 'Europe/London',
                    'operating_currency_code' => 'USD',

                    /*
                     * Must be ignored by general update.
                     */
                    'status' => CenterStatus::Active,
                ]
            );

        $this->assertSame(
            'Updated Center Name',
            $updated->name
        );

        $this->assertSame(
            'Europe/London',
            $updated->timezone
        );

        $this->assertSame(
            'USD',
            $updated->operating_currency_code
        );

        $this->assertSame(
            CenterStatus::Suspended,
            $updated->status
        );
    }

    public function test_platform_owner_can_activate_and_suspend_center(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->suspended()
            ->create();

        $this->establishPlatformContext();

        $center = $this->service()
            ->activate(
                $platformOwner,
                $center
            );

        $this->assertSame(
            CenterStatus::Active,
            $center->status
        );

        $center = $this->service()
            ->suspend(
                $platformOwner,
                $center
            );

        $this->assertSame(
            CenterStatus::Suspended,
            $center->status
        );

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,
                'status' =>
                CenterStatus::Suspended->value,
            ]
        );
    }

    public function test_center_management_requires_established_platform_context(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                $platformOwner,
                [
                    'code' => 'NO-CONTEXT',
                    'name' => 'Blocked Center',
                    'timezone' => 'Asia/Gaza',
                ]
            );
    }

    public function test_center_scoped_context_cannot_be_used_for_center_management(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $platformOwner,
                $center,
                [
                    'name' => 'Blocked',
                ]
            );
    }

    public function test_center_owner_cannot_manage_centers_even_in_platform_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishPlatformContext();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $centerOwner,
                $center,
                [
                    'name' => 'Unauthorized',
                ]
            );
    }

    public function test_center_creation_creates_audit_record(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->establishPlatformContext();

        $center = $this->service()
            ->create(
                $platformOwner,
                [
                    'code' => 'AUD-CTR-1',
                    'identifier_code' => '02',
                    'name' => 'Audit Center',
                    'email' => 'audit@example.test',
                    'phone' => '123456',
                    'address' => 'Audit Address',
                    'timezone' => 'Asia/Gaza',
                    'operating_currency_code' => 'ILS',
                ]
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'center.created'
            )
            ->firstOrFail();

        $this->assertSame(
            $platformOwner->id,
            $record->actor_user_id
        );

        $this->assertSame(
            '02',
            $record
                ->after_values['identifier_code']
        );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertNull(
            $record->branch_id
        );

        $this->assertSame(
            'centers',
            $record->subject_type
        );

        $this->assertSame(
            $center->id,
            $record->subject_id
        );

        $this->assertNull(
            $record->before_values
        );

        $this->assertSame(
            'Audit Center',
            $record->after_values['name']
        );

        $this->assertSame(
            CenterStatus::Suspended->value,
            $record->after_values['status']
        );
    }

    public function test_center_update_records_persisted_before_and_after_values(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create([
                'name' => 'Original Center',
                'email' => 'old@example.test',
                'timezone' => 'Asia/Gaza',
            ]);

        $this->establishPlatformContext();

        $updated = $this->service()
            ->update(
                $platformOwner,
                $center,
                [
                    'name' => 'Updated Center',
                    'email' => 'new@example.test',
                ]
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'center.updated'
            )
            ->firstOrFail();

        $this->assertSame(
            $updated->id,
            $record->subject_id
        );

        $this->assertSame(
            'Original Center',
            $record->before_values['name']
        );

        $this->assertSame(
            'old@example.test',
            $record->before_values['email']
        );

        $this->assertSame(
            'Updated Center',
            $record->after_values['name']
        );

        $this->assertSame(
            'new@example.test',
            $record->after_values['email']
        );

        $this->assertSame(
            CenterStatus::Active->value,
            $record->after_values['status']
        );
    }

    public function test_center_lifecycle_changes_are_audited(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->suspended()
            ->create();

        $this->establishPlatformContext();

        $service = $this->service();

        $center = $service->activate(
            $platformOwner,
            $center
        );

        $center = $service->suspend(
            $platformOwner,
            $center
        );

        $activated = AuditRecord::query()
            ->where(
                'action_type',
                'center.activated'
            )
            ->firstOrFail();

        $suspended = AuditRecord::query()
            ->where(
                'action_type',
                'center.suspended'
            )
            ->firstOrFail();

        $this->assertSame(
            CenterStatus::Suspended->value,
            $activated->before_values['status']
        );

        $this->assertSame(
            CenterStatus::Active->value,
            $activated->after_values['status']
        );

        $this->assertSame(
            CenterStatus::Active->value,
            $suspended->before_values['status']
        );

        $this->assertSame(
            CenterStatus::Suspended->value,
            $suspended->after_values['status']
        );
    }

    public function test_no_op_center_update_does_not_create_audit_record(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create([
                'name' => 'Unchanged Center',
            ]);

        $this->establishPlatformContext();

        $this->service()
            ->update(
                $platformOwner,
                $center,
                [
                    'name' => 'Unchanged Center',
                ]
            );

        $this->assertSame(
            0,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'center.updated'
                )
                ->count()
        );
    }

    public function test_repeated_center_lifecycle_requests_do_not_duplicate_audit_history(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->suspended()
            ->create();

        $this->establishPlatformContext();

        $service = $this->service();

        $center = $service->activate(
            $platformOwner,
            $center
        );

        $center = $service->activate(
            $platformOwner,
            $center
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'center.activated'
                )
                ->count()
        );

        $center = $service->suspend(
            $platformOwner,
            $center
        );

        $service->suspend(
            $platformOwner,
            $center
        );

        $this->assertSame(
            1,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'center.suspended'
                )
                ->count()
        );
    }

    public function test_center_management_uses_persisted_state_instead_of_tampered_in_memory_state(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create([
                'name' => 'Persisted Center',
                'email' => 'persisted@example.test',
            ]);

        /*
         * Change only local Eloquent state.
         */
        $center->name = 'Tampered Local Name';
        $center->email = 'tampered@example.test';

        $this->establishPlatformContext();

        $this->service()
            ->update(
                $platformOwner,
                $center,
                [
                    'phone' => '999999',
                ]
            );

        $record = AuditRecord::query()
            ->where(
                'action_type',
                'center.updated'
            )
            ->firstOrFail();

        $this->assertSame(
            'Persisted Center',
            $record->before_values['name']
        );

        $this->assertSame(
            'persisted@example.test',
            $record->before_values['email']
        );

        $this->assertSame(
            'Persisted Center',
            $record->after_values['name']
        );

        $this->assertSame(
            '999999',
            $record->after_values['phone']
        );
    }

    public function test_suspending_center_preserves_center_user_accounts(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create();

        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishPlatformContext();

        $this->service()
            ->suspend(
                $platformOwner,
                $center
            );

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,
                'status' =>
                CenterStatus::Suspended->value,
            ]
        );

        $this->assertDatabaseHas(
            'users',
            [
                'id' => $centerOwner->id,
                'center_id' => $center->id,
                'status' =>
                AccountStatus::Active->value,
            ]
        );
    }

    public function test_center_creation_rolls_back_when_audit_recording_fails(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->establishPlatformContext();

        $failingAudit = \Mockery::mock(
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

        try {
            $this->service()
                ->create(
                    $platformOwner,
                    [
                        'code' => 'ROLLBACK-CTR',
                        'identifier_code' => '03',
                        'name' => 'Rollback Center',
                        'timezone' => 'Asia/Gaza',
                    ]
                );

            $this->fail(
                'Expected audit failure to abort center creation.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'centers',
            [
                'code' => 'ROLLBACK-CTR',
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_update_rolls_back_when_audit_recording_fails(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create([
                'name' => 'Original Center',
            ]);

        $this->establishPlatformContext();

        $failingAudit = \Mockery::mock(
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

        try {
            $this->service()
                ->update(
                    $platformOwner,
                    $center,
                    [
                        'name' => 'Should Roll Back',
                    ]
                );

            $this->fail(
                'Expected audit failure to abort center update.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,
                'name' => 'Original Center',
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_lifecycle_change_rolls_back_when_audit_recording_fails(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create();

        $this->establishPlatformContext();

        $failingAudit = \Mockery::mock(
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

        try {
            $this->service()
                ->suspend(
                    $platformOwner,
                    $center
                );

            $this->fail(
                'Expected audit failure to abort center lifecycle change.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,
                'status' =>
                CenterStatus::Active->value,
            ]
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_creation_requires_identifier_code(): void
    {
        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->establishPlatformContext();

        $this->expectException(
            DomainException::class
        );

        $this->service()->create(
            $platformOwner,
            [
                'code' => 'NO-ID-CODE',
                'name' =>
                'Missing Identifier Center',

                'timezone' =>
                'Asia/Gaza',
            ]
        );
    }

    public function test_center_creation_rejects_invalid_identifier_code(): void
    {
        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->establishPlatformContext();

        $this->expectException(
            DomainException::class
        );

        $this->service()->create(
            $platformOwner,
            [
                'code' => 'BAD-ID-CODE',

                'identifier_code' =>
                '00',

                'name' =>
                'Invalid Identifier Center',

                'timezone' =>
                'Asia/Gaza',
            ]
        );
    }

    public function test_center_identifier_code_cannot_be_changed_by_general_update(): void
    {
        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $center = Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '04',
            ]);

        $this->establishPlatformContext();

        $updated =
            $this->service()->update(
                $platformOwner,
                $center,
                [
                    'identifier_code' =>
                    '05',

                    'name' =>
                    'Updated Center Name',
                ]
            );

        $this->assertSame(
            '04',
            $updated->identifier_code
        );

        $this->assertSame(
            'Updated Center Name',
            $updated->name
        );

        $this->assertDatabaseHas(
            'centers',
            [
                'id' => $center->id,

                'identifier_code' =>
                '04',
            ]
        );
    }

    private function service(): CenterManagementService
    {
        return app(
            CenterManagementService::class
        );
    }

    private function establishPlatformContext(): void
    {
        app(TenantContext::class)
            ->establishPlatformScope();
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role($role)->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' =>
                $this->role($role)->id,
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
