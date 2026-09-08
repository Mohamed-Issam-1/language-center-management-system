<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Students\Pages\ListStudents;
use App\Mail\RegistrationCredentialsMail;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class StudentAccountLifecycleActionTest
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

    public function test_active_student_account_exposes_deactivate_and_reissue_only(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Active
        );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'deactivateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reissueStudentCredentials'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'activateStudentAccount'
                )->table(
                    $student
                )
            );
    }

    public function test_center_owner_can_deactivate_linked_student_account(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Active
        );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $account =
            User::withoutGlobalScopes()
            ->findOrFail(
                $student->user_id
            );

        $this->assertSame(
            AccountStatus::Deactivated,
            $account->status
        );

        $this->assertNotNull(
            $account->deactivated_at
        );
    }

    public function test_deactivated_student_account_exposes_activate_only(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Deactivated
        );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'activateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueStudentCredentials'
                )->table(
                    $student
                )
            );
    }

    public function test_center_owner_can_activate_deactivated_student_account(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Deactivated
        );

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'activateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $account =
            User::withoutGlobalScopes()
            ->findOrFail(
                $student->user_id
            );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );
    }

    public function test_branch_manager_can_manage_linked_student_account_inside_assigned_branch(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Active
        );

        $manager =
            $this->user(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->actingAs(
            $manager
        );

        $this->branchScope(
            $center,
            $branch
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $account =
            User::withoutGlobalScopes()
            ->findOrFail(
                $student->user_id
            );

        $this->assertSame(
            AccountStatus::Deactivated,
            $account->status
        );
    }

    public function test_reissue_credentials_changes_password_and_sends_email(): void
    {
        Mail::fake();

        [
            $center,
            $branch,
            $student,
        ] = $this->studentWithAccount(
            AccountStatus::Active
        );

        $account =
            User::withoutGlobalScopes()
            ->findOrFail(
                $student->user_id
            );

        $oldPasswordHash =
            (string)
            $account->password;

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'reissueStudentCredentials'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();

        $this->assertFalse(
            hash_equals(
                $oldPasswordHash,
                (string)
                $account->password
            )
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertSame(
            0,
            $account->failed_login_attempts
        );

        $this->assertNull(
            $account->locked_until
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:Branch,
     *     2:Student
     * }
     */
    private function studentWithAccount(
        AccountStatus $status
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '41',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'email' =>
                'student@example.test',
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
                    SystemRole::Student
                )->id,

                'account_login_identifier' =>
                '41130001',

                'recovery_email' =>
                'student@example.test',

                'status' =>
                $status,

                'password' =>
                'OldPassword123!',

                'must_change_password' =>
                false,

                'failed_login_attempts' =>
                3,

                'locked_until' =>
                now()->addMinutes(
                    5
                ),

                'deactivated_at' =>
                $status
                    === AccountStatus::Deactivated
                    ? now()
                    : null,
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $person
            )
            ->create([
                'user_id' =>
                $account->id,
            ]);

        return [
            $center,
            $branch,
            $student,
        ];
    }

    private function user(
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

    private function assignManager(
        User $manager,
        Branch $branch
    ): void {
        BranchManagerAssignment
            ::query()
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

    private function centerWide(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function branchScope(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
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
}