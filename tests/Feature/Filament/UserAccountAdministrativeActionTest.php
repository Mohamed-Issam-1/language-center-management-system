<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserAccounts\Pages\ListUserAccounts;
use App\Mail\RegistrationCredentialsMail;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class UserAccountAdministrativeActionTest
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

    public function test_center_owner_can_update_manageable_account_fields(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $center
            );

        $originalCenterId =
            $teacher->center_id;

        $originalPersonId =
            $teacher->person_id;

        $originalRoleId =
            $teacher->role_id;

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->callAction(
                TestAction::make(
                    'editUserAccount'
                )->table(
                    $teacher
                ),
                [
                    'account_login_identifier' =>
                    'updated.teacher',

                    'recovery_email' =>
                    'UPDATED@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();

        $this->assertSame(
            'updated.teacher',
            $teacher
                ->account_login_identifier
        );

        $this->assertSame(
            'updated@example.test',
            $teacher
                ->recovery_email
        );

        $this->assertSame(
            $originalCenterId,
            $teacher->center_id
        );

        $this->assertSame(
            $originalPersonId,
            $teacher->person_id
        );

        $this->assertSame(
            $originalRoleId,
            $teacher->role_id
        );
    }

    public function test_branch_manager_can_update_linked_student_account_in_current_branch(): void
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
            $this->account(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment
            ::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $center->id,

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

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $studentAccount =
            $this->account(
                SystemRole::Student,
                $center,
                $person
            );

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
                $studentAccount->id,
            ]);

        $this->actingAs(
            $manager
        );

        $this->branchScope(
            $center,
            $branch
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->callAction(
                TestAction::make(
                    'editUserAccount'
                )->table(
                    $studentAccount
                ),
                [
                    'account_login_identifier' =>
                    'branch.student.updated',

                    'recovery_email' =>
                    'BRANCH.STUDENT@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $studentAccount->refresh();

        $this->assertSame(
            'branch.student.updated',
            $studentAccount
                ->account_login_identifier
        );

        $this->assertSame(
            'branch.student@example.test',
            $studentAccount
                ->recovery_email
        );
    }

    public function test_platform_owner_can_manage_center_owner_account(): void
    {
        $platformOwner =
            $this->platformOwner();

        $center =
            Center::factory()
            ->active()
            ->create();

        $centerOwner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        Livewire::test(
            ListUserAccounts::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateUserAccount'
                )->table(
                    $centerOwner
                )
            )
            ->assertHasNoActionErrors();

        $centerOwner->refresh();

        $this->assertSame(
            AccountStatus::Deactivated,
            $centerOwner->status
        );

        $this->assertNotNull(
            $centerOwner
                ->deactivated_at
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->callAction(
                TestAction::make(
                    'activateUserAccount'
                )->table(
                    $centerOwner
                )
            )
            ->assertHasNoActionErrors();

        $centerOwner->refresh();

        $this->assertSame(
            AccountStatus::Active,
            $centerOwner->status
        );

        $this->assertNull(
            $centerOwner
                ->deactivated_at
        );
    }

    public function test_account_deactivation_does_not_change_student_operational_record(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $studentAccount =
            $this->account(
                SystemRole::Student,
                $center,
                $person
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
                $studentAccount->id,
            ]);

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->callAction(
                TestAction::make(
                    'deactivateUserAccount'
                )->table(
                    $studentAccount
                )
            )
            ->assertHasNoActionErrors();

        $studentAccount->refresh();
        $student->refresh();

        $this->assertSame(
            AccountStatus::Deactivated,
            $studentAccount->status
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertSame(
            $studentAccount->id,
            $student->user_id
        );
    }

    public function test_active_operational_teacher_can_receive_reissued_credentials(): void
    {
        Mail::fake();

        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacherAccount =
            $this->account(
                SystemRole::Teacher,
                $center,
                $person
            );

        Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $teacherAccount->id,

                'status' =>
                StaffStatus::Active,

                'deactivated_at' =>
                null,
            ]);

        $oldPasswordHash =
            (string)
            $teacherAccount->password;

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'reissueUserCredentials'
                )->table(
                    $teacherAccount
                )
            )
            ->callAction(
                TestAction::make(
                    'reissueUserCredentials'
                )->table(
                    $teacherAccount
                )
            )
            ->assertHasNoActionErrors();

        $teacherAccount->refresh();

        $this->assertFalse(
            hash_equals(
                $oldPasswordHash,
                (string)
                $teacherAccount->password
            )
        );

        $this->assertTrue(
            $teacherAccount
                ->must_change_password
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    public function test_reissue_is_hidden_for_deactivated_operational_teacher(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacherAccount =
            $this->account(
                SystemRole::Teacher,
                $center,
                $person
            );

        Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $teacherAccount->id,

                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' =>
                now(),
            ]);

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'reissueUserCredentials'
                )->table(
                    $teacherAccount
                )
            );
    }

    public function test_deactivated_account_exposes_activate_but_not_reissue(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->account(
                SystemRole::CenterOwner,
                $center
            );

        $teacher =
            $this->account(
                SystemRole::Teacher,
                $center,
                status: AccountStatus::Deactivated
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListUserAccounts::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'activateUserAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateUserAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueUserCredentials'
                )->table(
                    $teacher
                )
            );
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

    private function account(
        SystemRole $role,
        Center $center,
        ?Person $person = null,
        AccountStatus $status =
        AccountStatus::Active
    ): User {
        $person ??=
            Person::factory()
            ->for($center)
            ->create();

        $account =
            User::factory()
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
                $status,

                'must_change_password' =>
                false,
            ]);

        if (
            $status
            === AccountStatus::Deactivated
        ) {
            $account->forceFill([
                'deactivated_at' =>
                now(),
            ])->save();

            $account->refresh();
        }

        return $account;
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