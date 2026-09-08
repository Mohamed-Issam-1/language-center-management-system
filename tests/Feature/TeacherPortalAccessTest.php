<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TeacherPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );

        /*
         * Test-only Teacher Portal boundary.
         *
         * Production Teacher pages do not exist yet, so the
         * middleware is tested directly without prematurely
         * registering frontend routes.
         */
        Route::middleware([
            'web',
            'auth',
            'tenant.context',
            'teacher.portal',
        ])->get(
            '/_test/teacher-portal',
            fn() =>
            response()->json([
                'ok' => true,
            ])
        );
    }

    public function test_active_exactly_linked_teacher_can_access_teacher_portal_boundary(): void
    {
        $context =
            $this->teacherContext();

        $this
            ->actingAs(
                $context['user']
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertOk()
            ->assertJson([
                'ok' => true,
            ]);
    }

    public function test_non_teacher_roles_cannot_access_teacher_portal_boundary(): void
    {
        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::CenterOwner,
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Student,
            ] as $role
        ) {
            $user =
                $this->createUserForRole(
                    $role
                );

            $this
                ->actingAs(
                    $user
                )
                ->getJson(
                    '/_test/teacher-portal'
                )
                ->assertForbidden();
        }
    }

    public function test_teacher_role_without_teacher_record_cannot_access_portal_boundary(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $user =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
            );

        $this
            ->actingAs(
                $user
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertForbidden();
    }

    public function test_person_only_teacher_linkage_is_not_enough_for_portal_access(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $user =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
            );

        /*
         * The Teacher record belongs to the same Person and
         * Center but has no explicit User Account linkage.
         *
         * Person-only matching must not grant Teacher Portal
         * access.
         */
        Teacher::factory()
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                null,
            ]);

        $this
            ->actingAs(
                $user
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertForbidden();
    }

    public function test_teacher_record_linked_to_another_user_does_not_grant_access(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacherUser =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
            );

        /*
         * A different role may legitimately have another User
         * Account for the same shared Person identity.
         *
         * Deliberately attach the operational Teacher record to
         * that different User so exact User linkage is broken.
         */
        $otherUser =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center,
                $person
            );

        Teacher::factory()
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $otherUser->id,
            ]);

        $this
            ->actingAs(
                $teacherUser
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertForbidden();
    }

    public function test_deactivated_teacher_record_cannot_access_teacher_portal_boundary(): void
    {
        $context =
            $this->teacherContext();

        $context['teacher']
            ->forceFill([
                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' =>
                now(),
            ])
            ->save();

        $this
            ->actingAs(
                $context['user']
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertForbidden();
    }

    public function test_deactivated_teacher_account_is_rejected_before_portal_access(): void
    {
        $context =
            $this->teacherContext();

        /*
        * Change persisted account state directly while keeping
        * the supplied authenticated User object deliberately stale.
        *
        * The Teacher Portal boundary must re-read the account and
        * reject the persisted Deactivated state.
        */
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

        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        $this
            ->actingAs(
                $context['user']
            )
            ->getJson(
                '/_test/teacher-portal'
            )
            ->assertForbidden();
    }

    /**
     * @return array{
     *     center: Center,
     *     person: Person,
     *     user: User,
     *     teacher: Teacher
     * }
     */
    private function teacherContext(): array
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $user =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
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

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null,
        ?Person $person = null
    ): User {
        if (
            $role
            === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

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

        $center ??=
            Center::factory()
            ->active()
            ->create();

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
