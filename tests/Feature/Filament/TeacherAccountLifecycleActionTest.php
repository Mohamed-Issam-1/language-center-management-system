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

class TeacherAccountLifecycleActionTest
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

    public function test_active_teacher_account_exposes_deactivate_and_reissue_only(): void
    {
        [
            $center,
            $teacher,
        ] = $this->teacher(
            StaffStatus::Active,
            AccountStatus::Active
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
            ->assertActionVisible(
                TestAction::make(
                    'deactivateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'reissueTeacherCredentials'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'activateTeacherAccount'
                )->table(
                    $teacher
                )
            );
    }

    public function test_center_owner_can_deactivate_teacher_account_without_deactivating_teacher_record(): void
    {
        [
            $center,
            $teacher,
            $account,
        ] = $this->teacher();

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
            ->callAction(
                TestAction::make(
                    'deactivateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();
        $account->refresh();

        $this->assertSame(
            AccountStatus::Deactivated,
            $account->status
        );

        $this->assertNotNull(
            $account->deactivated_at
        );

        $this->assertSame(
            StaffStatus::Active,
            $teacher->status
        );

        $this->assertNull(
            $teacher->deactivated_at
        );
    }

    public function test_deactivated_teacher_account_exposes_activate_only(): void
    {
        [
            $center,
            $teacher,
        ] = $this->teacher(
            StaffStatus::Active,
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
            ->assertActionVisible(
                TestAction::make(
                    'activateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'deactivateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueTeacherCredentials'
                )->table(
                    $teacher
                )
            );
    }

    public function test_center_owner_can_activate_teacher_account(): void
    {
        [
            $center,
            $teacher,
            $account,
        ] = $this->teacher(
            StaffStatus::Active,
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
            ->callAction(
                TestAction::make(
                    'activateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $account->refresh();
        $teacher->refresh();

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );

        $this->assertSame(
            StaffStatus::Active,
            $teacher->status
        );
    }

    public function test_deactivated_teacher_record_does_not_expose_reissue_credentials(): void
    {
        [
            $center,
            $teacher,
        ] = $this->teacher(
            StaffStatus::Deactivated,
            AccountStatus::Active
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
            ->assertActionVisible(
                TestAction::make(
                    'deactivateTeacherAccount'
                )->table(
                    $teacher
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'reissueTeacherCredentials'
                )->table(
                    $teacher
                )
            );
    }

    public function test_reissue_teacher_credentials_changes_password_and_sends_email(): void
    {
        Mail::fake();

        [
            $center,
            $teacher,
            $account,
        ] = $this->teacher();

        $oldPasswordHash =
            (string)
            $account->password;

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
            ->callAction(
                TestAction::make(
                    'reissueTeacherCredentials'
                )->table(
                    $teacher
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
     *     1:Teacher,
     *     2:User
     * }
     */
    private function teacher(
        StaffStatus $staffStatus =
        StaffStatus::Active,
        AccountStatus $accountStatus =
        AccountStatus::Active
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

        $account =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,

                'account_login_identifier' =>
                '41120001',

                'recovery_email' =>
                'teacher@example.test',

                'status' =>
                $accountStatus,

                'password' =>
                'OldTeacherPassword123!',

                'must_change_password' =>
                false,

                'failed_login_attempts' =>
                3,

                'locked_until' =>
                now()->addMinutes(
                    5
                ),

                'deactivated_at' =>
                $accountStatus
                    === AccountStatus::Deactivated
                    ? now()
                    : null,
            ]);

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $account->id,

                'status' =>
                $staffStatus,

                'deactivated_at' =>
                $staffStatus
                    === StaffStatus::Deactivated
                    ? now()
                    : null,
            ]);

        return [
            $center,
            $teacher,
            $account,
        ];
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