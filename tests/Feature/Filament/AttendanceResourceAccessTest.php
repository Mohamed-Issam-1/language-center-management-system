<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
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
use Tests\TestCase;

class AttendanceResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_attendance_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($centerA)
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

        $ownAttendance =
            $this->createAttendance(
                $branchA
            );

        $otherAttendance =
            $this->createAttendance(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            AttendanceResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownAttendance->id,
            $ids
        );

        $this->assertNotContains(
            $otherAttendance->id,
            $ids
        );

        $this->assertTrue(
            AttendanceResource
                ::canViewAny()
        );

        $this->assertTrue(
            AttendanceResource
                ::canView(
                    $ownAttendance
                )
        );

        $this->assertFalse(
            AttendanceResource
                ::canView(
                    $otherAttendance
                )
        );

        $this->assertNull(
            AttendanceResource
                ::resolveRecordRouteBinding(
                    $otherAttendance
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_only_attendance_from_assigned_branch(): void
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

        $ownAttendance =
            $this->createAttendance(
                $ownBranch
            );

        $otherAttendance =
            $this->createAttendance(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $ids =
            AttendanceResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownAttendance->id,
            $ids
        );

        $this->assertNotContains(
            $otherAttendance->id,
            $ids
        );

        $this->assertTrue(
            AttendanceResource
                ::canViewAny()
        );

        $this->assertTrue(
            AttendanceResource
                ::canView(
                    $ownAttendance
                )
        );

        $this->assertFalse(
            AttendanceResource
                ::canView(
                    $otherAttendance
                )
        );

        $this->assertNull(
            AttendanceResource
                ::resolveRecordRouteBinding(
                    $otherAttendance
                        ->getKey()
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed_even_with_stale_context(): void
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

        $assignment =
            $this->assignBranchManager(
                $manager,
                $branch
            );

        $attendance =
            $this->createAttendance(
                $branch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            AttendanceResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            AttendanceResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            AttendanceResource
                ::canView(
                    $attendance
                )
        );

        $this->assertNull(
            AttendanceResource
                ::resolveRecordRouteBinding(
                    $attendance
                        ->getKey()
                )
        );
    }

    public function test_other_admin_roles_cannot_access_attendance_resource(): void
    {
        $roles = [
            SystemRole::PlatformOwner,
            SystemRole::FinanceEmployee,
        ];

        foreach (
            $roles as $role
        ) {
            $center =
                Center::factory()
                ->active()
                ->create();

            $user =
                $this->createCenterUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $user
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            app(BranchContext::class)
                ->establishCenterWideScope();

            $this->assertFalse(
                AttendanceResource
                    ::canViewAny(),
                "Role {$role->value} unexpectedly accessed Attendance."
            );
        }
    }

    public function test_teacher_and_student_are_not_exposed_through_admin_attendance_resource(): void
    {
        $roles = [
            SystemRole::Teacher,
            SystemRole::Student,
        ];

        foreach (
            $roles as $role
        ) {
            $center =
                Center::factory()
                ->active()
                ->create();

            $user =
                $this->createCenterUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $user
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            $this->assertFalse(
                AttendanceResource
                    ::canViewAny()
            );
        }
    }

    public function test_native_attendance_crud_is_disabled(): void
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

        $attendance =
            $this->createAttendance(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            AttendanceResource
                ::canCreate()
        );

        $this->assertFalse(
            AttendanceResource
                ::canEdit(
                    $attendance
                )
        );

        $this->assertFalse(
            AttendanceResource
                ::canDelete(
                    $attendance
                )
        );

        $this->assertFalse(
            AttendanceResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            AttendanceResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                AttendanceResource
                    ::getPages()
            )
        );
    }

    private function createAttendance(
        Branch $branch
    ): Attendance {
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

        return Attendance::factory()
            ->forSession(
                $session
            )
            ->forEnrollment(
                $enrollment
            )
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