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

class EnrollmentStatusActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_complete_active_enrollment_through_filament_action(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

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
                    'complete'
                )->table(
                    $enrollment
                )
            )
            ->callAction(
                TestAction::make(
                    'complete'
                )->table(
                    $enrollment
                )
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Completed,
            $enrollment
                ->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $enrollment->id,

                'event_type' =>
                'completed',

                'previous_status' =>
                EnrollmentStatus::Active
                    ->value,

                'new_status' =>
                EnrollmentStatus::Completed
                    ->value,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.completed',

                'subject_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_center_owner_can_cancel_active_enrollment_through_filament_action(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

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
                    'cancel'
                )->table(
                    $enrollment
                )
            )
            ->callAction(
                TestAction::make(
                    'cancel'
                )->table(
                    $enrollment
                )
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Cancelled,
            $enrollment
                ->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $enrollment->id,

                'event_type' =>
                'cancelled',

                'previous_status' =>
                EnrollmentStatus::Active
                    ->value,

                'new_status' =>
                EnrollmentStatus::Cancelled
                    ->value,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.cancelled',

                'subject_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_branch_manager_can_complete_enrollment_inside_assigned_branch(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

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
                    'complete'
                )->table(
                    $enrollment
                )
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Completed,
            $enrollment
                ->enrollment_status
        );
    }

    public function test_branch_manager_can_cancel_enrollment_inside_assigned_branch(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

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
                    'cancel'
                )->table(
                    $enrollment
                )
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Cancelled,
            $enrollment
                ->enrollment_status
        );
    }

    public function test_complete_and_cancel_actions_are_hidden_for_terminal_enrollment(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Withdrawn,

            'withdrawal_date' =>
            now()->toDateString(),
        ])->save();

        $enrollment->refresh();

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
                    'complete'
                )->table(
                    $enrollment
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'cancel'
                )->table(
                    $enrollment
                )
            );
    }

    public function test_branch_manager_cannot_access_status_actions_for_enrollment_outside_assigned_branch(): void
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

        $otherEnrollment =
            $this->createEnrollment(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        /*
     * The Enrollment is outside the Branch Manager's
     * operational scope, therefore Filament must remove
     * the record from the table entirely.
     *
     * A record action cannot be mounted for a record that
     * the scoped table query does not expose.
     */
        Livewire::test(
            ListEnrollments::class
        )
            ->assertCanNotSeeTableRecords([
                $otherEnrollment,
            ]);

        $this->assertFalse(
            \App\Filament\Resources\Enrollments\EnrollmentResource
                ::canView(
                    $otherEnrollment
                )
        );

        $this->assertNull(
            \App\Filament\Resources\Enrollments\EnrollmentResource
                ::resolveRecordRouteBinding(
                    $otherEnrollment
                        ->getKey()
                )
        );

        $otherEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $otherEnrollment
                ->enrollment_status
        );

        $this->assertDatabaseMissing(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $otherEnrollment->id,

                'event_type' =>
                'completed',
            ]
        );

        $this->assertDatabaseMissing(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $otherEnrollment->id,

                'event_type' =>
                'cancelled',
            ]
        );
    }

    private function createEnrollment(
        Branch $branch
    ): Enrollment {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->planned()
            ->create();

        return Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();
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