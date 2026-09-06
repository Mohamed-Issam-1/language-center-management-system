<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $student = Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->create([
                'enrollment_number' =>
                'ENR-000001',

                'enrollment_date' =>
                '2026-09-01',

                'eligibility_status' =>
                'eligible',
            ]);

        $this->assertSame(
            $center->id,
            $enrollment->center_id
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            $courseClass->id,
            $enrollment->class_id
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment
                ->enrollment_status
        );

        $this->assertNull(
            $enrollment
                ->withdrawal_date
        );

        $this->assertNull(
            $enrollment
                ->withdrawal_reason
        );
    }

    public function test_new_enrollment_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $student = Student::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $enrollment =
            Enrollment::query()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_number' =>
                'ENR-DEFAULT',

                'enrollment_date' =>
                '2026-09-01',

                'eligibility_status' =>
                'eligible',
            ]);

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment
                ->enrollment_status
        );

        $this->assertTrue(
            $enrollment->isActive()
        );
    }

    public function test_enrollment_casts_all_lifecycle_states(): void
    {
        $active =
            Enrollment::factory()
            ->active()
            ->create();

        $completed =
            Enrollment::factory()
            ->completed()
            ->create();

        $withdrawn =
            Enrollment::factory()
            ->withdrawn()
            ->create();

        $transferred =
            Enrollment::factory()
            ->transferred()
            ->create();

        $cancelled =
            Enrollment::factory()
            ->cancelled()
            ->create();

        $this->assertTrue(
            $active->isActive()
        );

        $this->assertTrue(
            $completed->isCompleted()
        );

        $this->assertTrue(
            $withdrawn->isWithdrawn()
        );

        $this->assertTrue(
            $transferred->isTransferred()
        );

        $this->assertTrue(
            $cancelled->isCancelled()
        );
    }

    public function test_enrollment_belongs_to_center_student_and_course_class(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $student = Student::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->create();

        $this->assertTrue(
            $enrollment->center
                ->is($center)
        );

        $this->assertTrue(
            $enrollment->student
                ->is($student)
        );

        $this->assertTrue(
            $enrollment->courseClass
                ->is($courseClass)
        );

        $this->assertTrue(
            $student->enrollments
                ->contains($enrollment)
        );

        $this->assertTrue(
            $courseClass->enrollments
                ->contains($enrollment)
        );

        $this->assertTrue(
            $center->enrollments
                ->contains($enrollment)
        );
    }

    public function test_database_rejects_student_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $studentB =
            Student::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        Enrollment::query()
            ->create(
                $this->attributes(
                    centerId: $centerA->id,

                    studentId: $studentB->id,

                    classId: $classA->id,

                    number: 'INVALID-STUDENT'
                )
            );
    }

    public function test_database_rejects_class_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $studentA =
            Student::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        Enrollment::query()
            ->create(
                $this->attributes(
                    centerId: $centerA->id,

                    studentId: $studentA->id,

                    classId: $classB->id,

                    number: 'INVALID-CLASS'
                )
            );
    }

    public function test_database_prevents_duplicate_student_enrollment_in_same_class(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $student = Student::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->create();
    }

    public function test_same_student_can_have_enrollments_in_different_classes(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $student = Student::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $enrollmentA =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($classA)
            ->create();

        $enrollmentB =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($classB)
            ->create();

        $this->assertNotSame(
            $enrollmentA->class_id,
            $enrollmentB->class_id
        );
    }

    public function test_enrollment_number_must_be_unique_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'enrollment_number' =>
                'ENR-001',
            ]);

        $this->expectException(
            QueryException::class
        );

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'enrollment_number' =>
                'ENR-001',
            ]);
    }

    public function test_same_enrollment_number_is_allowed_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $enrollmentA =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'enrollment_number' =>
                'ENR-001',
            ]);

        $enrollmentB =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'enrollment_number' =>
                'ENR-001',
            ]);

        $this->assertSame(
            'ENR-001',
            $enrollmentA
                ->enrollment_number
        );

        $this->assertSame(
            'ENR-001',
            $enrollmentB
                ->enrollment_number
        );

        $this->assertNotSame(
            $enrollmentA->center_id,
            $enrollmentB->center_id
        );
    }

    public function test_enrollment_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $enrollmentA =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $centerB = Center::factory()
            ->active()
            ->create();

        $enrollmentB =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = Enrollment::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $enrollmentA->id,
            $ids
        );

        $this->assertNotContains(
            $enrollmentB->id,
            $ids
        );
    }

    public function test_enrollment_history_preserves_actor_classes_and_status_change(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $toClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $actor =
            $this->createCenterOwner(
                $center
            );

        $history =
            EnrollmentHistory::factory()
            ->forEnrollment($enrollment)
            ->performedBy($actor)
            ->create([
                'from_class_id' =>
                $enrollment->class_id,

                'to_class_id' =>
                $toClass->id,

                'event_type' =>
                'transfer',

                'previous_status' =>
                EnrollmentStatus::Active,

                'new_status' =>
                EnrollmentStatus::Transferred,

                'notes' =>
                'Transferred to another Class.',
            ]);

        $this->assertTrue(
            $history->enrollment
                ->is($enrollment)
        );

        $this->assertTrue(
            $history->fromClass
                ->is($enrollment->courseClass)
        );

        $this->assertTrue(
            $history->toClass
                ->is($toClass)
        );

        $this->assertTrue(
            $history->performedBy
                ->is($actor)
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $history->previous_status
        );

        $this->assertSame(
            EnrollmentStatus::Transferred,
            $history->new_status
        );

        $this->assertTrue(
            $enrollment->histories
                ->contains($history)
        );
    }

    public function test_database_rejects_history_actor_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $actorB =
            $this->createCenterOwner(
                $centerB
            );

        $this->expectException(
            QueryException::class
        );

        EnrollmentHistory::query()
            ->create([
                'center_id' =>
                $centerA->id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                $enrollment->class_id,

                'performed_by_user_id' =>
                $actorB->id,

                'event_type' =>
                'created',

                'previous_status' =>
                null,

                'new_status' =>
                EnrollmentStatus::Active,

                'notes' =>
                null,

                'occurred_at' =>
                now(),
            ]);
    }

    public function test_database_rejects_history_class_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $actor =
            $this->createCenterOwner(
                $centerA
            );

        $this->expectException(
            QueryException::class
        );

        EnrollmentHistory::query()
            ->create([
                'center_id' =>
                $centerA->id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                $enrollment->class_id,

                'to_class_id' =>
                $classB->id,

                'performed_by_user_id' =>
                $actor->id,

                'event_type' =>
                'transfer',

                'previous_status' =>
                EnrollmentStatus::Active,

                'new_status' =>
                EnrollmentStatus::Transferred,

                'notes' =>
                null,

                'occurred_at' =>
                now(),
            ]);
    }

    public function test_enrollment_history_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $historyA =
            EnrollmentHistory::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        $centerB = Center::factory()
            ->active()
            ->create();

        $historyB =
            EnrollmentHistory::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids =
            EnrollmentHistory::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $historyA->id,
            $ids
        );

        $this->assertNotContains(
            $historyB->id,
            $ids
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(
        int $centerId,
        int $studentId,
        int $classId,
        string $number
    ): array {
        return [
            'center_id' =>
            $centerId,

            'student_id' =>
            $studentId,

            'class_id' =>
            $classId,

            'enrollment_number' =>
            $number,

            'enrollment_date' =>
            '2026-09-01',

            'enrollment_status' =>
            EnrollmentStatus::Active,

            'eligibility_status' =>
            'eligible',

            'withdrawal_date' =>
            null,

            'withdrawal_reason' =>
            null,
        ];
    }

    private function createCenterOwner(
        Center $center
    ): User {
        $person = Person::factory()
            ->for($center)
            ->create();

        $role = Role::query()
            ->firstOrCreate(
                [
                    'code' =>
                    SystemRole::CenterOwner
                        ->value,
                ],
                [
                    'name' =>
                    SystemRole::CenterOwner
                        ->label(),
                ]
            );

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $role->id,
            ]);
    }
}
