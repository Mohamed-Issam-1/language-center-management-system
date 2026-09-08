<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentProfileReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileReadServiceTest extends TestCase
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
            $this->studentContext(
                'Canonical Student',
                [
                    'national_id_number' =>
                    '123456789',

                    'date_of_birth' =>
                    '2001-05-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'student.personal@example.test',

                    'phone_number' =>
                    '+970599123456',

                    'personal_picture_path' =>
                    'people/private/student.jpg',
                ],
                [
                    /*
                     * Deliberately different from Person name.
                     *
                     * Student Profile must use canonical
                     * Person identity instead.
                     */
                    'name' =>
                    'Legacy User Name',

                    'account_login_identifier' =>
                    '12345678',

                    'recovery_email' =>
                    'recovery@example.test',

                    'email_verified_at' =>
                    '2026-09-01 10:00:00',

                    'must_change_password' =>
                    false,

                    'password_changed_at' =>
                    '2026-09-02 11:00:00',

                    'last_login_at' =>
                    '2026-09-08 12:00:00',
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
         * Student operational identity.
         */
        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            $context['person']->id,
            $result['student']['person_id']
        );

        $this->assertSame(
            $context['user']->id,
            $result['student']['user_id']
        );

        $this->assertSame(
            '12345678',
            $result['student']['account_login_identifier']
        );

        /*
         * Canonical name must come from Person,
         * not User.name.
         */
        $this->assertSame(
            'Canonical Student',
            $result['student']['name']
        );

        $this->assertNotSame(
            'Legacy User Name',
            $result['student']['name']
        );

        $this->assertSame(
            StudentStatus::Active->value,
            $result['student']['status']
        );

        $this->assertSame(
            $context['center']->id,
            $result['student']['center']['id']
        );

        $this->assertSame(
            $context['branch']->id,
            $result['student']['branch']['id']
        );

        /*
         * Canonical Person identity.
         */
        $this->assertSame(
            $context['person']->id,
            $result['identity']['person_id']
        );

        $this->assertSame(
            '123456789',
            $result['identity']['national_id_number']
        );

        $this->assertSame(
            'Canonical Student',
            $result['identity']['full_name']
        );

        $this->assertSame(
            '2001-05-15',
            $result['identity']['date_of_birth']
        );

        $this->assertSame(
            'Gaza',
            $result['identity']['city_of_residence']
        );

        $this->assertSame(
            'student.personal@example.test',
            $result['identity']['email']
        );

        $this->assertSame(
            '+970599123456',
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
            '12345678',
            $result['account']['account_login_identifier']
        );

        $this->assertSame(
            'recovery@example.test',
            $result['account']['recovery_email']
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $result['account']['status']
        );

        $this->assertSame(
            '2026-09-01 10:00:00',
            $result['account']['email_verified_at']
        );

        $this->assertFalse(
            $result['account']['must_change_password']
        );

        $this->assertSame(
            '2026-09-02 11:00:00',
            $result['account']['password_changed_at']
        );

        $this->assertSame(
            '2026-09-08 12:00:00',
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
            $this->studentContext(
                'Private Picture Student',
                [
                    'personal_picture_path' =>
                    'people/private/secret-student-picture.jpg',
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
            'people/private/secret-student-picture.jpg',
            $serialized
        );
    }

    public function test_profile_reports_when_student_has_no_personal_picture(): void
    {
        $context =
            $this->studentContext(
                'No Picture Student',
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

    public function test_non_student_account_cannot_use_student_profile_read_service(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacher =
            $this->createUserForRole(
                SystemRole::Teacher,
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
                $teacher
            );
    }

    public function test_archived_student_cannot_use_student_profile_read_service(): void
    {
        $context =
            $this->studentContext(
                'Archived Student'
            );

        $context['student']
            ->forceFill([
                'status' =>
                StudentStatus::Archived->value,

                'archived_at' =>
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

    public function test_person_linkage_without_exact_student_user_linkage_is_rejected(): void
    {
        $context =
            $this->studentContext(
                'Exact Link Student'
            );

        /*
     * Preserve the valid Center + Person relationship,
     * but remove the explicit Student -> User Account link.
     *
     * Person-only linkage must not authorize Student
     * self-facing Profile access.
     */
        $context['student']
            ->forceFill([
                'user_id' =>
                null,
            ])
            ->save();

        $context['student']
            ->refresh();

        $this->assertSame(
            $context['person']->id,
            $context['student']->person_id
        );

        $this->assertSame(
            $context['user']->person_id,
            $context['student']->person_id
        );

        $this->assertNull(
            $context['student']->user_id
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

    public function test_student_profile_read_service_fails_closed_on_tenant_mismatch(): void
    {
        $context =
            $this->studentContext(
                'Tenant Profile Student'
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

    public function test_student_profile_read_service_uses_persisted_account_state(): void
    {
        $context =
            $this->studentContext(
                'Persisted State Student'
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
         * Supplied object deliberately remains stale.
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

    public function test_student_profile_read_creates_no_audit_records(): void
    {
        $context =
            $this->studentContext(
                'Read Only Student'
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
     *     branch: Branch,
     *     person: Person,
     *     user: User,
     *     student: Student
     * }
     */
    private function studentContext(
        string $name,
        array $personAttributes = [],
        array $userAttributes = []
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                $name,

                ...$personAttributes,
            ]);

        $user =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person,
                $userAttributes
            );

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
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

            'branch' =>
            $branch,

            'person' =>
            $person,

            'user' =>
            $user,

            'student' =>
            $student,
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

    private function service(): StudentProfileReadService
    {
        return app(
            StudentProfileReadService::class
        );
    }
}
