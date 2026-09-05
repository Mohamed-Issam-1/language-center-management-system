<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_teacher_receives_teacher_dashboard_payload(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/dashboard'
            )
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page
                    ->component(
                        'Dashboard'
                    )
                    ->where(
                        'dashboard.scope.role',
                        SystemRole::Teacher->value
                    )
                    ->where(
                        'dashboard.scope.scope_type',
                        'teacher'
                    )
                    ->has(
                        'dashboard.enrollments'
                    )
                    ->has(
                        'dashboard.classes'
                    )
                    ->has(
                        'dashboard.attendance'
                    )
                    ->where(
                        'dashboard.finance',
                        null
                    )
            );
    }

    public function test_student_receives_self_facing_dashboard_payload(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/dashboard'
            )
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) =>
                $page
                    ->component(
                        'Dashboard'
                    )
                    ->where(
                        'dashboard.scope.role',
                        SystemRole::Student->value
                    )
                    ->where(
                        'dashboard.scope.scope_type',
                        'student'
                    )
                    ->has(
                        'dashboard.enrollments'
                    )
                    ->has(
                        'dashboard.classes'
                    )
                    ->has(
                        'dashboard.attendance'
                    )
                    ->where(
                        'dashboard.finance.report_type',
                        'student_balance'
                    )
            );
    }

    public function test_center_owner_dashboard_route_redirects_to_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/dashboard'
            )
            ->assertRedirect(
                '/admin'
            );
    }

    public function test_platform_owner_dashboard_route_redirects_to_admin_panel(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this
            ->actingAs(
                $actor
            )
            ->get(
                '/dashboard'
            )
            ->assertRedirect(
                '/admin'
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

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
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
                ]);
        }

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
            ]);
    }
}