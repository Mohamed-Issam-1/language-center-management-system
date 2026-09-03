<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
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

class EnrollmentCreateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_enroll_student_through_filament_action(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->planned()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListEnrollments::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'enrollStudent'
                )
            )
            ->callAction(
                TestAction::make(
                    'enrollStudent'
                ),
                [
                    'student_id' =>
                    $student->id,

                    'course_class_id' =>
                    $courseClass->id,

                    'enrollment_number' =>
                    'ENR-UI-OWNER-001',

                    'enrollment_date' =>
                    now()
                        ->toDateString(),
                ]
            );

        $enrollment =
            \App\Models\Enrollment
            ::withoutGlobalScopes()
            ->where(
                'enrollment_number',
                'ENR-UI-OWNER-001'
            )
            ->firstOrFail();

        $this->assertSame(
            $center->id,
            $enrollment->center_id
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            $courseClass->id,
            $enrollment->class_id
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $enrollment->id,

                'event_type' =>
                'created',
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.created',

                'subject_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_branch_manager_can_enroll_student_inside_assigned_branch_through_filament_action(): void
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

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->planned()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListEnrollments::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'enrollStudent'
                )
            )
            ->callAction(
                TestAction::make(
                    'enrollStudent'
                ),
                [
                    'student_id' =>
                    $student->id,

                    'course_class_id' =>
                    $courseClass->id,

                    'enrollment_number' =>
                    'ENR-UI-MANAGER-001',

                    'enrollment_date' =>
                    now()
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseHas(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_number' =>
                'ENR-UI-MANAGER-001',
            ]
        );
    }

    public function test_branch_manager_cannot_tamper_target_class_outside_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $student =
            Student::factory()
            ->forBranch(
                $ownBranch
            )
            ->active()
            ->create();

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->planned()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListEnrollments::class
        )
            ->callAction(
                TestAction::make(
                    'enrollStudent'
                ),
                [
                    'student_id' =>
                    $student->id,

                    'course_class_id' =>
                    $otherClass->id,

                    'enrollment_number' =>
                    'ENR-TAMPER-BRANCH',

                    'enrollment_date' =>
                    now()
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'enrollment_number' =>
                'ENR-TAMPER-BRANCH',
            ]
        );
    }

    public function test_center_owner_cannot_tamper_resources_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $studentB =
            Student::factory()
            ->forBranch($branchB)
            ->active()
            ->create();

        $classB =
            CourseClass::factory()
            ->forBranch($branchB)
            ->planned()
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        Livewire::test(
            ListEnrollments::class
        )
            ->callAction(
                TestAction::make(
                    'enrollStudent'
                ),
                [
                    'student_id' =>
                    $studentB->id,

                    'course_class_id' =>
                    $classB->id,

                    'enrollment_number' =>
                    'ENR-TAMPER-CENTER',

                    'enrollment_date' =>
                    now()
                        ->toDateString(),
                ]
            );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'enrollment_number' =>
                'ENR-TAMPER-CENTER',
            ]
        );
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchManagerContext(
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

    private function assignBranchManager(
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
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}