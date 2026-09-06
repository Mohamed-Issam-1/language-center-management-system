<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentTransferActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_transfer_enrollment_to_another_branch_through_filament_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $sourceBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $targetBranch =
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
            ->forBranch(
                $sourceBranch
            )
            ->active()
            ->create();

        $sourceClass =
            CourseClass::factory()
            ->forBranch(
                $sourceBranch
            )
            ->planned()
            ->create();

        $targetClass =
            CourseClass::factory()
            ->forBranch(
                $targetBranch
            )
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $sourceClass
            )
            ->active()
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
                    'transfer'
                )->table(
                    $sourceEnrollment
                )
            )
            ->callAction(
                TestAction::make(
                    'transfer'
                )->table(
                    $sourceEnrollment
                ),
                [
                    'target_class_id' =>
                    $targetClass->id,

                    'enrollment_number' =>
                    'ENR-TRANSFER-OWNER-001',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Transferred,
            $sourceEnrollment
                ->enrollment_status
        );

        $targetEnrollment =
            Enrollment::withoutGlobalScopes()
            ->where(
                'enrollment_number',
                'ENR-TRANSFER-OWNER-001'
            )
            ->firstOrFail();

        $this->assertSame(
            EnrollmentStatus::Active,
            $targetEnrollment
                ->enrollment_status
        );

        $this->assertSame(
            $student->id,
            $targetEnrollment->student_id
        );

        $this->assertSame(
            $targetClass->id,
            $targetEnrollment->class_id
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $sourceEnrollment->id,

                'event_type' =>
                'transfer',

                'previous_status' =>
                EnrollmentStatus::Active
                    ->value,

                'new_status' =>
                EnrollmentStatus::Transferred
                    ->value,

                'from_class_id' =>
                $sourceClass->id,

                'to_class_id' =>
                $targetClass->id,
            ]
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $targetEnrollment->id,

                'event_type' =>
                'created',

                'new_status' =>
                EnrollmentStatus::Active
                    ->value,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.transferred',

                'subject_id' =>
                $sourceEnrollment->id,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.created',

                'subject_id' =>
                $targetEnrollment->id,
            ]
        );
    }

    public function test_branch_manager_can_transfer_inside_assigned_branch(): void
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

        $sourceClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->planned()
            ->create();

        $targetClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->active()
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
            ->callAction(
                TestAction::make(
                    'transfer'
                )->table(
                    $sourceEnrollment
                ),
                [
                    'target_class_id' =>
                    $targetClass->id,

                    'enrollment_number' =>
                    'ENR-TRANSFER-MANAGER-001',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Transferred,
            $sourceEnrollment
                ->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $targetClass->id,

                'enrollment_number' =>
                'ENR-TRANSFER-MANAGER-001',

                'enrollment_status' =>
                EnrollmentStatus::Active
                    ->value,
            ]
        );
    }

    public function test_branch_manager_cannot_tamper_transfer_target_outside_assigned_branch(): void
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

        $sourceClass =
            CourseClass::factory()
            ->forBranch(
                $ownBranch
            )
            ->planned()
            ->create();

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->active()
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
                    'transfer'
                )->table(
                    $sourceEnrollment
                ),
                [
                    'target_class_id' =>
                    $otherClass->id,

                    'enrollment_number' =>
                    'ENR-TRANSFER-TAMPER-BRANCH',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $sourceEnrollment
                ->enrollment_status
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'enrollment_number' =>
                'ENR-TRANSFER-TAMPER-BRANCH',
            ]
        );
    }

    public function test_center_owner_cannot_tamper_transfer_target_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $sourceBranch =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $student =
            Student::factory()
            ->forBranch(
                $sourceBranch
            )
            ->active()
            ->create();

        $sourceClass =
            CourseClass::factory()
            ->forBranch(
                $sourceBranch
            )
            ->planned()
            ->create();

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $sourceClass
            )
            ->active()
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
                    'transfer'
                )->table(
                    $sourceEnrollment
                ),
                [
                    'target_class_id' =>
                    $otherClass->id,

                    'enrollment_number' =>
                    'ENR-TRANSFER-TAMPER-CENTER',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $sourceEnrollment
                ->enrollment_status
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'enrollment_number' =>
                'ENR-TRANSFER-TAMPER-CENTER',
            ]
        );
    }

    public function test_transfer_action_is_hidden_for_terminal_enrollment(): void
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

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->completed()
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
            ->assertActionHidden(
                TestAction::make(
                    'transfer'
                )->table(
                    $enrollment
                )
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