<?php

namespace Tests\Feature\Authorization;

use App\Models\AcademicLevel;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Language;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Students\StudentPortalReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPortalReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_student_courses_return_authoritative_own_academic_records(): void
    {
        $context =
            $this->studentContext(
                'Student One'
            );

        $academic =
            $this->academicContext(
                $context['center'],
                $context['branch']
            );

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $context['student']
            )
            ->forCourseClass(
                $academic['class']
            )
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-STUDENT-001',

                'enrollment_date' =>
                '2026-09-01',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->courses(
                $context['user']
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            'Student One',
            $result['student']['name']
        );

        $this->assertSame(
            $context['center']->id,
            $result['student']['center']['id']
        );

        $this->assertSame(
            $context['branch']->id,
            $result['student']['branch']['id']
        );

        $this->assertCount(
            1,
            $result['enrollments']
        );

        $record =
            $result['enrollments'][0];

        $this->assertSame(
            $enrollment->id,
            $record['enrollment_id']
        );

        $this->assertSame(
            'ENR-STUDENT-001',
            $record['enrollment_number']
        );

        $this->assertSame(
            'active',
            $record['status']
        );

        $this->assertSame(
            'Active',
            $record['status_label']
        );

        $this->assertSame(
            $academic['course']->id,
            $record['course']['id']
        );

        $this->assertSame(
            'English Intermediate',
            $record['course']['name']
        );

        $this->assertSame(
            'ENG-B2',
            $record['course']['code']
        );

        $this->assertSame(
            'English',
            $record['course']['language']['name']
        );

        $this->assertSame(
            'B2',
            $record['course']['academic_level']['code']
        );

        $this->assertSame(
            $academic['class']->id,
            $record['class']['id']
        );

        $this->assertSame(
            'ENG-B2-01',
            $record['class']['code']
        );

        $this->assertSame(
            'Hussam Al-Attar',
            $record['assigned_teacher']['name']
        );

        $this->assertSame(
            'Room 204',
            $record['assigned_classroom']['name']
        );

        $this->assertSame(
            $context['branch']->id,
            $record['branch']['id']
        );

        $this->assertCount(
            1,
            $record['schedules']
        );

        $schedule =
            $record['schedules'][0];

        $this->assertSame(
            1,
            $schedule['day_of_week']
        );

        $this->assertSame(
            '17:00:00',
            $schedule['start_time']
        );

        $this->assertSame(
            '19:00:00',
            $schedule['end_time']
        );

        $this->assertSame(
            'active',
            $schedule['status']
        );

        $this->assertSame(
            'Hussam Al-Attar',
            $schedule['teacher']['name']
        );
    }

    public function test_student_courses_do_not_include_another_student_enrollment_in_same_center(): void
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

        $academicA =
            $this->academicContext(
                $center,
                $branch,
                'English Intermediate',
                'ENG-B2',
                'ENG-B2-A'
            );

        $academicB =
            $this->academicContext(
                $center,
                $branch,
                'French Beginner',
                'FRE-A1',
                'FRE-A1-B'
            );

        $ownEnrollment =
            Enrollment::factory()
            ->forStudent(
                $studentA['student']
            )
            ->forCourseClass(
                $academicA['class']
            )
            ->active()
            ->create();

        $otherEnrollment =
            Enrollment::factory()
            ->forStudent(
                $studentB['student']
            )
            ->forCourseClass(
                $academicB['class']
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $result =
            $this->service()
            ->courses(
                $studentA['user']
            );

        $ids =
            collect(
                $result['enrollments']
            )
            ->pluck(
                'enrollment_id'
            )
            ->all();

        $this->assertSame(
            [
                $ownEnrollment->id,
            ],
            $ids
        );

        $this->assertNotContains(
            $otherEnrollment->id,
            $ids
        );
    }

    public function test_student_schedule_returns_only_sessions_for_own_enrolled_classes(): void
    {
        $context =
            $this->studentContext(
                'Student Schedule'
            );

        $academic =
            $this->academicContext(
                $context['center'],
                $context['branch']
            );

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $context['student']
            )
            ->forCourseClass(
                $academic['class']
            )
            ->active()
            ->create();

        $completed =
            ClassSession::factory()
            ->forSchedule(
                $academic['schedule']
            )
            ->completed()
            ->create([
                'session_date' =>
                '2026-09-08',

                'occurrence_date' =>
                '2026-09-08',
            ]);

        $cancelled =
            ClassSession::factory()
            ->forSchedule(
                $academic['schedule']
            )
            ->cancelled()
            ->create([
                'session_date' =>
                '2026-09-10',

                'occurrence_date' =>
                '2026-09-10',

                'cancellation_reason' =>
                'Teacher unavailable',
            ]);

        $scheduled =
            ClassSession::factory()
            ->forSchedule(
                $academic['schedule']
            )
            ->scheduled()
            ->create([
                'session_date' =>
                '2026-09-15',

                'occurrence_date' =>
                '2026-09-15',
            ]);

        $otherStudent =
            $this->studentContext(
                'Other Student',
                $context['center'],
                $context['branch']
            );

        $otherAcademic =
            $this->academicContext(
                $context['center'],
                $context['branch'],
                'German Beginner',
                'GER-A1',
                'GER-A1-01'
            );

        Enrollment::factory()
            ->forStudent(
                $otherStudent['student']
            )
            ->forCourseClass(
                $otherAcademic['class']
            )
            ->active()
            ->create();

        $otherSession =
            ClassSession::factory()
            ->forSchedule(
                $otherAcademic['schedule']
            )
            ->scheduled()
            ->create([
                'session_date' =>
                '2026-09-12',

                'occurrence_date' =>
                '2026-09-12',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->schedule(
                $context['user']
            );

        $this->assertCount(
            3,
            $result['sessions']
        );

        $sessionIds =
            collect(
                $result['sessions']
            )
            ->pluck(
                'session_id'
            )
            ->all();

        $this->assertSame(
            [
                $completed->id,
                $cancelled->id,
                $scheduled->id,
            ],
            $sessionIds
        );

        $this->assertNotContains(
            $otherSession->id,
            $sessionIds
        );

        $this->assertSame(
            [
                'completed',
                'cancelled',
                'scheduled',
            ],
            collect(
                $result['sessions']
            )
                ->pluck(
                    'status'
                )
                ->all()
        );

        foreach (
            $result['sessions']
            as $session
        ) {
            $this->assertSame(
                $enrollment->id,
                $session['enrollment_id']
            );

            $this->assertSame(
                $academic['course']->id,
                $session['course_id']
            );

            $this->assertSame(
                'Hussam Al-Attar',
                $session['teacher']['name']
            );

            $this->assertSame(
                'Room 204',
                $session['classroom']['name']
            );
        }
    }

    public function test_student_course_details_return_owned_enrollment_and_actual_rescheduled_session_date(): void
    {
        $context =
            $this->studentContext(
                'Course Details Student'
            );

        $academic =
            $this->academicContext(
                $context['center'],
                $context['branch']
            );

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $context['student']
            )
            ->forCourseClass(
                $academic['class']
            )
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-DETAILS-001',
            ]);

        $session =
            ClassSession::factory()
            ->forSchedule(
                $academic['schedule']
            )
            ->scheduled()
            ->create([
                /*
             * Original recurring occurrence.
             */
                'occurrence_date' =>
                '2026-09-08',

                /*
             * Actual rescheduled date shown to the Student.
             */
                'session_date' =>
                '2026-09-11',

                'start_time' =>
                '18:00:00',

                'end_time' =>
                '20:00:00',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->courseDetails(
                $context['user'],
                $enrollment->id
            );

        $this->assertSame(
            $context['student']->id,
            $result['student']['id']
        );

        $this->assertSame(
            $enrollment->id,
            $result['enrollment']['enrollment_id']
        );

        $this->assertSame(
            'ENR-DETAILS-001',
            $result['enrollment']['enrollment_number']
        );

        $this->assertSame(
            $academic['course']->id,
            $result['enrollment']['course']['id']
        );

        $this->assertSame(
            $academic['class']->id,
            $result['enrollment']['class']['id']
        );

        $this->assertCount(
            1,
            $result['sessions']
        );

        $record =
            $result['sessions'][0];

        $this->assertSame(
            $session->id,
            $record['session_id']
        );

        $this->assertSame(
            $enrollment->id,
            $record['enrollment_id']
        );

        /*
     * The Student-facing date must be the actual session_date,
     * not the immutable original occurrence_date.
     */
        $this->assertSame(
            '2026-09-11',
            $record['session_date']
        );

        $this->assertSame(
            '18:00:00',
            $record['start_time']
        );

        $this->assertSame(
            '20:00:00',
            $record['end_time']
        );

        $this->assertSame(
            'Hussam Al-Attar',
            $record['teacher']['name']
        );

        $this->assertSame(
            'Room 204',
            $record['classroom']['name']
        );
    }

    public function test_student_course_details_reject_another_student_enrollment_in_same_center(): void
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
                'Details Student A',
                $center,
                $branch
            );

        $studentB =
            $this->studentContext(
                'Details Student B',
                $center,
                $branch
            );

        $academic =
            $this->academicContext(
                $center,
                $branch
            );

        $otherEnrollment =
            Enrollment::factory()
            ->forStudent(
                $studentB['student']
            )
            ->forCourseClass(
                $academic['class']
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->courseDetails(
                $studentA['user'],
                $otherEnrollment->id
            );
    }

    public function test_student_course_details_reject_cross_center_enrollment(): void
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
                $studentB['center'],
                $studentB['branch'],
                'French Intermediate',
                'FRE-B1',
                'FRE-B1-01'
            );

        $otherEnrollment =
            Enrollment::factory()
            ->forStudent(
                $studentB['student']
            )
            ->forCourseClass(
                $academicB['class']
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $studentA['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->courseDetails(
                $studentA['user'],
                $otherEnrollment->id
            );
    }

    public function test_student_schedule_excludes_sessions_from_non_active_enrollments(): void
    {
        $context =
            $this->studentContext(
                'Active Schedule Student'
            );

        $activeAcademic =
            $this->academicContext(
                $context['center'],
                $context['branch'],
                'English Intermediate',
                'ENG-B2',
                'ENG-B2-ACTIVE'
            );

        $completedAcademic =
            $this->academicContext(
                $context['center'],
                $context['branch'],
                'Spanish Beginner',
                'SPA-A1',
                'SPA-A1-COMPLETED'
            );

        $activeEnrollment =
            Enrollment::factory()
            ->forStudent(
                $context['student']
            )
            ->forCourseClass(
                $activeAcademic['class']
            )
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent(
                $context['student']
            )
            ->forCourseClass(
                $completedAcademic['class']
            )
            ->completed()
            ->create();

        $activeSession =
            ClassSession::factory()
            ->forSchedule(
                $activeAcademic['schedule']
            )
            ->scheduled()
            ->create([
                'occurrence_date' =>
                '2026-09-20',

                'session_date' =>
                '2026-09-20',
            ]);

        $completedEnrollmentSession =
            ClassSession::factory()
            ->forSchedule(
                $completedAcademic['schedule']
            )
            ->scheduled()
            ->create([
                'occurrence_date' =>
                '2026-09-21',

                'session_date' =>
                '2026-09-21',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->schedule(
                $context['user']
            );

        $this->assertCount(
            1,
            $result['sessions']
        );

        $this->assertSame(
            $activeSession->id,
            $result['sessions'][0]['session_id']
        );

        $this->assertSame(
            $activeEnrollment->id,
            $result['sessions'][0]['enrollment_id']
        );

        $this->assertNotContains(
            $completedEnrollmentSession->id,
            collect(
                $result['sessions']
            )
                ->pluck(
                    'session_id'
                )
                ->all()
        );
    }

    public function test_student_without_enrollments_receives_explicit_empty_read_state(): void
    {
        $context =
            $this->studentContext(
                'Student Empty'
            );

        $this->establishCenterContext(
            $context['center']
        );

        $courses =
            $this->service()
            ->courses(
                $context['user']
            );

        $schedule =
            $this->service()
            ->schedule(
                $context['user']
            );

        $this->assertSame(
            [],
            $courses['enrollments']
        );

        $this->assertSame(
            [],
            $schedule['sessions']
        );

        $this->assertSame(
            $context['student']->id,
            $courses['student']['id']
        );

        $this->assertSame(
            $context['student']->id,
            $schedule['student']['id']
        );
    }

    public function test_non_student_account_cannot_use_student_portal_read_service(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacherUser =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center,
                $person
            );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->courses(
                $teacherUser
            );
    }

    public function test_archived_student_record_cannot_use_student_portal_read_service(): void
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

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'full_name' =>
                'Archived Student',
            ]);

        $user =
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $person
            )
            ->archived()
            ->create([
                'user_id' =>
                $user->id,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->courses(
                $user
            );
    }

    public function test_student_read_service_fails_closed_when_tenant_context_does_not_match_account(): void
    {
        $context =
            $this->studentContext(
                'Tenant Student'
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
            ->courses(
                $context['user']
            );
    }

    public function test_student_read_service_uses_persisted_account_state_instead_of_stale_actor(): void
    {
        $context =
            $this->studentContext(
                'Persisted State Student'
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
         * Supplied instance deliberately remains stale and Active.
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
            ->courses(
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
            ->for(
                $center
            )
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
            $this->createUserForRole(
                SystemRole::Student,
                $center,
                $person
            );

        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
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
     *     language: Language,
     *     level: AcademicLevel,
     *     course: Course,
     *     teacher: Teacher,
     *     classroom: Classroom,
     *     class: CourseClass,
     *     schedule: ClassSchedule
     * }
     */
    private function academicContext(
        Center $center,
        Branch $branch,
        string $courseName = 'English Intermediate',
        string $courseCode = 'ENG-B2',
        string $classCode = 'ENG-B2-01'
    ): array {
        $language =
            Language::factory()
            ->create([
                'center_id' =>
                $center->id,

                'name' =>
                $courseCode === 'ENG-B2'
                    ? 'English'
                    : $courseName
                    . ' Language',
            ]);

        $level =
            AcademicLevel::factory()
            ->create([
                'center_id' =>
                $center->id,

                'language_id' =>
                $language->id,

                'name' =>
                'Intermediate',

                'code' =>
                $courseCode === 'ENG-B2'
                    ? 'B2'
                    : 'L1',
            ]);

        $course =
            Course::factory()
            ->forAcademicLevel(
                $level
            )
            ->active()
            ->create([
                'name' =>
                $courseName,

                'code' =>
                $courseCode,

                'total_hours' =>
                48,

                'minimum_attendance' =>
                75,
            ]);

        $teacherPerson =
            Person::factory()
            ->for(
                $center
            )
            ->create([
                'full_name' =>
                'Hussam Al-Attar',
            ]);

        $teacher =
            Teacher::factory()
            ->forPerson(
                $teacherPerson
            )
            ->active()
            ->create();

        $classroom =
            Classroom::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->available()
            ->create([
                'name' =>
                'Room 204',

                'location' =>
                'Second Floor',
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forCourse(
                $course
            )
            ->forTeacher(
                $teacher
            )
            ->forClassroom(
                $classroom
            )
            ->active()
            ->create([
                'class_code' =>
                $classCode,

                'name' =>
                $courseName
                    . ' Class',

                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-12-20',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'start_time' =>
                '17:00:00',

                'end_time' =>
                '19:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-12-20',
            ]);

        return [
            'language' =>
            $language,

            'level' =>
            $level,

            'course' =>
            $course,

            'teacher' =>
            $teacher,

            'classroom' =>
            $classroom,

            'class' =>
            $courseClass,

            'schedule' =>
            $schedule,
        ];
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
        Person $person
    ): User {
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

    private function service(): StudentPortalReadService
    {
        return app(
            StudentPortalReadService::class
        );
    }
}
