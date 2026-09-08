<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_active_linked_student_can_access_student_portal_routes(): void
    {
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
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $studentUser->id,
            ]);

        foreach (
            [
                '/my-courses',
                '/my-courses/1',
                '/my-schedule',
                '/my-attendance',
                '/payments',
                '/student/profile',
                '/student/profile/edit',
            ] as $uri
        ) {
            $this
                ->actingAs(
                    $studentUser
                )
                ->get(
                    $uri
                )
                ->assertOk();
        }
    }

    public function test_non_student_roles_cannot_access_student_portal(): void
    {
        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::CenterOwner,
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
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
                ->get(
                    '/my-courses'
                )
                ->assertForbidden();
        }
    }

    public function test_student_role_without_linked_student_record_cannot_access_portal(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $this
            ->actingAs(
                $studentUser
            )
            ->get(
                '/my-courses'
            )
            ->assertForbidden();
    }

    public function test_student_record_linked_only_by_person_is_not_enough_for_portal_access(): void
    {
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
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                null,
            ]);

        $this
            ->actingAs(
                $studentUser
            )
            ->get(
                '/my-courses'
            )
            ->assertForbidden();
    }

    public function test_archived_student_cannot_access_student_portal(): void
    {
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
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->archived()
            ->create([
                'user_id' =>
                $studentUser->id,
            ]);

        $this
            ->actingAs(
                $studentUser
            )
            ->get(
                '/my-courses'
            )
            ->assertForbidden();
    }

    public function test_student_record_linked_to_another_user_does_not_grant_access(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $personA =
            Person::factory()
            ->for($center)
            ->create();

        $personB =
            Person::factory()
            ->for($center)
            ->create();

        $studentUserA =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $personA
            );

        $studentUserB =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $personB
            );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($personA)
            ->active()
            ->create([
                'user_id' =>
                $studentUserA->id,
            ]);

        $this
            ->actingAs(
                $studentUserB
            )
            ->get(
                '/my-courses'
            )
            ->assertForbidden();
    }

    public function test_deactivated_student_account_is_rejected_before_portal_access(): void
    {
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
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $studentUser->id,
            ]);

        $studentUser->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $this
            ->actingAs(
                $studentUser
            )
            ->get(
                '/my-courses'
            )
            ->assertForbidden();
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null,
        ?Person $person = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
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
