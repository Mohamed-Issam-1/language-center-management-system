<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Students\Pages\ListStudents;
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
use Livewire\Livewire;
use Tests\TestCase;

class StudentIdentityActionTest
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

    public function test_center_owner_can_update_student_person_identity_through_filament(): void
    {
        [
            $center,
            $branch,
            $student,
            $person,
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
                    'editStudentIdentity'
                )->table(
                    $student
                )
            )
            ->callAction(
                TestAction::make(
                    'editStudentIdentity'
                )->table(
                    $student
                ),
                [
                    'full_name' =>
                    'Updated Student',

                    'date_of_birth' =>
                    '2002-04-15',

                    'city_of_residence' =>
                    'Khan Younis',

                    'email' =>
                    'UPDATED@EXAMPLE.TEST',

                    'phone_number' =>
                    '+970599123456',
                ]
            )
            ->assertHasNoActionErrors();

        $person->refresh();

        $this->assertSame(
            'Updated Student',
            $person->full_name
        );

        $this->assertSame(
            '2002-04-15',
            $person
                ->date_of_birth
                ->format('Y-m-d')
        );

        $this->assertSame(
            'Khan Younis',
            $person
                ->city_of_residence
        );

        $this->assertSame(
            'updated@example.test',
            $person->email
        );

        $this->assertSame(
            '+970599123456',
            $person->phone_number
        );

        /*
         * Immutable identity key remains unchanged.
         */
        $this->assertSame(
            '123456789',
            $person
                ->national_id_number
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

    public function test_branch_manager_cannot_edit_shared_student_identity(): void
    {
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
            ->assertActionHidden(
                TestAction::make(
                    'editStudentIdentity'
                )->table(
                    $student
                )
            );
    }

    public function test_student_identity_action_does_not_change_account_recovery_email(): void
    {
        [
            $center,
            $branch,
            $student,
            $person,
        ] = $this->student();

        $account =
            $this->user(
                SystemRole::Student,
                $center,
                $person
            );

        $account->forceFill([
            'recovery_email' =>
            'recovery@example.test',
        ])->save();

        $student->user_id =
            $account->id;

        $student->save();
        $student->refresh();

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
                    'editStudentIdentity'
                )->table(
                    $student
                ),
                [
                    'full_name' =>
                    'Updated Student',

                    'date_of_birth' =>
                    '2002-04-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'new-person@example.test',

                    'phone_number' =>
                    '+970599123456',
                ]
            )
            ->assertHasNoActionErrors();

        $account->refresh();

        $this->assertSame(
            'recovery@example.test',
            $account
                ->recovery_email
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:Branch,
     *     2:Student,
     *     3:Person
     * }
     */
    private function student(): array
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
            ->create([
                'national_id_number' =>
                '123456789',

                'full_name' =>
                'Original Student',

                'date_of_birth' =>
                '2000-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'student@example.test',

                'phone_number' =>
                '+970590000000',
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $person
            )
            ->create();

        return [
            $center,
            $branch,
            $student,
            $person,
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