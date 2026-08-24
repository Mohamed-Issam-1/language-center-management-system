<?php

namespace Tests\Feature\Filament;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_admin_request_uses_application_login_entry_point(): void
    {
        $this->get('/admin')
            ->assertRedirect(
                route(
                    'filament.admin.auth.login'
                )
            );

        $this->get(
            route(
                'filament.admin.auth.login',
                absolute: false
            )
        )->assertRedirect(
            route('login')
        );
    }

    public function test_application_login_preserves_intended_admin_destination(): void
    {
        $user =
            $this->createPlatformOwner();

        /*
         * First request records /admin as the intended URL.
         */
        $this->get('/admin')
            ->assertRedirect(
                route(
                    'filament.admin.auth.login'
                )
            );

        /*
         * Filament login performs no credential authentication.
         */
        $this->get(
            route(
                'filament.admin.auth.login',
                absolute: false
            )
        )->assertRedirect(
            route('login')
        );

        /*
         * Actual authentication still goes through the LCMS
         * account_login_identifier workflow.
         */
        $response =
            $this->post(
                '/login',
                [
                    'account_login_identifier' =>
                    $user
                        ->account_login_identifier,

                    'password' =>
                    'password',
                ]
            );

        $response->assertRedirect(
            url('/admin')
        );

        $this->assertAuthenticatedAs(
            $user
        );
    }

    public function test_platform_owner_can_access_admin_panel(): void
    {
        $user =
            $this->createPlatformOwner();

        $this
            ->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }

    public function test_center_owner_can_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this
            ->actingAs($owner)
            ->get('/admin')
            ->assertOk();
    }

    public function test_branch_manager_with_active_assignment_can_access_admin_panel(): void
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

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $this
            ->actingAs($manager)
            ->get('/admin')
            ->assertOk();
    }

    public function test_finance_employee_with_active_assignment_can_access_admin_panel(): void
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

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $branch
        );

        $this
            ->actingAs($finance)
            ->get('/admin')
            ->assertOk();
    }

    public function test_branch_manager_without_active_assignment_is_rejected_from_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this
            ->actingAs($manager)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_finance_employee_without_active_assignment_is_rejected_from_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this
            ->actingAs($finance)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_teacher_cannot_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $teacher =
            $this->createCenterUser(
                SystemRole::Teacher,
                $center
            );

        $this
            ->actingAs($teacher)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_student_cannot_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $student =
            $this->createCenterUser(
                SystemRole::Student,
                $center
            );

        $this
            ->actingAs($student)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_deactivated_administrative_account_cannot_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $owner->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $this
            ->actingAs($owner)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_pending_administrative_account_cannot_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $owner->forceFill([
            'status' =>
            AccountStatus::Pending,
        ])->save();

        $this
            ->actingAs($owner)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_administrative_account_requiring_password_change_is_redirected_to_existing_forced_password_flow(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        /*
         * Represent an authenticated session after the temporary
         * password has already been consumed.
         */
        $owner->forceFill([
            'must_change_password' =>
            true,

            'temporary_password_used_at' =>
            now(),
        ])->save();

        $this
            ->actingAs($owner)
            ->get('/admin')
            ->assertRedirect(
                route(
                    'profile.edit',
                    absolute: false
                )
            );
    }

    public function test_center_account_from_suspended_center_cannot_access_admin_panel(): void
    {
        $center =
            Center::factory()
            ->suspended()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this
            ->actingAs($owner)
            ->get('/admin')
            ->assertForbidden();
    }

    private function createPlatformOwner(): User
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

    private function createCenterUser(
        SystemRole $role,
        Center $center
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
            ->firstOrCreate(
                [
                    'code' =>
                    $role->value,
                ],
                [
                    'name' =>
                    $role->label(),
                ]
            );
    }

    private function assignManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function assignFinanceEmployee(
        User $finance,
        Branch $branch
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $finance->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }
}
