<?php

namespace Tests\Feature\Authorization;

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
use App\Services\Teachers\TeacherPortalReadService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherPortalReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_classes_returns_only_course_classes_assigned_to_authenticated_teacher(): void
    {
        $context =
            $this->teacherContext(
                'Teacher One'
            );

        $branch =
            $this->branch(
                $context['center']
            );

        $ownClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $context['teacher']
            )
            ->active()
            ->create([
                'class_code' =>
                'TEACHER-OWN-001',

                'name' =>
                'Teacher Own Class',
            ]);

        $otherTeacher =
            $this->teacherContext(
                'Teacher Two',
                $context['center']
            );

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $otherTeacher['teacher']
            )
            ->active()
            ->create([
                'class_code' =>
                'TEACHER-OTHER-001',

                'name' =>
                'Other Teacher Class',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->classes(
                $context['user']
            );

        $this->assertSame(
            $context['teacher']->id,
            $result['teacher']['id']
        );

        $this->assertSame(
            'Teacher One',
            $result['teacher']['name']
        );

        $ids =
            collect(
                $result['classes']
            )
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $ownClass->id,
            $ids
        );

        $this->assertNotContains(
            $otherClass->id,
            $ids
        );

        $ownPayload =
            collect(
                $result['classes']
            )
            ->firstWhere(
                'id',
                $ownClass->id
            );

        $this->assertSame(
            'TEACHER-OWN-001',
            $ownPayload['code']
        );

        $this->assertSame(
            'Teacher Own Class',
            $ownPayload['name']
        );

        $this->assertSame(
            'active',
            $ownPayload['status']
        );

        $this->assertSame(
            'Active',
            $ownPayload['status_label']
        );
    }

    public function test_historical_assigned_classes_remain_visible(): void
    {
        $context =
            $this->teacherContext(
                'Historical Teacher'
            );

        $branch =
            $this->branch(
                $context['center']
            );

        $completed =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $context['teacher']
            )
            ->completed()
            ->create([
                'class_code' =>
                'HIST-COMPLETE',
            ]);

        $cancelled =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $context['teacher']
            )
            ->cancelled()
            ->create([
                'class_code' =>
                'HIST-CANCELLED',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->classes(
                $context['user']
            );

        $ids =
            collect(
                $result['classes']
            )
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $completed->id,
            $ids
        );

        $this->assertContains(
            $cancelled->id,
            $ids
        );

        $statuses =
            collect(
                $result['classes']
            )
            ->whereIn(
                'id',
                [
                    $completed->id,
                    $cancelled->id,
                ]
            )
            ->pluck(
                'status'
            )
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [
                'cancelled',
                'completed',
            ],
            $statuses
        );
    }

    public function test_class_details_returns_only_assigned_class_roster(): void
    {
        $context =
            $this->teacherContext(
                'Roster Teacher'
            );

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
            ->create([
                'class_code' =>
                'ROSTER-001',

                'name' =>
                'Roster Class',
            ]);

        $studentPerson =
            Person::factory()
            ->for(
                $context['center']
            )
            ->create([
                'full_name' =>
                'Roster Student',
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $branch
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
                'ENR-ROSTER-001',

                'enrollment_date' =>
                '2026-09-01',
            ]);

        $otherTeacher =
            $this->teacherContext(
                'Other Roster Teacher',
                $context['center']
            );

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $otherTeacher['teacher']
            )
            ->active()
            ->create();

        $otherPerson =
            Person::factory()
            ->for(
                $context['center']
            )
            ->create([
                'full_name' =>
                'Unrelated Student',
            ]);

        $otherStudent =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->forPerson(
                $otherPerson
            )
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent(
                $otherStudent
            )
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->classDetails(
                $context['user'],
                $courseClass->id
            );

        $this->assertSame(
            $courseClass->id,
            $result['class']['id']
        );

        $this->assertSame(
            'ROSTER-001',
            $result['class']['code']
        );

        $this->assertSame(
            1,
            $result['class']['enrollment_count']
        );

        $this->assertCount(
            1,
            $result['students']
        );

        $record =
            $result['students'][0];

        $this->assertSame(
            $enrollment->id,
            $record['enrollment_id']
        );

        $this->assertSame(
            'ENR-ROSTER-001',
            $record['enrollment_number']
        );

        $this->assertSame(
            $student->id,
            $record['student']['id']
        );

        $this->assertSame(
            'Roster Student',
            $record['student']['name']
        );

        $this->assertSame(
            'active',
            $record['student']['status']
        );
    }

    public function test_class_roster_does_not_expose_sensitive_student_identity_or_account_data(): void
    {
        $context =
            $this->teacherContext(
                'Safe Roster Teacher'
            );

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

        $person =
            Person::factory()
            ->for(
                $context['center']
            )
            ->create([
                'full_name' =>
                'Safe Roster Student',

                'national_id_number' =>
                'SENSITIVE-NATIONAL-999',

                'email' =>
                'private-student@example.test',

                'phone_number' =>
                '+970599999999',

                'personal_picture_path' =>
                'private/students/secret-picture.jpg',
            ]);

        $student =
            Student::factory()
            ->forBranch(
                $branch
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
            ->classDetails(
                $context['user'],
                $courseClass->id
            );

        $studentPayload =
            $result['students'][0]['student'];

        $this->assertSame(
            'Safe Roster Student',
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
            'SENSITIVE-NATIONAL-999',
            $serialized
        );

        $this->assertStringNotContainsString(
            'private-student@example.test',
            $serialized
        );

        $this->assertStringNotContainsString(
            '+970599999999',
            $serialized
        );

        $this->assertStringNotContainsString(
            'private/students/secret-picture.jpg',
            $serialized
        );
    }

    public function test_class_details_rejects_another_teachers_class(): void
    {
        $context =
            $this->teacherContext(
                'Teacher Scope One'
            );

        $branch =
            $this->branch(
                $context['center']
            );

        $otherTeacher =
            $this->teacherContext(
                'Teacher Scope Two',
                $context['center']
            );

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $otherTeacher['teacher']
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->classDetails(
                $context['user'],
                $otherClass->id
            );
    }

    public function test_class_details_rejects_cross_center_class(): void
    {
        $context =
            $this->teacherContext(
                'Center A Teacher'
            );

        $otherContext =
            $this->teacherContext(
                'Center B Teacher'
            );

        $otherBranch =
            $this->branch(
                $otherContext['center']
            );

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->forTeacher(
                $otherContext['teacher']
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->classDetails(
                $context['user'],
                $otherClass->id
            );
    }

    public function test_reassigned_class_is_no_longer_visible_to_previous_teacher(): void
    {
        $context =
            $this->teacherContext(
                'Previous Teacher'
            );

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

        $newTeacher =
            $this->teacherContext(
                'Replacement Teacher',
                $context['center']
            );

        $courseClass
            ->forceFill([
                'assigned_teacher_id' =>
                $newTeacher['teacher']->id,
            ])
            ->save();

        $this->establishCenterContext(
            $context['center']
        );

        $classes =
            $this->service()
            ->classes(
                $context['user']
            );

        $this->assertNotContains(
            $courseClass->id,
            collect(
                $classes['classes']
            )
                ->pluck(
                    'id'
                )
                ->all()
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->classDetails(
                $context['user'],
                $courseClass->id
            );
    }

    public function test_schedule_returns_only_recurring_schedules_assigned_to_teacher(): void
    {
        $context =
            $this->teacherContext(
                'Schedule Teacher'
            );

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

        $ownSchedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' =>
                2,

                'start_time' =>
                '11:00:00',

                'end_time' =>
                '12:30:00',
            ]);

        $otherTeacher =
            $this->teacherContext(
                'Other Schedule Teacher',
                $context['center']
            );

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forTeacher(
                $otherTeacher['teacher']
            )
            ->active()
            ->create();

        $otherSchedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create();

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->schedule(
                $context['user']
            );

        $ids =
            collect(
                $result['schedules']
            )
            ->pluck(
                'schedule_id'
            )
            ->all();

        $this->assertContains(
            $ownSchedule->id,
            $ids
        );

        $this->assertNotContains(
            $otherSchedule->id,
            $ids
        );

        $ownPayload =
            collect(
                $result['schedules']
            )
            ->firstWhere(
                'schedule_id',
                $ownSchedule->id
            );

        $this->assertSame(
            2,
            $ownPayload['day_of_week']
        );

        $this->assertSame(
            '11:00:00',
            $ownPayload['start_time']
        );

        $this->assertSame(
            '12:30:00',
            $ownPayload['end_time']
        );

        $this->assertSame(
            'active',
            $ownPayload['status']
        );
    }

    public function test_concrete_session_visibility_uses_session_teacher_assignment(): void
    {
        $context =
            $this->teacherContext(
                'Concrete Session Teacher'
            );

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

        $ownSession =
            ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create([
                'session_date' =>
                '2026-09-10',

                'occurrence_date' =>
                '2026-09-10',

                'topic' =>
                'Own Teacher Session',
            ]);

        $otherTeacher =
            $this->teacherContext(
                'Substitute Teacher',
                $context['center']
            );

        /*
         * Same Course Class and same recurring Schedule,
         * but this concrete occurrence is assigned to another
         * persisted Teacher.
         */
        $otherSession =
            ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create([
                'teacher_id' =>
                $otherTeacher['teacher']->id,

                'session_date' =>
                '2026-09-12',

                'occurrence_date' =>
                '2026-09-12',

                'topic' =>
                'Substitute Session',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->schedule(
                $context['user']
            );

        $sessionIds =
            collect(
                $result['sessions']
            )
            ->pluck(
                'session_id'
            )
            ->all();

        $this->assertContains(
            $ownSession->id,
            $sessionIds
        );

        $this->assertNotContains(
            $otherSession->id,
            $sessionIds
        );

        $payload =
            collect(
                $result['sessions']
            )
            ->firstWhere(
                'session_id',
                $ownSession->id
            );

        $this->assertSame(
            '2026-09-10',
            $payload['session_date']
        );

        $this->assertSame(
            'Own Teacher Session',
            $payload['topic']
        );

        $this->assertSame(
            'scheduled',
            $payload['status']
        );
    }

    public function test_cancelled_schedule_and_session_remain_visible_as_history(): void
    {
        $context =
            $this->teacherContext(
                'Historical Schedule Teacher'
            );

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
            ->cancelled()
            ->create();

        $session =
            ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->cancelled()
            ->create([
                'session_date' =>
                '2026-09-14',

                'occurrence_date' =>
                '2026-09-14',

                'cancellation_reason' =>
                'Teacher unavailable',
            ]);

        $this->establishCenterContext(
            $context['center']
        );

        $result =
            $this->service()
            ->schedule(
                $context['user']
            );

        $schedulePayload =
            collect(
                $result['schedules']
            )
            ->firstWhere(
                'schedule_id',
                $schedule->id
            );

        $sessionPayload =
            collect(
                $result['sessions']
            )
            ->firstWhere(
                'session_id',
                $session->id
            );

        $this->assertNotNull(
            $schedulePayload
        );

        $this->assertNotNull(
            $sessionPayload
        );

        $this->assertSame(
            'cancelled',
            $schedulePayload['status']
        );

        $this->assertSame(
            'cancelled',
            $sessionPayload['status']
        );

        $this->assertSame(
            'Teacher unavailable',
            $sessionPayload['cancellation_reason']
        );
    }

    public function test_teacher_without_academic_assignments_receives_explicit_empty_state(): void
    {
        $context =
            $this->teacherContext(
                'Empty Teacher'
            );

        $this->establishCenterContext(
            $context['center']
        );

        $classes =
            $this->service()
            ->classes(
                $context['user']
            );

        $schedule =
            $this->service()
            ->schedule(
                $context['user']
            );

        $this->assertSame(
            [],
            $classes['classes']
        );

        $this->assertSame(
            [],
            $schedule['schedules']
        );

        $this->assertSame(
            [],
            $schedule['sessions']
        );
    }

    public function test_deactivated_teacher_record_cannot_use_teacher_portal_read_service(): void
    {
        $context =
            $this->teacherContext(
                'Deactivated Teacher'
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
            ->classes(
                $context['user']
            );
    }

    public function test_teacher_portal_read_service_uses_persisted_account_state(): void
    {
        $context =
            $this->teacherContext(
                'Persisted Teacher'
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
         * Deliberately stale in-memory User.
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
            ->classes(
                $context['user']
            );
    }

    public function test_teacher_portal_read_service_fails_closed_on_tenant_mismatch(): void
    {
        $context =
            $this->teacherContext(
                'Tenant Teacher'
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
            ->classes(
                $context['user']
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

    private function service(): TeacherPortalReadService
    {
        return app(
            TeacherPortalReadService::class
        );
    }
}
