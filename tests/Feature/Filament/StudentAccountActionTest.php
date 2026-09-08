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

class StudentAccountActionTest
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

    public function test_center_owner_can_create_and_link_student_account(): void
    {
        Mail::fake();

        [
            $center,
            $branch,
            $student,
        ] = $this->student();

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
                    'createStudentAccount'
                )->table(
                    $student
                )
            )
            ->callAction(
                TestAction::make(
                    'createStudentAccount'
                )->table(
                    $student
                ),
                [
                    'recovery_email' =>
                    'STUDENT@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $this->assertNotNull(
            $student->user_id
        );

        $account =
            User::withoutGlobalScopes()
            ->with('role')
            ->findOrFail(
                $student->user_id
            );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $student->person_id,
            $account->person_id
        );

        $this->assertSame(
            SystemRole::Student,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'student@example.test',
            $account->recovery_email
        );

        $this->assertTrue(
            $account
                ->must_change_password
        );

        $identifier =
            (string)
            $account
                ->account_login_identifier;

        $this->assertSame(
            8,
            strlen(
                $identifier
            )
        );

        $this->assertTrue(
            ctype_digit(
                $identifier
            )
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    public function test_existing_student_role_account_is_linked_instead_of_duplicate_created(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->student();

        $existingAccount =
            $this->user(
                SystemRole::Student,
                $center,
                $student->person
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
            ->assertActionHidden(
                TestAction::make(
                    'createStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'linkExistingStudentAccount'
                )->table(
                    $student
                )
            )
            ->callAction(
                TestAction::make(
                    'linkExistingStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $this->assertSame(
            $existingAccount->id,
            $student->user_id
        );

        $this->assertSame(
            1,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'person_id',
                    $student->person_id
                )
                ->where(
                    'role_id',
                    $this->role(
                        SystemRole::Student
                    )->id
                )
                ->count()
        );
    }

    public function test_archived_student_exposes_no_account_creation_or_link_action(): void
    {
        [
            $center,
            $branch,
            $student,
        ] = $this->student(
            StudentStatus::Archived
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
            ->assertActionHidden(
                TestAction::make(
                    'createStudentAccount'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingStudentAccount'
                )->table(
                    $student
                )
            );
    }

    public function test_branch_manager_can_create_student_account_in_assigned_branch(): void
    {
        Mail::fake();

        [
            $center,
            $branch,
            $student,
        ] = $this->student();

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
                    'createStudentAccount'
                )->table(
                    $student
                ),
                [
                    'recovery_email' =>
                    'student@example.test',
                ]
            )
            ->assertHasNoActionErrors();

        $student->refresh();

        $this->assertNotNull(
            $student->user_id
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            1
        );
    }

    /**
     * @return array{
     *     0: Center,
     *     1: Branch,
     *     2: Student
     * }
     */
    private function student(
        StudentStatus $status =
        StudentStatus::Active
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
                null,

                'status' =>
                $status,

                'archived_at' =>
                $status
                    === StudentStatus::Archived
                    ? now()
                    : null,
            ]);

        return [
            $center,
            $branch,
            $student,
        ];
    }

    private function user(
        SystemRole $role,
        Center $center,
        ?Person $person = null
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
