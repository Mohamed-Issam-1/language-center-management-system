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

class EnrollmentWithdrawActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_withdraw_active_enrollment_through_filament_action(): void
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
                    'withdraw'
                )->table(
                    $enrollment
                )
            )
            ->callAction(
                TestAction::make(
                    'withdraw'
                )->table(
                    $enrollment
                ),
                [
                    'reason' =>
                    'Student requested withdrawal.',
                ]
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $enrollment
                ->enrollment_status
        );

        $this->assertSame(
            'Student requested withdrawal.',
            $enrollment
                ->withdrawal_reason
        );

        $this->assertNotNull(
            $enrollment
                ->withdrawal_date
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $enrollment->id,

                'event_type' =>
                'withdrawn',

                'previous_status' =>
                EnrollmentStatus::Active
                    ->value,

                'new_status' =>
                EnrollmentStatus::Withdrawn
                    ->value,

                'notes' =>
                'Student requested withdrawal.',
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'enrollment.withdrawn',

                'subject_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_branch_manager_can_withdraw_enrollment_inside_assigned_branch(): void
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
            ->assertActionVisible(
                TestAction::make(
                    'withdraw'
                )->table(
                    $enrollment
                )
            )
            ->callAction(
                TestAction::make(
                    'withdraw'
                )->table(
                    $enrollment
                ),
                [
                    'reason' =>
                    'Branch withdrawal.',
                ]
            );

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $enrollment
                ->enrollment_status
        );

        $this->assertSame(
            'Branch withdrawal.',
            $enrollment
                ->withdrawal_reason
        );
    }

    public function test_withdraw_action_is_hidden_for_terminal_enrollment(): void
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
            EnrollmentStatus::Completed,
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
                    'withdraw'
                )->table(
                    $enrollment
                )
            );
    }

    public function test_withdrawal_reason_cannot_exceed_255_characters_in_filament_action(): void
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
            ->callAction(
                TestAction::make(
                    'withdraw'
                )->table(
                    $enrollment
                ),
                [
                    'reason' =>
                    str_repeat(
                        'A',
                        256
                    ),
                ]
            )
            ->assertHasActionErrors([
                'reason' =>
                'max',
            ]);

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment
                ->enrollment_status
        );

        $this->assertNull(
            $enrollment
                ->withdrawal_date
        );

        $this->assertDatabaseMissing(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $enrollment->id,

                'event_type' =>
                'withdrawn',
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