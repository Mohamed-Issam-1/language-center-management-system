<?php

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Teachers\TeacherAttendanceReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAttendanceReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_assigned_teacher_receives_session_roster_and_existing_attendance(): void
    {
        $context =
            $this->teacherContext(
                'Attendance Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $studentPerson =
            Person::factory()
            ->for(
                $context['center']
            )
            ->create([
                'full_name' =>
                'Attendance Student',
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $courseClass->branch
            )
            ->forPerson(
                $studentPerson
            )
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-ATT-001',

                'enrollment_date' =>
                '2026-09-01',
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->active()
            ->create([
                'code' =>
                'PRESENT',

                'name' =>
                'Present',

                'contribution_value' =>
                100,
            ]);

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
                $context['user']
            )
            ->create([
                'late_minutes' =>
                5,

                'excuse' =>
                'Transport delay',

                'notes' =>
                'Arrived shortly after start',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $this->assertSame(
            $context['teacher']->id,
            $result['teacher']['id']
        );

        $this->assertSame(
            $session->id,
            $result['session']['session_id']
        );

        $this->assertSame(
            $courseClass->id,
            $result['session']['class_id']
        );

        $this->assertCount(
            1,
            $result['roster']
        );

        $record =
            $result['roster'][0];

        $this->assertSame(
            $enrollment->id,
            $record['enrollment_id']
        );

        $this->assertSame(
            'ENR-ATT-001',
            $record['enrollment_number']
        );

        $this->assertSame(
            'Attendance Student',
            $record['student']['name']
        );

        $this->assertNotNull(
            $record['attendance']
        );

        $this->assertSame(
            $attendance->id,
            $record['attendance']['attendance_id']
        );

        $this->assertSame(
            $status->id,
            $record['attendance']['attendance_status']['id']
        );

        $this->assertSame(
            'PRESENT',
            $record['attendance']['attendance_status']['code']
        );

        $this->assertSame(
            5,
            $record['attendance']['late_minutes']
        );

        $this->assertSame(
            'Transport delay',
            $record['attendance']['excuse']
        );

        $this->assertSame(
            'Arrived shortly after start',
            $record['attendance']['notes']
        );

        $this->assertNotNull(
            $record['attendance']['recorded_at']
        );

        $this->assertNotNull(
            $record['attendance']['updated_at']
        );
    }

    public function test_roster_contains_enrollments_without_attendance_as_null(): void
    {
        $context =
            $this->teacherContext(
                'Null Attendance Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $student =
            $this->student(
                $context['center'],
                $courseClass->branch,
                'No Attendance Student'
            );

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $this->assertCount(
            1,
            $result['roster']
        );

        $this->assertSame(
            $enrollment->id,
            $result['roster'][0]['enrollment_id']
        );

        $this->assertNull(
            $result['roster'][0]['attendance']
        );
    }

    public function test_session_roster_contains_all_persisted_enrollment_lifecycle_states(): void
    {
        $context =
            $this->teacherContext(
                'Lifecycle Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $states = [
            EnrollmentStatus::Active,
            EnrollmentStatus::Completed,
            EnrollmentStatus::Withdrawn,
            EnrollmentStatus::Transferred,
            EnrollmentStatus::Cancelled,
        ];

        foreach (
            $states as $index => $status
        ) {
            $student =
                $this->student(
                    $context['center'],
                    $courseClass->branch,
                    'Lifecycle Student '
                        . ($index + 1)
                );

            $factory =
                Enrollment::factory()
                ->forStudent(
                    $student
                )
                ->forCourseClass(
                    $courseClass
                );

            $factory =
                match ($status) {
                    EnrollmentStatus::Active =>
                    $factory->active(),

                    EnrollmentStatus::Completed =>
                    $factory->completed(),

                    EnrollmentStatus::Withdrawn =>
                    $factory->withdrawn(),

                    EnrollmentStatus::Transferred =>
                    $factory->transferred(),

                    EnrollmentStatus::Cancelled =>
                    $factory->cancelled(),
                };

            $factory->create([
                'enrollment_date' =>
                '2026-09-01',
            ]);
        }

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $this->assertCount(
            5,
            $result['roster']
        );

        $actualStatuses =
            collect(
                $result['roster']
            )
            ->pluck(
                'enrollment_status'
            )
            ->sort()
            ->values()
            ->all();

        $expectedStatuses =
            collect(
                $states
            )
            ->map(
                fn(
                    EnrollmentStatus $status
                ): string =>
                $status->value
            )
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $expectedStatuses,
            $actualStatuses
        );
    }

    public function test_only_active_center_attendance_statuses_are_offered_for_future_writes(): void
    {
        $context =
            $this->teacherContext(
                'Status Teacher'
            );

        [,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $active =
            AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->active()
            ->create([
                'code' =>
                'ACTIVE-STATUS',

                'name' =>
                'Active Status',
            ]);

        $inactive =
            AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->inactive()
            ->create([
                'code' =>
                'INACTIVE-STATUS',

                'name' =>
                'Inactive Status',
            ]);

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $otherCenterStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $otherCenter
            )
            ->active()
            ->create([
                'code' =>
                'OTHER-CENTER',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $ids =
            collect(
                $result['attendance_statuses']
            )
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $active->id,
            $ids
        );

        $this->assertNotContains(
            $inactive->id,
            $ids
        );

        $this->assertNotContains(
            $otherCenterStatus->id,
            $ids
        );
    }

    public function test_existing_historical_attendance_preserves_inactive_status(): void
    {
        $context =
            $this->teacherContext(
                'Historical Status Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $student =
            $this->student(
                $context['center'],
                $courseClass->branch,
                'Historical Attendance Student'
            );

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->completed()
            ->create();

        $historicalStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->inactive()
            ->create([
                'code' =>
                'OLD-PRESENT',

                'name' =>
                'Old Present',

                'contribution_value' =>
                100,
            ]);

        Attendance::factory()
            ->forSession(
                $session
            )
            ->forEnrollment(
                $enrollment
            )
            ->withStatus(
                $historicalStatus
            )
            ->recordedBy(
                $context['user']
            )
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $attendance =
            $result['roster'][0]['attendance'];

        $this->assertNotNull(
            $attendance
        );

        $this->assertSame(
            $historicalStatus->id,
            $attendance['attendance_status']['id']
        );

        $this->assertSame(
            'OLD-PRESENT',
            $attendance['attendance_status']['code']
        );

        $this->assertFalse(
            $attendance['attendance_status']['is_active']
        );

        $this->assertNotContains(
            $historicalStatus->id,
            collect(
                $result['attendance_statuses']
            )
                ->pluck(
                    'id'
                )
                ->all()
        );
    }

    public function test_concrete_session_teacher_assignment_is_authoritative_for_attendance_read_scope(): void
    {
        $primary =
            $this->teacherContext(
                'Primary Teacher'
            );

        $substitute =
            $this->teacherContext(
                'Substitute Teacher',
                $primary['center']
            );

        $branch =
            $this->branch(
                $primary['center']
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $primary['teacher']
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
            ->create([
                'teacher_id' =>
                $substitute['teacher']->id,
            ]);

        $student =
            $this->student(
                $primary['center'],
                $branch,
                'Substitute Session Student'
            );

        Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $primary['center']
        );

        $result =
            $this->service()
            ->forSession(
                $substitute['user'],
                $session->id
            );

        $this->assertSame(
            $substitute['teacher']->id,
            $result['teacher']['id']
        );

        $this->assertSame(
            $session->id,
            $result['session']['session_id']
        );

        $this->assertCount(
            1,
            $result['roster']
        );
    }

    public function test_default_class_teacher_cannot_read_session_reassigned_to_another_teacher(): void
    {
        $primary =
            $this->teacherContext(
                'Default Teacher'
            );

        $substitute =
            $this->teacherContext(
                'Assigned Session Teacher',
                $primary['center']
            );

        $branch =
            $this->branch(
                $primary['center']
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $primary['teacher']
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
            ->create([
                'teacher_id' =>
                $substitute['teacher']->id,
            ]);

        $this->establishCenterContext(
            $primary['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $primary['user'],
                $session->id
            );
    }

    public function test_teacher_cannot_read_another_teachers_session(): void
    {
        $context =
            $this->teacherContext(
                'Teacher One'
            );

        $other =
            $this->teacherContext(
                'Teacher Two',
                $context['center']
            );

        [,,
            $otherSession,
        ] = $this->sessionForTeacher(
            $other
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $context['user'],
                $otherSession->id
            );
    }

    public function test_teacher_cannot_read_cross_center_session(): void
    {
        $context =
            $this->teacherContext(
                'Center One Teacher'
            );

        $other =
            $this->teacherContext(
                'Center Two Teacher'
            );

        [,,
            $otherSession,
        ] = $this->sessionForTeacher(
            $other
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $context['user'],
                $otherSession->id
            );
    }

    public function test_cancelled_assigned_session_remains_readable_as_historical_information(): void
    {
        $context =
            $this->teacherContext(
                'Cancelled Session Teacher'
            );

        [,,
            $cancelled,
        ] = $this->sessionForTeacher(
            $context
        );

        /*
     * Reuse the already persisted Session created by the fixture.
     *
     * Creating another Session for the same Schedule on the same
     * date would violate the authoritative
     * schedule/date uniqueness constraint.
     */
        $cancelled
            ->forceFill([
                'session_status' =>
                \App\Support\Enums\ClassSessionStatus::Cancelled,

                'cancellation_reason' =>
                'Weather closure',
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $cancelled->id
            );

        $this->assertSame(
            $cancelled->id,
            $result['session']['session_id']
        );

        $this->assertSame(
            'cancelled',
            $result['session']['status']
        );

        $this->assertSame(
            'Weather closure',
            $result['session']['cancellation_reason']
        );
    }

    public function test_teacher_attendance_roster_does_not_expose_sensitive_student_data(): void
    {
        $context =
            $this->teacherContext(
                'Privacy Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $person =
            Person::factory()
            ->for(
                $context['center']
            )
            ->create([
                'full_name' =>
                'Private Student',

                'national_id_number' =>
                'PRIVATE-NATIONAL-777',

                'email' =>
                'private-attendance@example.test',

                'phone_number' =>
                '+970598887777',

                'personal_picture_path' =>
                'private/attendance/student.jpg',
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $courseClass->branch
            )
            ->forPerson(
                $person
            )
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $studentPayload =
            $result['roster'][0]['student'];

        $this->assertSame(
            'Private Student',
            $studentPayload['name']
        );

        $this->assertArrayNotHasKey(
            'national_id_number',
            $studentPayload
        );

        $this->assertArrayNotHasKey(
            'email',
            $studentPayload
        );

        $this->assertArrayNotHasKey(
            'phone_number',
            $studentPayload
        );

        $this->assertArrayNotHasKey(
            'personal_picture_path',
            $studentPayload
        );

        $this->assertArrayNotHasKey(
            'user_id',
            $studentPayload
        );

        $serialized =
            json_encode(
                $result,
                JSON_THROW_ON_ERROR
            );

        $this->assertStringNotContainsString(
            'PRIVATE-NATIONAL-777',
            $serialized
        );

        $this->assertStringNotContainsString(
            'private-attendance@example.test',
            $serialized
        );

        $this->assertStringNotContainsString(
            '+970598887777',
            $serialized
        );

        $this->assertStringNotContainsString(
            'private/attendance/student.jpg',
            $serialized
        );
    }

    public function test_empty_class_roster_returns_explicit_empty_array(): void
    {
        $context =
            $this->teacherContext(
                'Empty Roster Teacher'
            );

        [,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $this->assertSame(
            [],
            $result['roster']
        );
    }

    public function test_deactivated_teacher_record_cannot_read_teacher_attendance(): void
    {
        $context =
            $this->teacherContext(
                'Deactivated Attendance Teacher'
            );

        [,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $context['teacher']
            ->forceFill([
                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' =>
                now(),
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );
    }

    public function test_teacher_attendance_read_uses_persisted_user_account_state(): void
    {
        $context =
            $this->teacherContext(
                'Persisted Attendance Teacher'
            );

        [,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        User::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $context['user']->id
            )
            ->update([
                'status' =>
                AccountStatus::Deactivated->value,

                'deactivated_at' =>
                now(),
            ]);

        /*
         * Intentionally stale in-memory authenticated User.
         */
        $this->assertSame(
            AccountStatus::Active,
            $context['user']->status
        );

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );
    }

    public function test_teacher_attendance_read_fails_closed_on_tenant_mismatch(): void
    {
        $context =
            $this->teacherContext(
                'Tenant Attendance Teacher'
            );

        [,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $this->establishCenterContext(
            $otherCenter
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );
    }

    public function test_teacher_attendance_read_is_side_effect_free_and_creates_no_audit_records(): void
    {
        $context =
            $this->teacherContext(
                'Read Only Teacher'
            );

        [
            $courseClass,,
            $session,
        ] = $this->sessionForTeacher(
            $context
        );

        $student =
            $this->student(
                $context['center'],
                $courseClass->branch,
                'Read Only Student'
            );

        Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->active()
            ->create();

        $attendanceCountBefore =
            Attendance::query()
            ->withoutGlobalScopes()
            ->count();

        $auditCountBefore =
            AuditRecord::query()
            ->withoutGlobalScopes()
            ->count();

        $this->establishCenterContext(
            $context['center']
        );

        $this->service()
            ->forSession(
                $context['user'],
                $session->id
            );

        $this->assertSame(
            $attendanceCountBefore,
            Attendance::query()
                ->withoutGlobalScopes()
                ->count()
        );

        $this->assertSame(
            $auditCountBefore,
            AuditRecord::query()
                ->withoutGlobalScopes()
                ->count()
        );
    }

    /**
     * @return array{
     *     center: Center,
     *     person: Person,
     *     user: User,
     *     teacher: Teacher
     * }
     */
    private function teacherContext(
        string $name,
        ?Center $center = null
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for(
                $center
            )
            ->create([
                'full_name' =>
                $name,
            ]);

        $user =
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
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->active()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        return [
            'center' =>
            $center,

            'person' =>
            $person,

            'user' =>
            $user,

            'teacher' =>
            $teacher,
        ];
    }

    /**
     * @param array{
     *     center: Center,
     *     person: Person,
     *     user: User,
     *     teacher: Teacher
     * } $context
     *
     * @return array{
     *     0: CourseClass,
     *     1: ClassSchedule,
     *     2: ClassSession
     * }
     */
    private function sessionForTeacher(
        array $context
    ): array {
        $branch =
            $this->branch(
                $context['center']
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $context['teacher']
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

        return [
            $courseClass,
            $schedule,
            $session,
        ];
    }

    private function student(
        Center $center,
        Branch $branch,
        string $name
    ): Student {
        $person =
            Person::factory()
            ->for(
                $center
            )
            ->create([
                'full_name' =>
                $name,
            ]);

        return Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $person
            )
            ->active()
            ->create();
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->for(
                $center
            )
            ->active()
            ->create();
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
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

    private function service(): TeacherAttendanceReadService
    {
        return app(
            TeacherAttendanceReadService::class
        );
    }
}