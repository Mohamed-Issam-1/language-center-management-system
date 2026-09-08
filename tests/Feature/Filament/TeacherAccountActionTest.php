<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Mail\RegistrationCredentialsMail;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherAccountActionTest
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

    public function test_center_owner_can_create_link_and_send_teacher_credentials(): void
    {
        Mail::fake();

        [
            $center,
            $teacher,
            $person,
        ] = $this->teacherWithoutAccount();

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListTeachers::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'createTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->callAction(
                TestAction::make(
                    'createTeacherAccount'
                )->table(
                    $teacher
                ),
                [
                    'recovery_email' =>
                    'TEACHER.RECOVERY@EXAMPLE.TEST',
                ]
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();

        $this->assertNotNull(
            $teacher->user_id
        );

        $account =
            User::withoutGlobalScopes()
            ->with('role')
            ->findOrFail(
                $teacher->user_id
            );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $person->id,
            $account->person_id
        );

        $this->assertSame(
            SystemRole::Teacher,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            'teacher.recovery@example.test',
            $account->recovery_email
        );

        $this->assertTrue(
            $account->must_change_password
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

    public function test_existing_active_teacher_account_is_linked_instead_of_duplicate_created(): void
    {
        [
            $center,
            $teacher,
            $person,
        ] = $this->teacherWithoutAccount();

        $existing =
            $this->teacherAccount(
                $center,
                $person
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListTeachers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'linkExistingTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->callAction(
                TestAction::make(
                    'linkExistingTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();

        $this->assertSame(
            $existing->id,
            $teacher->user_id
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
                    $person->id
                )
                ->where(
                    'role_id',
                    $this->role(
                        SystemRole::Teacher
                    )->id
                )
                ->count()
        );
    }

    public function test_existing_deactivated_teacher_account_prevents_duplicate_creation(): void
    {
        [
            $center,
            $teacher,
            $person,
        ] = $this->teacherWithoutAccount();

        $this->teacherAccount(
            $center,
            $person,
            AccountStatus::Deactivated
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListTeachers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingTeacherAccount'
                )->table(
                    $teacher
                )
            );
    }

    public function test_deactivated_teacher_exposes_no_create_or_link_account_action(): void
    {
        [
            $center,
            $teacher,
        ] = $this->teacherWithoutAccount(
            StaffStatus::Deactivated
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListTeachers::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'createTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'linkExistingTeacherAccount'
                )->table(
                    $teacher
                )
            );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:Teacher,
     *     2:Person
     * }
     */
    private function teacherWithoutAccount(
        StaffStatus $status =
        StaffStatus::Active
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '41',
            ]);

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900000001',

                'email' =>
                'teacher@example.test',
            ]);

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                null,

                'status' =>
                $status,

                'deactivated_at' =>
                $status
                    === StaffStatus::Deactivated
                    ? now()
                    : null,
            ]);

        return [
            $center,
            $teacher,
            $person,
        ];
    }

    private function teacherAccount(
        Center $center,
        Person $person,
        AccountStatus $status =
        AccountStatus::Active
    ): User {
        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,

                'status' =>
                $status,

                'deactivated_at' =>
                $status
                    === AccountStatus::Deactivated
                    ? now()
                    : null,

                'must_change_password' =>
                false,
            ]);
    }

    private function centerOwner(
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
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
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