<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\PlatformCenterOwnerIdentityUpdateService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PlatformCenterOwnerIdentityUpdateServiceTest
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

    public function test_platform_owner_can_update_center_owner_identity_and_recovery_email_atomically(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->platformOwner();

        $account =
            $this->centerOwner(
                $center
            );

        $originalNationalId =
            $account
            ->person
            ->national_id_number;

        $originalCenterId =
            $account->center_id;

        $originalRoleId =
            $account->role_id;

        $originalLoginIdentifier =
            $account
            ->account_login_identifier;

        /*
         * users.email is a legacy account field.
         * It is intentionally not synchronized with Person.email.
         */
        $originalLegacyEmail =
            $account->email;

        $this->establishPlatformScope();

        $updated =
            $this->service()
            ->update(
                $actor,
                $account,
                [
                    'full_name' =>
                    'Updated Center Owner',

                    'date_of_birth' =>
                    '1992-07-15',

                    'city_of_residence' =>
                    'Khan Younis',

                    'email' =>
                    'Updated.Person@Example.Test',

                    'phone_number' =>
                    '+970599222333',

                    'recovery_email' =>
                    'Updated.Recovery@Example.Test',
                ]
            );

        $updated->refresh();
        $updated->loadMissing(
            'person'
        );

        $this->assertSame(
            'Updated Center Owner',
            $updated
                ->person
                ->full_name
        );

        $this->assertSame(
            '1992-07-15',
            $updated
                ->person
                ->date_of_birth
                ?->format('Y-m-d')
        );

        $this->assertSame(
            'Khan Younis',
            $updated
                ->person
                ->city_of_residence
        );

        $this->assertSame(
            'updated.person@example.test',
            $updated
                ->person
                ->email
        );

        $this->assertSame(
            '+970599222333',
            $updated
                ->person
                ->phone_number
        );

        $this->assertSame(
            'updated.recovery@example.test',
            $updated
                ->recovery_email
        );

        /*
         * Identity keys are immutable through this workflow.
         */
        $this->assertSame(
            $originalNationalId,
            $updated
                ->person
                ->national_id_number
        );

        $this->assertSame(
            $originalCenterId,
            $updated->center_id
        );

        $this->assertSame(
            $originalRoleId,
            $updated->role_id
        );

        $this->assertSame(
            $originalLoginIdentifier,
            $updated
                ->account_login_identifier
        );

        $this->assertSame(
            $originalLegacyEmail,
            $updated->email
        );

        $personAudit =
            AuditRecord::query()
            ->where(
                'action_type',
                'person.center_owner_identity_updated'
            )
            ->where(
                'subject_type',
                'people'
            )
            ->where(
                'subject_id',
                $updated->person_id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            'Updated Center Owner',
            $personAudit
                ->after_values['full_name']
        );

        $accountAudit =
            AuditRecord::query()
            ->where(
                'action_type',
                'user_account.updated'
            )
            ->where(
                'subject_type',
                'users'
            )
            ->where(
                'subject_id',
                $updated->id
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            'updated.recovery@example.test',
            $accountAudit
                ->after_values['recovery_email']
        );
    }

    public function test_no_op_update_does_not_create_duplicate_audit_history(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->platformOwner();

        $account =
            $this->centerOwner(
                $center
            );

        $this->establishPlatformScope();

        $beforeCount =
            AuditRecord::query()
            ->count();

        $this->service()
            ->update(
                $actor,
                $account,
                [
                    'full_name' =>
                    $account
                        ->person
                        ->full_name,

                    'date_of_birth' =>
                    $account
                        ->person
                        ->date_of_birth
                        ?->format(
                            'Y-m-d'
                        ),

                    'city_of_residence' =>
                    $account
                        ->person
                        ->city_of_residence,

                    'email' =>
                    $account
                        ->person
                        ->email,

                    'phone_number' =>
                    $account
                        ->person
                        ->phone_number,

                    'recovery_email' =>
                    $account
                        ->recovery_email,
                ]
            );

        $this->assertSame(
            $beforeCount,
            AuditRecord::query()
                ->count()
        );
    }

    public function test_non_platform_owner_cannot_update_center_owner_identity(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $target =
            $this->centerOwner(
                $center
            );

        $actor =
            $this->centerOwner(
                $center
            );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $actor,
                $target,
                $this->changedIdentity()
            );
    }

    public function test_persisted_target_identity_is_used_instead_of_tampered_in_memory_person_and_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->platformOwner();

        $target =
            $this->centerOwner(
                $centerA
            );

        $originalPerson =
            $target->person;

        $otherPerson =
            Person::factory()
            ->for($centerB)
            ->create([
                'full_name' =>
                'Other Person',
            ]);

        /*
         * Tamper only with the supplied in-memory model.
         */
        $target->center_id =
            $centerB->id;

        $target->person_id =
            $otherPerson->id;

        $target->setRelation(
            'person',
            $otherPerson
        );

        $this->establishPlatformScope();

        $updated =
            $this->service()
            ->update(
                $actor,
                $target,
                $this->changedIdentity()
            );

        $originalPerson->refresh();
        $otherPerson->refresh();

        $this->assertSame(
            $originalPerson->id,
            $updated->person_id
        );

        $this->assertSame(
            'Changed Owner',
            $originalPerson
                ->full_name
        );

        $this->assertSame(
            'Other Person',
            $otherPerson
                ->full_name
        );
    }

    public function test_identity_update_rejects_immutable_or_unsupported_fields(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->platformOwner();

        $target =
            $this->centerOwner(
                $center
            );

        $this->establishPlatformScope();

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $actor,
                $target,
                array_merge(
                    $this->changedIdentity(),
                    [
                        'national_id_number' =>
                        '999999999',
                    ]
                )
            );
    }

    public function test_person_failure_rolls_back_recovery_email_update_from_nested_account_service(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->platformOwner();

        $account =
            $this->centerOwner(
                $center
            );

        $originalPerson =
            $account->person;

        $originalRecoveryEmail =
            $account
            ->recovery_email;

        $originalName =
            $originalPerson
            ->full_name;

        $recordCalls = 0;

        /*
         * First Audit call:
         * user_account.updated
         *
         * Second Audit call:
         * person.center_owner_identity_updated
         *
         * Fail on the second call to prove the outer transaction
         * rolls the earlier User Account mutation back too.
         */
        $this->mock(
            AuditRecorder::class,
            function (
                MockInterface $mock
            ) use (
                &$recordCalls
            ): void {
                $mock->shouldReceive(
                    'record'
                )
                    ->twice()
                    ->andReturnUsing(
                        function () use (
                            &$recordCalls
                        ): AuditRecord {
                            $recordCalls++;

                            if (
                                $recordCalls === 2
                            ) {
                                throw new DomainException(
                                    'Simulated Person audit failure.'
                                );
                            }

                            return new AuditRecord();
                        }
                    );
            }
        );

        $this->establishPlatformScope();

        try {
            $this->service()
                ->update(
                    $actor,
                    $account,
                    [
                        'full_name' =>
                        'Must Roll Back',

                        'date_of_birth' =>
                        '1991-02-03',

                        'city_of_residence' =>
                        'Gaza',

                        'email' =>
                        'rollback.person@example.test',

                        'phone_number' =>
                        '+970599444555',

                        'recovery_email' =>
                        'rollback.recovery@example.test',
                    ]
                );

            $this->fail(
                'Expected the simulated Audit failure.'
            );
        } catch (
            DomainException $exception
        ) {
            $this->assertSame(
                'Simulated Person audit failure.',
                $exception->getMessage()
            );
        }

        $account->refresh();
        $originalPerson->refresh();

        $this->assertSame(
            $originalRecoveryEmail,
            $account
                ->recovery_email
        );

        $this->assertSame(
            $originalName,
            $originalPerson
                ->full_name
        );
    }

    private function service(): PlatformCenterOwnerIdentityUpdateService
    {
        return app(
            PlatformCenterOwnerIdentityUpdateService::class
        );
    }

    private function establishPlatformScope(): void
    {
        app(TenantContext::class)
            ->establishPlatformScope();
    }

    private function platformOwner(): User
    {
        return User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

                'status' =>
                AccountStatus::Active,

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
            ->create([
                'national_id_number' =>
                fake()
                    ->unique()
                    ->numerify(
                        '9########'
                    ),

                'full_name' =>
                'Original Center Owner',

                'date_of_birth' =>
                '1990-05-10',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                fake()
                    ->unique()
                    ->safeEmail(),

                'phone_number' =>
                '+970599100001',
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
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'recovery_email' =>
                fake()
                    ->unique()
                    ->safeEmail(),

                'must_change_password' =>
                false,
            ]);

        $account->load([
            'person',
            'role',
            'center',
        ]);

        return $account;
    }

    /**
     * @return array<string, string>
     */
    private function changedIdentity(): array
    {
        return [
            'full_name' =>
            'Changed Owner',

            'date_of_birth' =>
            '1993-03-12',

            'city_of_residence' =>
            'Rafah',

            'email' =>
            'changed.person@example.test',

            'phone_number' =>
            '+970599777888',

            'recovery_email' =>
            'changed.recovery@example.test',
        ];
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