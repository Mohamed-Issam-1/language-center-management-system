<?php

namespace Tests\Feature\Authorization;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\CenterPersonIdentityUpdateService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CenterPersonIdentityUpdateServiceTest
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

    public function test_center_owner_can_update_person_identity_inside_own_center(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '123456789',

                'personal_picture_path' =>
                'people/original.jpg',
            ]);

        $this->establishCenter(
            $center
        );

        $updated =
            $this->service()
            ->update(
                $actor,
                $person,
                [
                    'full_name' =>
                    'Updated Person',

                    'date_of_birth' =>
                    '2000-05-10',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'UPDATED@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599999999',
                ]
            );

        $this->assertSame(
            'Updated Person',
            $updated->full_name
        );

        $this->assertSame(
            '2000-05-10',
            $updated
                ->date_of_birth
                ->format('Y-m-d')
        );

        $this->assertSame(
            'Gaza',
            $updated
                ->city_of_residence
        );

        $this->assertSame(
            'updated@example.test',
            $updated->email
        );

        $this->assertSame(
            '+970599999999',
            $updated->phone_number
        );

        /*
         * Shared identity keys and picture state are
         * immutable through this workflow.
         */
        $this->assertSame(
            $center->id,
            $updated->center_id
        );

        $this->assertSame(
            '123456789',
            $updated
                ->national_id_number
        );

        $this->assertSame(
            'people/original.jpg',
            $updated
                ->personal_picture_path
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.identity_updated',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_no_op_identity_update_creates_no_audit_record(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                'Same Person',

                'date_of_birth' =>
                '2001-01-02',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'same@example.test',

                'phone_number' =>
                '+970590000000',
            ]);

        $this->establishCenter(
            $center
        );

        $this->service()
            ->update(
                $actor,
                $person,
                [
                    'full_name' =>
                    'Same Person',

                    'date_of_birth' =>
                    '2001-01-02',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'same@example.test',

                    'phone_number' =>
                    '+970590000000',
                ]
            );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_cannot_update_person_from_another_center(): void
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
            $this->centerOwner(
                $centerA
            );

        $person =
            Person::factory()
            ->for($centerB)
            ->create();

        $this->establishCenter(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $this->identityData()
            );
    }

    public function test_branch_manager_cannot_update_shared_person_identity(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->roleAccount(
                $center,
                SystemRole::BranchManager
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $this->identityData()
            );
    }

    public function test_deactivated_center_owner_cannot_update_person_identity(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $actor->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $this->identityData()
            );
    }

    public function test_identity_keys_cannot_be_changed_through_general_update(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                [
                    ...$this->identityData(),

                    'national_id_number' =>
                    '999999999',
                ]
            );
    }

    public function test_invalid_personal_email_is_rejected(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $data =
            $this->identityData();

        $data['email'] =
            'not-an-email';

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $data
            );
    }

    public function test_future_date_of_birth_is_rejected(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $data =
            $this->identityData();

        $data['date_of_birth'] =
            now()
            ->addDay()
            ->toDateString();

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $data
            );
    }

    public function test_tampered_in_memory_person_center_cannot_bypass_scope(): void
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
            $this->centerOwner(
                $centerA
            );

        $person =
            Person::factory()
            ->for($centerB)
            ->create();

        /*
         * Simulate untrusted caller mutation.
         */
        $person->center_id =
            $centerA->id;

        $this->establishCenter(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $actor,
                $person,
                $this->identityData()
            );
    }

    public function test_identity_update_rolls_back_when_audit_recording_fails(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                'Original Person',
            ]);

        $this->establishCenter(
            $center
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive(
                        'record'
                    )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->update(
                    $actor,
                    $person,
                    [
                        ...$this->identityData(),

                        'full_name' =>
                        'Should Roll Back',
                    ]
                );

            $this->fail(
                'Expected Audit failure was not thrown.'
            );
        } catch (
            RuntimeException $exception
        ) {
            $this->assertSame(
                'Audit failure.',
                $exception
                    ->getMessage()
            );
        }

        $person->refresh();

        $this->assertSame(
            'Original Person',
            $person->full_name
        );
    }

    /**
     * @return array<string, string>
     */
    private function identityData(): array
    {
        return [
            'full_name' =>
            'Managed Person',

            'date_of_birth' =>
            '2000-01-15',

            'city_of_residence' =>
            'Gaza',

            'email' =>
            'person@example.test',

            'phone_number' =>
            '+970599111111',
        ];
    }

    private function service(): CenterPersonIdentityUpdateService
    {
        return app(
            CenterPersonIdentityUpdateService::class
        );
    }

    private function establishCenter(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function centerOwner(
        Center $center
    ): User {
        return $this->roleAccount(
            $center,
            SystemRole::CenterOwner
        );
    }

    private function roleAccount(
        Center $center,
        SystemRole $role
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