<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Teachers\Pages\ListTeachers;
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
use Livewire\Livewire;
use Tests\TestCase;

class TeacherAdministrativeActionTest
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

    public function test_center_owner_can_update_teacher_identity(): void
    {
        [
            $center,
            $teacher,
            $person,
            $account,
        ] = $this->teacher();

        $account->forceFill([
            'recovery_email' =>
            'recovery@example.test',
        ])->save();

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
                    'editTeacherIdentity'
                )->table(
                    $teacher
                )
            )
            ->callAction(
                TestAction::make(
                    'editTeacherIdentity'
                )->table(
                    $teacher
                ),
                [
                    'full_name' =>
                    'Updated Teacher',

                    'date_of_birth' =>
                    '1995-04-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'UPDATED.TEACHER@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599123456',
                ]
            )
            ->assertHasNoActionErrors();

        $person->refresh();
        $account->refresh();

        $this->assertSame(
            'Updated Teacher',
            $person->full_name
        );

        $this->assertSame(
            'updated.teacher@example.test',
            $person->email
        );

        $this->assertSame(
            '+970599123456',
            $person->phone_number
        );

        $this->assertSame(
            'recovery@example.test',
            $account->recovery_email
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'person.identity_updated',

                'subject_id' =>
                $person->id,
            ]
        );
    }

    public function test_center_owner_can_deactivate_teacher_without_deactivating_user_account(): void
    {
        [
            $center,
            $teacher,
            $person,
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
            ->assertActionVisible(
                TestAction::make(
                    'deactivateTeacher'
                )->table(
                    $teacher
                )
            )
            ->callAction(
                TestAction::make(
                    'deactivateTeacher'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();
        $account->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $teacher->status
        );

        $this->assertNotNull(
            $teacher->deactivated_at
        );

        /*
         * Operational lifecycle and authentication
         * lifecycle remain deliberately separate.
         */
        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertNull(
            $account->deactivated_at
        );
    }

    public function test_center_owner_can_reactivate_teacher_when_linked_account_is_active(): void
    {
        [
            $center,
            $teacher,
        ] = $this->teacher(
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
            ->assertActionVisible(
                TestAction::make(
                    'activateTeacher'
                )->table(
                    $teacher
                )
            )
            ->callAction(
                TestAction::make(
                    'activateTeacher'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();

        $this->assertSame(
            StaffStatus::Active,
            $teacher->status
        );

        $this->assertNull(
            $teacher->deactivated_at
        );
    }

    public function test_teacher_cannot_be_reactivated_while_linked_account_is_deactivated(): void
    {
        [
            $center,
            $teacher,
            $person,
            $account,
        ] = $this->teacher(
            StaffStatus::Deactivated,
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
                    'activateTeacher'
                )->table(
                    $teacher
                )
            )
            ->assertHasNoActionErrors();

        $teacher->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $teacher->status
        );

        $this->assertNotNull(
            $teacher->deactivated_at
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:Teacher,
     *     2:Person,
     *     3:User
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
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '123456789',

                'full_name' =>
                'Original Teacher',

                'date_of_birth' =>
                '1990-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'teacher@example.test',

                'phone_number' =>
                '+970590000000',
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

                'status' =>
                $accountStatus,

                'deactivated_at' =>
                $accountStatus
                    === AccountStatus::Deactivated
                    ? now()
                    : null,

                'must_change_password' =>
                false,
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
            $person,
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