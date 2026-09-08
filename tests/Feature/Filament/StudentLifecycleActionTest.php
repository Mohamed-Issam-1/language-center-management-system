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
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentLifecycleActionTest
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

    public function test_center_owner_can_move_active_student_to_another_active_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            Student::factory()
            ->forBranch(
                $branchA
            )
            ->create();

        $owner =
            $this->user(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs($owner);

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'moveStudentBranch'
                )->table(
                    $student
                ),
                [
                    'branch_id' =>
                    $branchB->id,
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'students',
            [
                'id' =>
                $student->id,

                'branch_id' =>
                $branchB->id,
            ]
        );
    }

    public function test_branch_manager_does_not_receive_move_branch_action(): void
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

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->create();

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
                    'moveStudentBranch'
                )->table(
                    $student
                )
            );
    }

    public function test_center_owner_can_archive_active_student_through_filament(): void
    {
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

        $this->actingAs($owner);

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'archiveStudent'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'students',
            [
                'id' =>
                $student->id,

                'status' =>
                StudentStatus
                ::Archived
                    ->value,
            ]
        );

        $this->assertNotNull(
            Student::withoutGlobalScopes()
                ->findOrFail(
                    $student->id
                )
                ->archived_at
        );
    }

    public function test_archived_student_exposes_restore_instead_of_archive_or_move(): void
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

        $this->actingAs($owner);

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'restoreStudent'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'archiveStudent'
                )->table(
                    $student
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'moveStudentBranch'
                )->table(
                    $student
                )
            );
    }

    public function test_center_owner_can_restore_archived_student_through_filament(): void
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

        $this->actingAs($owner);

        $this->centerWide(
            $center
        );

        Livewire::test(
            ListStudents::class
        )
            ->callAction(
                TestAction::make(
                    'restoreStudent'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'students',
            [
                'id' =>
                $student->id,

                'status' =>
                StudentStatus
                ::Active
                    ->value,

                'archived_at' =>
                null,
            ]
        );
    }

    public function test_branch_manager_can_archive_student_inside_assigned_branch(): void
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
            ->callAction(
                TestAction::make(
                    'archiveStudent'
                )->table(
                    $student
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'students',
            [
                'id' =>
                $student->id,

                'status' =>
                StudentStatus
                ::Archived
                    ->value,
            ]
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
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->create([
                'status' =>
                $status,

                'archived_at' =>
                $status
                    ===
                    StudentStatus::Archived
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
                $branch
                    ->center_id,

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