<?php

namespace Tests\Feature\Authorization;

use App\Mail\RegistrationCredentialsMail;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\PlatformCenterOwnerProvisioningService;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformCenterOwnerProvisioningServiceTest
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

    public function test_platform_owner_can_provision_complete_center_owner_identity_and_account(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '42',
            ]);

        $actor =
            $this->platformOwner();

        $this->establishPlatformScope();

        $result =
            $this->service()
            ->provision(
                $actor,
                $center,
                $this->identity(
                    nationalIdNumber: '900100001',
                    email: 'Owner.One@Example.Test'
                )
            );

        $account =
            $result['account'];

        $person =
            $result['person'];

        $this->assertSame(
            SystemRole::CenterOwner,
            $account->systemRole()
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
            AccountStatus::Active,
            $account->status
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNull(
            $account
                ->temporary_password_used_at
        );

        $this->assertSame(
            'owner.one@example.test',
            $account->recovery_email
        );

        $this->assertSame(
            8,
            strlen(
                $account
                    ->account_login_identifier
            )
        );

        $this->assertStringStartsWith(
            '42',
            $account
                ->account_login_identifier
        );

        $this->assertTrue(
            Hash::check(
                $result['temporary_password'],
                $account->password
            )
        );

        $this->assertSame(
            'Demo Center Owner',
            $person->full_name
        );

        $this->assertSame(
            '900100001',
            $person->national_id_number
        );

        $this->assertSame(
            '1990-05-10',
            $person
                ->date_of_birth
                ?->format('Y-m-d')
        );

        $this->assertSame(
            'Gaza',
            $person->city_of_residence
        );

        $this->assertSame(
            'owner.one@example.test',
            $person->email
        );

        $this->assertSame(
            '+970599100001',
            $person->phone_number
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.center_owner_identity_created',

                'subject_type' =>
                'people',

                'subject_id' =>
                $person->id,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'user_account.created',

                'subject_type' =>
                'users',

                'subject_id' =>
                $account->id,
            ]
        );

        /*
         * Credential delivery is intentionally performed
         * after provisioning has committed.
         */
        app(
            RegistrationCredentialsDeliveryService::class
        )->deliverAccountCredentials(
            $account,
            $result['temporary_password']
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ) use (
                $account,
                $result
            ): bool {
                return $mail->hasTo(
                    'owner.one@example.test'
                )
                    && $mail
                    ->accountLoginIdentifier
                    ===
                    $account
                    ->account_login_identifier

                    && $mail
                    ->temporaryPassword
                    ===
                    $result['temporary_password'];
            }
        );

        Mail::assertNotQueued(
            RegistrationCredentialsMail::class
        );
    }

    public function test_provisioning_reuses_and_synchronizes_existing_person(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '43',
            ]);

        $actor =
            $this->platformOwner();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900100002',

                'full_name' =>
                'Old Name',

                'date_of_birth' =>
                '1980-01-01',

                'city_of_residence' =>
                'Old City',

                'email' =>
                'old@example.test',

                'phone_number' =>
                '+970500000000',
            ]);

        $this->establishPlatformScope();

        $result =
            $this->service()
            ->provision(
                $actor,
                $center,
                $this->identity(
                    nationalIdNumber: '900100002',
                    email: 'updated@example.test'
                )
            );

        $person->refresh();

        $this->assertSame(
            $person->id,
            $result['person']->id
        );

        $this->assertSame(
            $person->id,
            $result['account']->person_id
        );

        $this->assertSame(
            'Demo Center Owner',
            $person->full_name
        );

        $this->assertSame(
            'updated@example.test',
            $person->email
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.center_owner_identity_updated',

                'subject_type' =>
                'people',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_duplicate_center_owner_account_rolls_back_person_update_and_identifier_sequence(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '44',
            ]);

        $actor =
            $this->platformOwner();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900100003',

                'full_name' =>
                'Original Owner',

                'email' =>
                'original@example.test',
            ]);

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

                'account_login_identifier' =>
                '44999999',
            ]);

        $this->establishPlatformScope();

        try {
            $this->service()
                ->provision(
                    $actor,
                    $center,
                    $this->identity(
                        nationalIdNumber: '900100003',
                        email: 'changed@example.test'
                    )
                );

            $this->fail(
                'Expected duplicate Center Owner account provisioning to fail.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $person->refresh();

        $this->assertSame(
            'Original Owner',
            $person->full_name
        );

        $this->assertSame(
            'original@example.test',
            $person->email
        );

        /*
         * Generator initialization/allocation happened inside
         * the failed outer transaction, so it must not survive.
         */
        $this->assertDatabaseMissing(
            'account_identifier_sequences',
            [
                'center_identifier_code' =>
                '44',

                'role_code' =>
                SystemRole::CenterOwner
                    ->value,
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'person.center_owner_identity_updated',

                'subject_type' =>
                'people',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_non_platform_owner_cannot_provision_center_owner(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '45',
            ]);

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $actor =
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
            ]);

        $this->establishPlatformScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->provision(
                $actor,
                $center,
                $this->identity(
                    nationalIdNumber: '900100004'
                )
            );
    }

    public function test_platform_tenant_scope_is_required(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '46',
            ]);

        $actor =
            $this->platformOwner();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->provision(
                $actor,
                $center,
                $this->identity(
                    nationalIdNumber: '900100005'
                )
            );
    }

    public function test_in_memory_role_tampering_cannot_grant_provisioning_access(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '47',
            ]);

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $actor =
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
            ]);

        /*
         * Tamper only with the in-memory instance.
         * Persisted state remains Center Owner.
         */
        $actor->forceFill([
            'center_id' => null,
            'person_id' => null,
            'role_id' =>
            $this->role(
                SystemRole::PlatformOwner
            )->id,
        ]);

        $actor->setRelation(
            'role',
            $this->role(
                SystemRole::PlatformOwner
            )
        );

        $this->establishPlatformScope();

        try {
            $this->service()
                ->provision(
                    $actor,
                    $center,
                    $this->identity(
                        nationalIdNumber: '900100006'
                    )
                );

            $this->fail(
                'Expected persisted Center Owner role to prevent provisioning.'
            );
        } catch (
            AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseMissing(
            'people',
            [
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900100006',
            ]
        );
    }

    private function service(): PlatformCenterOwnerProvisioningService
    {
        return app(
            PlatformCenterOwnerProvisioningService::class
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

    /**
     * @return array<string, string>
     */
    private function identity(
        string $nationalIdNumber,
        string $email =
        'center.owner@example.test'
    ): array {
        return [
            'national_id_number' =>
            $nationalIdNumber,

            'full_name' =>
            'Demo Center Owner',

            'date_of_birth' =>
            '1990-05-10',

            'city_of_residence' =>
            'Gaza',

            'email' =>
            $email,

            'phone_number' =>
            '+970599100001',
        ];
    }
}