<?php

namespace Tests\Feature\Audit;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_can_record_platform_scoped_event(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $subject = $this->roleModel(
            SystemRole::CenterOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        $record = app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'role.reviewed',
                subject: $subject,
                metadata: [
                    'source' => 'test',
                ]
            );

        $this->assertNull(
            $record->center_id
        );

        $this->assertNull(
            $record->branch_id
        );

        $this->assertSame(
            $actor->id,
            $record->actor_user_id
        );

        $this->assertSame(
            SystemRole::PlatformOwner->value,
            $record->actor_role
        );

        $this->assertSame(
            'roles',
            $record->subject_type
        );

        $this->assertSame(
            $subject->id,
            $record->subject_id
        );
    }

    public function test_platform_owner_can_record_center_event_without_becoming_center_scoped(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $center = Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishPlatformScope();

        $record = app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'center.reviewed',
                subject: $center
            );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertNull(
            $record->branch_id
        );

        $this->assertSame(
            SystemRole::PlatformOwner->value,
            $record->actor_role
        );
    }

    public function test_center_actor_records_scope_from_subject_not_input(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $center
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $record = app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'branch.updated',
                subject: $branch
            );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            $branch->id,
            $record->branch_id
        );

        $this->assertSame(
            SystemRole::CenterOwner->value,
            $record->actor_role
        );

        $this->assertSame(
            'branches',
            $record->subject_type
        );
    }

    public function test_center_actor_cannot_record_subject_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $centerA
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $this->expectException(
            AuthorizationException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'branch.updated',
                subject: $branchB
            );
    }

    public function test_center_actor_cannot_record_without_center_tenant_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $center
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        $this->expectException(
            AuthorizationException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'branch.updated',
                subject: $branch
            );
    }

    public function test_audit_recording_requires_established_tenant_context(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $subject = $this->roleModel(
            SystemRole::Student
        );

        $this->expectException(
            LogicException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'role.reviewed',
                subject: $subject
            );
    }

    public function test_action_type_must_use_stable_lowercase_dot_notation(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $subject = $this->roleModel(
            SystemRole::Student
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'Student Updated',
                subject: $subject
            );
    }

    public function test_subject_must_be_persisted_before_audit_recording(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $subject = new Role([
            'code' => 'not_persisted',
            'name' => 'Not Persisted',
        ]);

        app(TenantContext::class)
            ->establishPlatformScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'role.reviewed',
                subject: $subject
            );
    }

    public function test_sensitive_values_are_recursively_redacted(): void
    {
        $actor = $this->createActor(
            SystemRole::PlatformOwner
        );

        $subject = $this->roleModel(
            SystemRole::Student
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        $record = app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'user_account.security_reviewed',
                subject: $subject,
                beforeValues: [
                    'account_login_identifier' => 'student.one',
                    'password' => 'old-secret',
                    'password_hash' => 'stored-hash',
                    'password_changed_at' => '2026-08-18 10:00:00',
                    'nested' => [
                        'temporary_password' => 'temporary-secret',
                        'access-token' => 'token-value',
                        'recovery_email' => 'student@example.test',
                    ],
                ],
                afterValues: [
                    'password' => 'new-secret',
                    'remember_token' => 'remember-secret',
                ],
                metadata: [
                    'authorization' => 'Bearer secret-token',
                    'secret_key' => 'top-secret',
                    'request' => [
                        'client_secret' => 'secret',
                        'safe_value' => 'visible',
                    ],
                ]
            );

        $this->assertSame(
            '[REDACTED]',
            $record->before_values['password']
        );

        $this->assertSame(
            '2026-08-18 10:00:00',
            $record->before_values['password_changed_at']
        );

        $this->assertSame(
            '[REDACTED]',
            $record->before_values['nested']['temporary_password']
        );

        $this->assertSame(
            '[REDACTED]',
            $record->before_values['nested']['access-token']
        );

        $this->assertSame(
            'student@example.test',
            $record->before_values['nested']['recovery_email']
        );

        $this->assertSame(
            '[REDACTED]',
            $record->after_values['remember_token']
        );

        $this->assertSame(
            '[REDACTED]',
            $record->metadata['authorization']
        );

        $this->assertSame(
            '[REDACTED]',
            $record->metadata['request']['client_secret']
        );

        $this->assertSame(
            'visible',
            $record->metadata['request']['safe_value']
        );
    }

    public function test_audit_record_rolls_back_with_failed_business_transaction(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $center
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        try {
            DB::transaction(
                function () use (
                    $center,
                    $actor
                ): void {
                    $branch = Branch::factory()
                        ->for($center)
                        ->active()
                        ->create();

                    app(AuditRecorder::class)
                        ->record(
                            actor: $actor,
                            actionType: 'branch.created',
                            subject: $branch,
                            afterValues: [
                                'name' => $branch->name,
                            ]
                        );

                    throw new DomainException(
                        'Simulated business failure.'
                    );
                }
            );

            $this->fail(
                'The simulated business failure was not thrown.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Simulated business failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'branches',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_recorder_uses_persisted_actor_identity_instead_of_mutated_in_memory_state(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $center
        );

        /*
     * Simulate stale or accidentally mutated application state.
     * None of these changes are persisted.
     */
        $actor->center_id = $otherCenter->id;

        $actor->setRelation(
            'role',
            $this->roleModel(
                SystemRole::PlatformOwner
            )
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $record = app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'branch.updated',
                subject: $branch
            );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            SystemRole::CenterOwner->value,
            $record->actor_role
        );

        $this->assertSame(
            $actor->id,
            $record->actor_user_id
        );
    }

    public function test_recorder_rejects_cross_tenant_subject_even_if_in_memory_scope_is_tampered(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $actor = $this->createActor(
            SystemRole::CenterOwner,
            $centerA
        );

        $subject = User::factory()
            ->create([
                'center_id' => $centerB->id,
            ]);

        /*
     * Pretend locally that this foreign-Center record belongs
     * to Center A without persisting the change.
     */
        $subject->center_id = $centerA->id;

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $this->expectException(
            AuthorizationException::class
        );

        app(AuditRecorder::class)
            ->record(
                actor: $actor,
                actionType: 'user_account.updated',
                subject: $subject
            );
    }

    private function createActor(
        SystemRole $role,
        ?Center $center = null
    ): User {
        $roleModel = $this->roleModel(
            $role
        );

        return User::factory()
            ->create([
                'center_id' =>
                $center?->id,
                'role_id' =>
                $roleModel->id,
            ]);
    }

    private function roleModel(
        SystemRole $role
    ): Role {
        return Role::query()
            ->firstOrCreate(
                [
                    'code' => $role->value,
                ],
                [
                    'name' => $role->label(),
                ]
            );
    }
}
