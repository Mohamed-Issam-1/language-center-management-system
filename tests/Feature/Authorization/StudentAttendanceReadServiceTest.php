<?php

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAttendanceReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAttendanceReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_overview_returns_own_attendance_records_and_authoritative_summary(): void
    {
        $context =
            $this->studentContext(
                'Attendance Student'
            );

        $academic =
            $this->academicContext(
                $context['student'],
                $context['branch'],
                'English Intermediate',
                'ENG-B2',
                'ENG-B2-01',
                60
            );

        $present =
            $this->attendanceStatus(
                $context['center'],
                'PRESENT',
                100
            );

        $late =
            $this->attendanceStatus(
                $context['center'],
                'LATE',
                50
            );

        $sessionOne =
            $this->makeSession(
                $academic['schedule'],
                '2026-09-01'
            );

        $sessionTwo =
            $this->makeSession(
                $academic['schedule'],
                '2026-09-02'
            );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $attendanceOne =
            $this->attendance(
                $sessionOne,
                $academic['enrollment'],
                $present,
                $recorder
            );

        $attendanceTwo =
            $this->attendance(
                $sessionTwo,
                $academic['enrollment'],
                $late,
                $recorder,
                10
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            'Attendance Student',
            $result['student']['name']
        );

        $this->assertSame(
            1,
            $result['summary']['enrollment_count']
        );

        $this->assertSame(
            2,
            $result['summary']['recorded_sessions']
        );

        $this->assertSame(
            '1.50',
            $result['summary']['attendance_equivalent']
        );

        $this->assertSame(
            '0.50',
            $result['summary']['absence_equivalent']
        );

        $this->assertSame(
            '75.00',
            $result['summary']['attendance_percentage']
        );

        $this->assertSame(
            '25.00',
            $result['summary']['absence_percentage']
        );

        $this->assertCount(
            1,
            $result['enrollments']
        );

        $enrollment =
            $result['enrollments'][0];

        $this->assertSame(
            $academic['enrollment']->id,
            $enrollment['enrollment_id']
        );

        $this->assertSame(
            '75.00',
            $enrollment['summary']['attendance_percentage']
        );

        $this->assertSame(
            '60.00',
            $enrollment['summary']['minimum_attendance']
        );

        $this->assertTrue(
            $enrollment['summary']['meets_minimum_attendance']
        );

        $this->assertCount(
            2,
            $result['records']
        );

        /*
         * Student Attendance history is newest-first.
         */
        $this->assertSame(
            [
                $attendanceTwo->id,
                $attendanceOne->id,
            ],
            collect(
                $result['records']
            )
                ->pluck(
                    'attendance_id'
                )
                ->all()
        );

        $newest =
            $result['records'][0];

        $this->assertSame(
            '2026-09-02',
            $newest['session_date']
        );

        $this->assertSame(
            'LATE',
            $newest['attendance_status']['code']
        );

        $this->assertSame(
            '50.00',
            $newest['attendance_status']['contribution_value']
        );

        $this->assertSame(
            10,
            $newest['late_minutes']
        );

        $this->assertSame(
            $academic['course']->id,
            $newest['course']['id']
        );

        $this->assertSame(
            $academic['class']->id,
            $newest['class']['id']
        );
    }

    public function test_overview_aggregates_active_and_historical_owned_enrollments(): void
    {
        $context =
            $this->studentContext(
                'Historical Attendance Student'
            );

        $activeAcademic =
            $this->academicContext(
                $context['student'],
                $context['branch'],
                'English Intermediate',
                'ENG-B2',
                'ENG-B2-ACTIVE'
            );

        $historicalAcademic =
            $this->academicContext(
                $context['student'],
                $context['branch'],
                'French Beginner',
                'FRE-A1',
                'FRE-A1-COMPLETED'
            );

        $historicalAcademic['enrollment']
            ->forceFill([
                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ])
            ->save();

        $present =
            $this->attendanceStatus(
                $context['center'],
                'PRESENT_HISTORY',
                100
            );

        $absent =
            $this->attendanceStatus(
                $context['center'],
                'ABSENT_HISTORY',
                0
            );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $this->attendance(
            $this->makeSession(
                $activeAcademic['schedule'],
                '2026-09-03'
            ),
            $activeAcademic['enrollment'],
            $present,
            $recorder
        );

        $this->attendance(
            $this->makeSession(
                $historicalAcademic['schedule'],
                '2026-08-20'
            ),
            $historicalAcademic['enrollment'],
            $absent,
            $recorder
        );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            2,
            $result['summary']['enrollment_count']
        );

        $this->assertSame(
            2,
            $result['summary']['recorded_sessions']
        );

        $this->assertSame(
            '1.00',
            $result['summary']['attendance_equivalent']
        );

        $this->assertSame(
            '1.00',
            $result['summary']['absence_equivalent']
        );

        $this->assertSame(
            '50.00',
            $result['summary']['attendance_percentage']
        );

        $this->assertCount(
            2,
            $result['records']
        );

        $statuses =
            collect(
                $result['enrollments']
            )
            ->pluck(
                'status'
            )
            ->all();

        $this->assertContains(
            EnrollmentStatus::Active->value,
            $statuses
        );

        $this->assertContains(
            EnrollmentStatus::Completed->value,
            $statuses
        );
    }

    public function test_overview_does_not_include_another_students_attendance_in_same_center(): void
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

        $studentA =
            $this->studentContext(
                'Attendance Student A',
                $center,
                $branch
            );

        $studentB =
            $this->studentContext(
                'Attendance Student B',
                $center,
                $branch
            );

        $academicA =
            $this->academicContext(
                $studentA['student'],
                $branch,
                'English',
                'ENG-A',
                'ENG-A-01'
            );

        $academicB =
            $this->academicContext(
                $studentB['student'],
                $branch,
                'German',
                'GER-B',
                'GER-B-01'
            );

        $status =
            $this->attendanceStatus(
                $center,
                'PRESENT_SCOPE',
                100
            );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $ownAttendance =
            $this->attendance(
                $this->makeSession(
                    $academicA['schedule'],
                    '2026-09-04'
                ),
                $academicA['enrollment'],
                $status,
                $recorder
            );

        $otherAttendance =
            $this->attendance(
                $this->makeSession(
                    $academicB['schedule'],
                    '2026-09-05'
                ),
                $academicB['enrollment'],
                $status,
                $recorder
            );

        $this->establishCenterContext(
            $center
        );

        $result =
            $this->service()
            ->overview(
                $studentA['user']
            );

        $attendanceIds =
            collect(
                $result['records']
            )
            ->pluck(
                'attendance_id'
            )
            ->all();

        $this->assertSame(
            [
                $ownAttendance->id,
            ],
            $attendanceIds
        );

        $this->assertNotContains(
            $otherAttendance->id,
            $attendanceIds
        );

        $enrollmentIds =
            collect(
                $result['enrollments']
            )
            ->pluck(
                'enrollment_id'
            )
            ->all();

        $this->assertSame(
            [
                $academicA['enrollment']->id,
            ],
            $enrollmentIds
        );
    }

    public function test_historical_inactive_status_remains_visible_and_cancelled_session_is_excluded(): void
    {
        $context =
            $this->studentContext(
                'Historical Status Student'
            );

        $academic =
            $this->academicContext(
                $context['student'],
                $context['branch']
            );

        $historicalLate =
            AttendanceStatus::factory()
            ->forCenter(
                $context['center']
            )
            ->inactive()
            ->create([
                'name' =>
                'Old Late',

                'code' =>
                'OLD_LATE',

                'contribution_value' =>
                50,
            ]);

        $present =
            $this->attendanceStatus(
                $context['center'],
                'PRESENT_CANCELLED',
                100
            );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $historicalAttendance =
            $this->attendance(
                $this->makeSession(
                    $academic['schedule'],
                    '2026-09-06'
                ),
                $academic['enrollment'],
                $historicalLate,
                $recorder
            );

        $cancelledSession =
            $this->makeSession(
                $academic['schedule'],
                '2026-09-07'
            );

        $cancelledAttendance =
            $this->attendance(
                $cancelledSession,
                $academic['enrollment'],
                $present,
                $recorder
            );

        $cancelledSession
            ->forceFill([
                'session_status' =>
                ClassSessionStatus::Cancelled,

                'cancellation_reason' =>
                'Cancelled after Attendance recording.',
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            1,
            $result['summary']['recorded_sessions']
        );

        $this->assertSame(
            '50.00',
            $result['summary']['attendance_percentage']
        );

        $this->assertCount(
            1,
            $result['records']
        );

        $record =
            $result['records'][0];

        $this->assertSame(
            $historicalAttendance->id,
            $record['attendance_id']
        );

        $this->assertSame(
            'OLD_LATE',
            $record['attendance_status']['code']
        );

        $this->assertFalse(
            $record['attendance_status']['is_active']
        );

        $this->assertNotContains(
            $cancelledAttendance->id,
            collect(
                $result['records']
            )
                ->pluck(
                    'attendance_id'
                )
                ->all()
        );
    }

    public function test_for_enrollment_returns_only_the_owned_enrollment_records(): void
    {
        $context =
            $this->studentContext(
                'Enrollment Attendance Student'
            );

        $academicA =
            $this->academicContext(
                $context['student'],
                $context['branch'],
                'English',
                'ENG-ONE',
                'ENG-ONE-01'
            );

        $academicB =
            $this->academicContext(
                $context['student'],
                $context['branch'],
                'French',
                'FRE-TWO',
                'FRE-TWO-01'
            );

        $status =
            $this->attendanceStatus(
                $context['center'],
                'PRESENT_FILTER',
                100
            );

        $recorder =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $context['center']
            );

        $attendanceA =
            $this->attendance(
                $this->makeSession(
                    $academicA['schedule'],
                    '2026-09-08'
                ),
                $academicA['enrollment'],
                $status,
                $recorder
            );

        $attendanceB =
            $this->attendance(
                $this->makeSession(
                    $academicB['schedule'],
                    '2026-09-09'
                ),
                $academicB['enrollment'],
                $status,
                $recorder
            );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->forEnrollment(
                $context['user'],
                $academicA['enrollment']->id
            );

        $this->assertSame(
            $academicA['enrollment']->id,
            $result['enrollment']['enrollment_id']
        );

        $this->assertSame(
            '100.00',
            $result['enrollment']['summary']['attendance_percentage']
        );

        $this->assertCount(
            1,
            $result['records']
        );

        $this->assertSame(
            $attendanceA->id,
            $result['records'][0]['attendance_id']
        );

        $this->assertNotContains(
            $attendanceB->id,
            collect(
                $result['records']
            )
                ->pluck(
                    'attendance_id'
                )
                ->all()
        );
    }

    public function test_for_enrollment_rejects_another_students_enrollment(): void
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

        $studentA =
            $this->studentContext(
                'Student A',
                $center,
                $branch
            );

        $studentB =
            $this->studentContext(
                'Student B',
                $center,
                $branch
            );

        $academicB =
            $this->academicContext(
                $studentB['student'],
                $branch
            );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forEnrollment(
                $studentA['user'],
                $academicB['enrollment']->id
            );
    }

    public function test_for_enrollment_rejects_cross_center_enrollment(): void
    {
        $studentA =
            $this->studentContext(
                'Center A Student'
            );

        $studentB =
            $this->studentContext(
                'Center B Student'
            );

        $academicB =
            $this->academicContext(
                $studentB['student'],
                $studentB['branch']
            );

        $this->establishCenterContext(
            $studentA['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->forEnrollment(
                $studentA['user'],
                $academicB['enrollment']->id
            );
    }

    public function test_student_with_no_recorded_attendance_receives_null_percentages(): void
    {
        $context =
            $this->studentContext(
                'No Attendance Student'
            );

        $academic =
            $this->academicContext(
                $context['student'],
                $context['branch']
            );

        /*
         * A generated Session exists, but no Attendance record
         * exists. Missing Attendance must not mean Absence.
         */
        $this->makeSession(
            $academic['schedule'],
            '2026-09-10'
        );

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->overview(
                $context['user']
            );

        $this->assertSame(
            1,
            $result['summary']['enrollment_count']
        );

        $this->assertSame(
            0,
            $result['summary']['recorded_sessions']
        );

        $this->assertSame(
            '0.00',
            $result['summary']['attendance_equivalent']
        );

        $this->assertSame(
            '0.00',
            $result['summary']['absence_equivalent']
        );

        $this->assertNull(
            $result['summary']['attendance_percentage']
        );

        $this->assertNull(
            $result['summary']['absence_percentage']
        );

        $this->assertSame(
            [],
            $result['records']
        );

        $this->assertNull(
            $result['enrollments'][0]['summary']['attendance_percentage']
        );

        $this->assertNull(
            $result['enrollments'][0]['summary']['meets_minimum_attendance']
        );
    }

    public function test_student_attendance_read_service_uses_persisted_account_state(): void
    {
        $context =
            $this->studentContext(
                'Deactivated Attendance Student'
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
                AccountStatus::Deactivated
                    ->value,

                'deactivated_at' =>
                now(),
            ]);

        /*
         * The supplied User object deliberately remains stale.
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
            ->overview(
                $context['user']
            );
    }

    /**
     * @return array{
     *     center: Center,
     *     branch: Branch,
     *     person: Person,
     *     user: User,
     *     student: Student
     * }
     */
    private function studentContext(
        string $name,
        ?Center $center = null,
        ?Branch $branch = null
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create();

        $branch ??=
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                $name,
            ]);

        $user =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        $student =
            Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        return [
            'center' =>
            $center,

            'branch' =>
            $branch,

            'person' =>
            $person,

            'user' =>
            $user,

            'student' =>
            $student,
        ];
    }

    /**
     * @return array{
     *     course: Course,
     *     class: CourseClass,
     *     enrollment: Enrollment,
     *     schedule: ClassSchedule
     * }
     */
    private function academicContext(
        Student $student,
        Branch $branch,
        string $courseName = 'English Intermediate',
        string $courseCode = 'ENG-B2',
        string $classCode = 'ENG-B2-01',
        int|float $minimumAttendance = 75
    ): array {
        $course =
            Course::factory()
            ->for(
                $branch->center
            )
            ->create([
                'name' =>
                $courseName,

                'code' =>
                $courseCode,

                'minimum_attendance' =>
                $minimumAttendance,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forCourse(
                $course
            )
            ->active()
            ->create([
                'class_code' =>
                $classCode,

                'name' =>
                $courseName
                    . ' Class',
            ]);

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
                'enrollment_date' =>
                '2026-08-01',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        return [
            'course' =>
            $course,

            'class' =>
            $courseClass,

            'enrollment' =>
            $enrollment,

            'schedule' =>
            $schedule,
        ];
    }

    private function makeSession(
        ClassSchedule $schedule,
        string $date
    ): ClassSession {
        return ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create([
                'occurrence_date' =>
                $date,

                'session_date' =>
                $date,
            ]);
    }

    private function attendanceStatus(
        Center $center,
        string $code,
        int|float $contribution
    ): AttendanceStatus {
        return AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                $code,

                'code' =>
                $code,

                'contribution_value' =>
                $contribution,
            ]);
    }

    private function attendance(
        ClassSession $session,
        Enrollment $enrollment,
        AttendanceStatus $status,
        User $recorder,
        int $lateMinutes = 0
    ): Attendance {
        return Attendance::factory()
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
                $lateMinutes,
            ]);
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

    private function createUserForRole(
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

    private function service(): StudentAttendanceReadService
    {
        return app(
            StudentAttendanceReadService::class
        );
    }
}
