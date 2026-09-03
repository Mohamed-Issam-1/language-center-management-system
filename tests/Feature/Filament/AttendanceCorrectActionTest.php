<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceCorrectActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_branch_manager_can_correct_attendance_inside_assigned_branch_and_original_recording_identity_is_preserved(): void
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

        $originalRecorder =
            $this->createCenterUser(
                SystemRole::Teacher,
                $center
            );

        [
            $attendance,
            $session,
            $enrollment,
            $oldStatus,
        ] = $this->createAttendance(
            $branch,
            $originalRecorder
        );

        $newStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                'Late',

                'code' =>
                'LATE',

                'contribution_value' =>
                75,
            ]);

        $originalRecorderId =
            $attendance
            ->recorded_by_user_id;

        $originalRecordedAt =
            $attendance
            ->recorded_at
            ->toDateTimeString();

        $originalCenterId =
            $attendance->center_id;

        $originalSessionId =
            $attendance->session_id;

        $originalEnrollmentId =
            $attendance->enrollment_id;

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $action =
            TestAction::make(
                'correctAttendance'
            )->table(
                $attendance
            );

        Livewire::test(
            ListAttendances::class
        )
            ->assertActionVisible(
                $action
            )
            ->callAction(
                $action,
                [
                    'attendance_status_id' =>
                    $newStatus->id,

                    'late_minutes' =>
                    15,

                    'excuse' =>
                    'Approved excuse.',

                    'notes' =>
                    'Corrected by Branch Manager.',
                ]
            )
            ->assertHasNoActionErrors();

        $attendance->refresh();

        $this->assertSame(
            $newStatus->id,
            $attendance
                ->attendance_status_id
        );

        $this->assertSame(
            15,
            $attendance->late_minutes
        );

        $this->assertSame(
            'Approved excuse.',
            $attendance->excuse
        );

        $this->assertSame(
            'Corrected by Branch Manager.',
            $attendance->notes
        );

        $this->assertSame(
            $originalRecorderId,
            $attendance
                ->recorded_by_user_id
        );

        $this->assertSame(
            $originalRecordedAt,
            $attendance
                ->recorded_at
                ->toDateTimeString()
        );

        $this->assertSame(
            $originalCenterId,
            $attendance->center_id
        );

        $this->assertSame(
            $originalSessionId,
            $attendance->session_id
        );

        $this->assertSame(
            $originalEnrollmentId,
            $attendance->enrollment_id
        );

        $this->assertSame(
            $session->id,
            $attendance->session_id
        );

        $this->assertSame(
            $enrollment->id,
            $attendance->enrollment_id
        );

        $this->assertNotSame(
            $oldStatus->id,
            $attendance
                ->attendance_status_id
        );
    }

    public function test_center_owner_does_not_receive_correct_attendance_action(): void
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

        [
            $attendance,
        ] = $this->createAttendance(
            $branch,
            $owner
        );

        $this->actingAs(
            $owner
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        Livewire::test(
            ListAttendances::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'correctAttendance'
                )->table(
                    $attendance
                )
            );
    }

    public function test_inactive_status_cannot_replace_current_attendance_status(): void
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

        [
            $attendance,
        ] = $this->createAttendance(
            $branch,
            $manager
        );

        $originalStatusId =
            $attendance
            ->attendance_status_id;

        $inactiveStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->inactive()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListAttendances::class
        )
            ->callAction(
                TestAction::make(
                    'correctAttendance'
                )->table(
                    $attendance
                ),
                [
                    'attendance_status_id' =>
                    $inactiveStatus->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    'Should not be saved.',
                ]
            );

        $attendance->refresh();

        $this->assertSame(
            $originalStatusId,
            $attendance
                ->attendance_status_id
        );

        $this->assertNotSame(
            'Should not be saved.',
            $attendance->notes
        );
    }

    public function test_correct_attendance_action_is_hidden_for_cancelled_session(): void
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

        [
            $attendance,
            $session,
        ] = $this->createAttendance(
            $branch,
            $manager
        );

        $session->forceFill([
            'session_status' =>
            ClassSessionStatus::Cancelled,

            'cancellation_reason' =>
            'Cancelled after Attendance was recorded.',
        ])->save();

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListAttendances::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'correctAttendance'
                )->table(
                    $attendance
                )
            );
    }

    public function test_historical_withdrawn_enrollment_attendance_remains_correctable(): void
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

        [
            $attendance,,
            $enrollment,
            $status,
        ] = $this->createAttendance(
            $branch,
            $manager
        );

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Withdrawn,

            'withdrawal_date' =>
            now()->toDateString(),
        ])->save();

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $action =
            TestAction::make(
                'correctAttendance'
            )->table(
                $attendance
            );

        Livewire::test(
            ListAttendances::class
        )
            ->assertActionVisible(
                $action
            )
            ->callAction(
                $action,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    $attendance
                        ->late_minutes,

                    'excuse' =>
                    $attendance->excuse,

                    'notes' =>
                    'Historical correction.',
                ]
            )
            ->assertHasNoActionErrors();

        $attendance->refresh();

        $this->assertSame(
            'Historical correction.',
            $attendance->notes
        );

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $enrollment
                ->fresh()
                ->enrollment_status
        );
    }

    public function test_correct_attendance_rejects_fractional_late_minutes(): void
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

        [
            $attendance,,,
            $status,
        ] = $this->createAttendance(
            $branch,
            $manager
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListAttendances::class
        )
            ->callAction(
                TestAction::make(
                    'correctAttendance'
                )->table(
                    $attendance
                ),
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    1.5,

                    'excuse' =>
                    null,

                    'notes' =>
                    'Must not be saved.',
                ]
            )
            ->assertHasActionErrors([
                'late_minutes',
            ]);

        $attendance->refresh();

        $this->assertSame(
            0,
            $attendance->late_minutes
        );

        $this->assertNull(
            $attendance->notes
        );
    }

    /**
     * @return array{
     *     0: Attendance,
     *     1: ClassSession,
     *     2: Enrollment,
     *     3: AttendanceStatus
     * }
     */
    private function createAttendance(
        Branch $branch,
        User $recorder
    ): array {
        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $session =
            ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->active()
            ->create([
                'center_id' =>
                $branch->center_id,

                'class_id' =>
                $courseClass->id,

                'enrollment_date' =>
                $session
                    ->session_date
                    ->toDateString(),
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $branch->center
            )
            ->active()
            ->create();

        $attendance =
            Attendance::factory()
            ->forSession(
                $session
            )
            ->forEnrollment(
                $enrollment
            )
            ->withStatus(
                $status
            )
            ->recordedBy(
                $recorder
            )
            ->create([
                'late_minutes' =>
                0,

                'excuse' =>
                null,

                'notes' =>
                null,
            ]);

        return [
            $attendance,
            $session,
            $enrollment,
            $status,
        ];
    }

    private function establishBranchContext(
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
            ->for(
                $center
            )
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