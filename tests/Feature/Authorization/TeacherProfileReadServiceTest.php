<?php

namespace Tests\Feature\Authorization;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Teachers\TeacherProfileReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherProfileReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_profile_returns_canonical_person_identity_and_safe_account_metadata(): void
    {
        $context =
            $this->teacherContext(
                'Canonical Teacher',
                [
                    'national_id_number' =>
                    '987654321',

                    'date_of_birth' =>
                    '1995-03-20',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'teacher.personal@example.test',

                    'phone_number' =>
                    '+970599654321',

                    'personal_picture_path' =>
                    'people/private/teacher.jpg',
                ],
                [
                    /*
                     * Deliberately different from Person name.
                     *
                     * Canonical Teacher identity must come from
                     * Person rather than legacy User.name.
                     */
                    'name' =>
                    'Legacy Teacher User Name',

                    'account_login_identifier' =>
                    '87654321',

                    'recovery_email' =>
                    'teacher.recovery@example.test',

                    'email_verified_at' =>
                    '2026-09-01 09:00:00',

                    'must_change_password' =>
                    false,

                    'password_changed_at' =>
                    '2026-09-02 10:00:00',

                    'last_login_at' =>
                    '2026-09-08 13:00:00',
                ]
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->profile(
                $context['user']
            );

        /*
         * Teacher operational identity.
         */
        $this->assertSame(
            $context['teacher']->id,
            $result['teacher']['id']
        );

        $this->assertSame(
            $context['person']->id,
            $result['teacher']['person_id']
        );

        $this->assertSame(
            $context['user']->id,
            $result['teacher']['user_id']
        );

        $this->assertSame(
            '87654321',
            $result['teacher']['account_login_identifier']
        );

        /*
         * Canonical name comes from Person.
         */
        $this->assertSame(
            'Canonical Teacher',
            $result['teacher']['name']
        );

        $this->assertNotSame(
            'Legacy Teacher User Name',
            $result['teacher']['name']
        );

        $this->assertSame(
            StaffStatus::Active->value,
            $result['teacher']['status']
        );

        $this->assertSame(
            $context['center']->id,
            $result['teacher']['center']['id']
        );

        $this->assertSame(
            $context['center']->code,
            $result['teacher']['center']['code']
        );

        $this->assertSame(
            $context['center']->name,
            $result['teacher']['center']['name']
        );

        /*
         * Do not invent Teacher domain fields that do not exist.
         */
        $this->assertArrayNotHasKey(
            'branch',
            $result['teacher']
        );

        $this->assertArrayNotHasKey(
            'teacher_number',
            $result['teacher']
        );

        $this->assertArrayNotHasKey(
            'employee_number',
            $result['teacher']
        );

        $this->assertArrayNotHasKey(
            'specialization',
            $result['teacher']
        );

        $this->assertArrayNotHasKey(
            'qualification',
            $result['teacher']
        );

        /*
         * Canonical Person identity.
         */
        $this->assertSame(
            $context['person']->id,
            $result['identity']['person_id']
        );

        $this->assertSame(
            '987654321',
            $result['identity']['national_id_number']
        );

        $this->assertSame(
            'Canonical Teacher',
            $result['identity']['full_name']
        );

        $this->assertSame(
            '1995-03-20',
            $result['identity']['date_of_birth']
        );

        $this->assertSame(
            'Gaza',
            $result['identity']['city_of_residence']
        );

        $this->assertSame(
            'teacher.personal@example.test',
            $result['identity']['email']
        );

        $this->assertSame(
            '+970599654321',
            $result['identity']['phone_number']
        );

        $this->assertTrue(
            $result['identity']['personal_picture_available']
        );

        /*
         * Safe User Account metadata.
         */
        $this->assertSame(
            $context['user']->id,
            $result['account']['user_id']
        );

        $this->assertSame(
            '87654321',
            $result['account']['account_login_identifier']
        );

        $this->assertSame(
            'teacher.recovery@example.test',
            $result['account']['recovery_email']
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $result['account']['status']
        );

        $this->assertSame(
            '2026-09-01 09:00:00',
            $result['account']['email_verified_at']
        );

        $this->assertFalse(
            $result['account']['must_change_password']
        );

        $this->assertSame(
            '2026-09-02 10:00:00',
            $result['account']['password_changed_at']
        );

        $this->assertSame(
            '2026-09-08 13:00:00',
            $result['account']['last_login_at']
        );

        /*
         * Explicit frontend management contract.
         */
        $this->assertSame(
            'admin_managed',
            $result['profile_management']['identity']
        );

        $this->assertSame(
            'admin_managed',
            $result['profile_management']['personal_picture']
        );

        $this->assertSame(
            'admin_managed',
            $result['profile_management']['recovery_email']
        );

        $this->assertSame(
            'self_service',
            $result['profile_management']['password']
        );
    }

    public function test_profile_does_not_expose_private_personal_picture_storage_path(): void
    {
        $context =
            $this->teacherContext(
                'Private Picture Teacher',
                [
                    'personal_picture_path' =>
                    'people/private/secret-teacher-picture.jpg',
                ]
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->profile(
                $context['user']
            );

        $this->assertTrue(
            $result['identity']['personal_picture_available']
        );

        $this->assertArrayNotHasKey(
            'personal_picture_path',
            $result['identity']
        );

        $serialized =
            json_encode(
                $result,
                JSON_THROW_ON_ERROR
            );

        $this->assertStringNotContainsString(
            'people/private/secret-teacher-picture.jpg',
            $serialized
        );
    }

    public function test_profile_reports_when_teacher_has_no_personal_picture(): void
    {
        $context =
            $this->teacherContext(
                'No Picture Teacher',
                [
                    'personal_picture_path' =>
                    null,
                ]
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->profile(
                $context['user']
            );

        $this->assertFalse(
            $result['identity']['personal_picture_available']
        );
    }

    public function test_non_teacher_account_cannot_use_teacher_profile_read_service(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for(
                $center
            )
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->profile(
                $studentUser
            );
    }

    public function test_deactivated_teacher_cannot_use_teacher_profile_read_service(): void
    {
        $context =
            $this->teacherContext(
                'Deactivated Profile Teacher'
            );

        $context['teacher']
            ->forceFill([
                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' =>
                now(),
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->profile(
                $context['user']
            );
    }

    public function test_person_linkage_without_exact_teacher_user_linkage_is_rejected(): void
    {
        $context =
            $this->teacherContext(
                'Exact Link Teacher'
            );

        /*
         * Preserve Center + Person linkage while removing the
         * explicit Teacher -> User Account linkage.
         *
         * Person-only linkage must never authorize Teacher
         * self-facing Profile access.
         */
        $context['teacher']
            ->forceFill([
                'user_id' =>
                null,
            ])
            ->save();

        $context['teacher']
            ->refresh();

        $this->assertSame(
            $context['person']->id,
            $context['teacher']->person_id
        );

        $this->assertSame(
            $context['user']->person_id,
            $context['teacher']->person_id
        );

        $this->assertNull(
            $context['teacher']->user_id
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->profile(
                $context['user']
            );
    }

    public function test_teacher_profile_read_service_fails_closed_on_tenant_mismatch(): void
    {
        $context =
            $this->teacherContext(
                'Tenant Profile Teacher'
            );

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $this->establishCenterContext(
            $otherCenter
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->profile(
                $context['user']
            );
    }

    public function test_teacher_profile_read_service_uses_persisted_account_state(): void
    {
        $context =
            $this->teacherContext(
                'Persisted State Teacher'
            );

        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        User::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $context['user']->id
            )
            ->update([
                'status' =>
                AccountStatus::Deactivated->value,

                'deactivated_at' =>
                now(),
            ]);

        /*
         * Deliberately keep the supplied object stale.
         */
        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->profile(
                $context['user']
            );
    }

    public function test_teacher_profile_read_creates_no_audit_records(): void
    {
        $context =
            $this->teacherContext(
                'Read Only Profile Teacher'
            );

        $this->establishCenterContext(
            $context['center']
        );

        $this->service()
            ->profile(
                $context['user']
            );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    /**
     * @param array<string, mixed> $personAttributes
     * @param array<string, mixed> $userAttributes
     *
     * @return array{
     *     center: Center,
     *     person: Person,
     *     user: User,
     *     teacher: Teacher
     * }
     */
    private function teacherContext(
        string $name,
        array $personAttributes = [],
        array $userAttributes = []
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for(
                $center
            )
            ->create([
                'full_name' =>
                $name,

                ...$personAttributes,
            ]);

        $user =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person,
                $userAttributes
            );

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        return [
            'center' =>
            $center,

            'person' =>
            $person,

            'user' =>
            $user,

            'teacher' =>
            $teacher,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUserForRole(
        SystemRole $role,
        Center $center,
        ?Person $person = null,
        array $attributes = []
    ): User {
        $person ??=
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

                ...$attributes,
            ]);
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );
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

    private function service(): TeacherProfileReadService
    {
        return app(
            TeacherProfileReadService::class
        );
    }
}