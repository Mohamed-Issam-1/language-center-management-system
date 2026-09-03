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
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceRecordActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_branch_manager_can_record_attendance_inside_assigned_branch(): void
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
            $session,
            $enrollment,
        ] = $this->createSessionAndEnrollment(
            $branch
        );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

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
            ->assertActionVisible(
                'recordAttendance'
            )
            ->callAction(
                'recordAttendance',
                data: [
                    'session_id' =>
                    $session->id,

                    'enrollment_id' =>
                    $enrollment->id,

                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    5,

                    'excuse' =>
                    'Traffic delay',

                    'notes' =>
                    'Arrived shortly after start.',
                ]
            )
            ->assertHasNoActionErrors();

        $attendance =
            Attendance::query()
            ->withoutGlobalScopes()
            ->sole();

        $this->assertSame(
            $center->id,
            $attendance->center_id
        );

        $this->assertSame(
            $session->id,
            $attendance->session_id
        );

        $this->assertSame(
            $enrollment->id,
            $attendance->enrollment_id
        );

        $this->assertSame(
            $status->id,
            $attendance
                ->attendance_status_id
        );

        $this->assertSame(
            $manager->id,
            $attendance
                ->recorded_by_user_id
        );

        $this->assertSame(
            5,
            $attendance->late_minutes
        );

        $this->assertSame(
            'Traffic delay',
            $attendance->excuse
        );

        $this->assertSame(
            'Arrived shortly after start.',
            $attendance->notes
        );

        $this->assertNotNull(
            $attendance->recorded_at
        );
    }

    public function test_center_owner_does_not_receive_record_attendance_action(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
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
                'recordAttendance'
            );

        $this->assertSame(
            0,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_branch_manager_cannot_tamper_session_from_another_branch(): void
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

        [,
            $ownEnrollment,
        ] = $this->createSessionAndEnrollment(
            $ownBranch
        );

        [
            $otherSession,
        ] = $this->createSessionAndEnrollment(
            $otherBranch
        );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListAttendances::class
        )
            ->callAction(
                'recordAttendance',
                data: [
                    'session_id' =>
                    $otherSession->id,

                    'enrollment_id' =>
                    $ownEnrollment->id,

                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            );

        $this->assertSame(
            0,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_branch_manager_cannot_tamper_enrollment_from_another_class(): void
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
            $session,
        ] = $this->createSessionAndEnrollment(
            $branch
        );

        [,
            $otherEnrollment,
        ] = $this->createSessionAndEnrollment(
            $branch
        );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
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
                'recordAttendance',
                data: [
                    'session_id' =>
                    $session->id,

                    'enrollment_id' =>
                    $otherEnrollment->id,

                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            );

        $this->assertSame(
            0,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_inactive_attendance_status_cannot_be_tampered_into_record_action(): void
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
            $session,
            $enrollment,
        ] = $this->createSessionAndEnrollment(
            $branch
        );

        $status =
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
                'recordAttendance',
                data: [
                    'session_id' =>
                    $session->id,

                    'enrollment_id' =>
                    $enrollment->id,

                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            );

        $this->assertSame(
            0,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    public function test_record_attendance_rejects_fractional_late_minutes(): void
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
            $session,
            $enrollment,
        ] = $this->createSessionAndEnrollment(
            $branch
        );

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
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
                'recordAttendance',
                data: [
                    'session_id' =>
                    $session->id,

                    'enrollment_id' =>
                    $enrollment->id,

                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    1.5,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            )
            ->assertHasActionErrors([
                'late_minutes',
            ]);

        $this->assertSame(
            0,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    /**
     * @return array{0: ClassSession, 1: Enrollment}
     */
    private function createSessionAndEnrollment(
        Branch $branch
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

        return [
            $session,
            $enrollment,
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