<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Support\Enums\CourseClassStatus;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseClassFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_class_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacher = Teacher::factory()
            ->active()
            ->create([
                'center_id' => $center->id,
            ]);

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create([
                'class_code' => 'ENG-A1-01',
                'name' => 'English A1 Morning',
                'start_date' => '2026-09-01',
                'end_date' => '2026-10-31',
                'capacity' => 20,
                'delivery_mode' => 'onsite',
                'class_status' =>
                CourseClassStatus::Planned,
            ]);

        $this->assertSame(
            $center->id,
            $courseClass->center_id
        );

        $this->assertSame(
            $branch->id,
            $courseClass->branch_id
        );

        $this->assertSame(
            $course->id,
            $courseClass->course_id
        );

        $this->assertSame(
            $classroom->id,
            $courseClass->assigned_classroom_id
        );

        $this->assertSame(
            $teacher->id,
            $courseClass->assigned_teacher_id
        );

        $this->assertSame(
            'ENG-A1-01',
            $courseClass->class_code
        );

        $this->assertSame(
            'English A1 Morning',
            $courseClass->name
        );

        $this->assertSame(
            20,
            $courseClass->capacity
        );

        $this->assertSame(
            'onsite',
            $courseClass->delivery_mode
        );

        $this->assertSame(
            CourseClassStatus::Planned,
            $courseClass->class_status
        );
    }

    public function test_new_course_class_defaults_to_planned_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        $teacher = Teacher::factory()
            ->create([
                'center_id' => $center->id,
            ]);

        $courseClass = CourseClass::query()
            ->create([
                'center_id' => $center->id,
                'branch_id' => $branch->id,
                'course_id' => $course->id,

                'assigned_classroom_id' =>
                $classroom->id,

                'assigned_teacher_id' =>
                $teacher->id,

                'class_code' => 'CLASS-001',
                'name' => 'Class 001',
                'start_date' => '2026-09-01',
                'end_date' => '2026-10-01',
                'capacity' => 20,
                'delivery_mode' => 'standard',
            ]);

        $courseClass->refresh();

        $this->assertSame(
            CourseClassStatus::Planned,
            $courseClass->class_status
        );

        $this->assertTrue(
            $courseClass->isPlanned()
        );

        $this->assertFalse(
            $courseClass->isActive()
        );
    }

    public function test_course_class_casts_all_lifecycle_states(): void
    {
        $planned = CourseClass::factory()
            ->planned()
            ->create();

        $active = CourseClass::factory()
            ->active()
            ->create();

        $completed = CourseClass::factory()
            ->completed()
            ->create();

        $cancelled = CourseClass::factory()
            ->cancelled()
            ->create();

        $this->assertTrue(
            $planned->isPlanned()
        );

        $this->assertTrue(
            $active->isActive()
        );

        $this->assertTrue(
            $completed->isCompleted()
        );

        $this->assertTrue(
            $cancelled->isCancelled()
        );

        $this->assertSame(
            CourseClassStatus::Active,
            $active->class_status
        );

        $this->assertSame(
            CourseClassStatus::Completed,
            $completed->class_status
        );

        $this->assertSame(
            CourseClassStatus::Cancelled,
            $cancelled->class_status
        );
    }

    public function test_course_class_belongs_to_all_required_domain_records(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $course = Course::factory()
            ->for($center)
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        $teacher = Teacher::factory()
            ->create([
                'center_id' => $center->id,
            ]);

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create();

        $this->assertTrue(
            $courseClass->center->is(
                $center
            )
        );

        $this->assertTrue(
            $courseClass->branch->is(
                $branch
            )
        );

        $this->assertTrue(
            $courseClass->course->is(
                $course
            )
        );

        $this->assertTrue(
            $courseClass
                ->assignedClassroom
                ->is($classroom)
        );

        $this->assertTrue(
            $courseClass
                ->assignedTeacher
                ->is($teacher)
        );

        $this->assertTrue(
            $center->courseClasses
                ->contains($courseClass)
        );

        $this->assertTrue(
            $branch->courseClasses
                ->contains($courseClass)
        );

        $this->assertTrue(
            $course->courseClasses
                ->contains($courseClass)
        );

        $this->assertTrue(
            $classroom
                ->assignedCourseClasses
                ->contains($courseClass)
        );

        $this->assertTrue(
            $teacher
                ->assignedCourseClasses
                ->contains($courseClass)
        );
    }

    public function test_database_rejects_branch_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $courseA = Course::factory()
            ->for($centerA)
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $teacherA = Teacher::factory()
            ->create([
                'center_id' => $centerA->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        CourseClass::query()
            ->create(
                $this->attributes(
                    centerId: $centerA->id,
                    branchId: $branchB->id,
                    courseId: $courseA->id,
                    classroomId: $classroomB->id,
                    teacherId: $teacherA->id,
                    code: 'INVALID-BRANCH'
                )
            );
    }

    public function test_database_rejects_course_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->create();

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $teacherA = Teacher::factory()
            ->create([
                'center_id' => $centerA->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        CourseClass::query()
            ->create(
                $this->attributes(
                    centerId: $centerA->id,
                    branchId: $branchA->id,
                    courseId: $courseB->id,
                    classroomId: $classroomA->id,
                    teacherId: $teacherA->id,
                    code: 'INVALID-COURSE'
                )
            );
    }

    public function test_database_rejects_classroom_from_different_branch_in_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->create();

        $course = Course::factory()
            ->for($center)
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $teacher = Teacher::factory()
            ->create([
                'center_id' => $center->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        CourseClass::query()
            ->create(
                $this->attributes(
                    centerId: $center->id,
                    branchId: $branchA->id,
                    courseId: $course->id,
                    classroomId: $classroomB->id,
                    teacherId: $teacher->id,
                    code: 'INVALID-ROOM'
                )
            );
    }

    public function test_database_rejects_teacher_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->create();

        $courseA = Course::factory()
            ->for($centerA)
            ->create();

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $teacherB = Teacher::factory()
            ->create([
                'center_id' => $centerB->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        CourseClass::query()
            ->create(
                $this->attributes(
                    centerId: $centerA->id,
                    branchId: $branchA->id,
                    courseId: $courseA->id,
                    classroomId: $classroomA->id,
                    teacherId: $teacherB->id,
                    code: 'INVALID-TEACHER'
                )
            );
    }

    public function test_class_code_must_be_unique_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        CourseClass::factory()
            ->create([
                'center_id' => $center->id,
                'class_code' => 'CLASS-001',
            ]);

        $this->expectException(
            QueryException::class
        );

        CourseClass::factory()
            ->create([
                'center_id' => $center->id,
                'class_code' => 'CLASS-001',
            ]);
    }

    public function test_same_class_code_is_allowed_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $classA = CourseClass::factory()
            ->create([
                'center_id' => $centerA->id,
                'class_code' => 'CLASS-001',
            ]);

        $classB = CourseClass::factory()
            ->create([
                'center_id' => $centerB->id,
                'class_code' => 'CLASS-001',
            ]);

        $this->assertNotSame(
            $classA->center_id,
            $classB->center_id
        );
    }

    public function test_course_class_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $classA = CourseClass::factory()
            ->create([
                'center_id' => $centerA->id,
            ]);

        $centerB = Center::factory()
            ->active()
            ->create();

        $classB = CourseClass::factory()
            ->create([
                'center_id' => $centerB->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = CourseClass::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $classA->id,
            $ids
        );

        $this->assertNotContains(
            $classB->id,
            $ids
        );
    }

    public function test_course_class_branch_scope_restricts_to_current_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->create();

        $classA = CourseClass::factory()
            ->forBranch($branchA)
            ->create();

        $classB = CourseClass::factory()
            ->forBranch($branchB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $ids = CourseClass::query()
            ->forCurrentBranch()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $classA->id,
            $ids
        );

        $this->assertNotContains(
            $classB->id,
            $ids
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(
        int $centerId,
        int $branchId,
        int $courseId,
        int $classroomId,
        int $teacherId,
        string $code
    ): array {
        return [
            'center_id' => $centerId,
            'branch_id' => $branchId,
            'course_id' => $courseId,

            'assigned_classroom_id' =>
            $classroomId,

            'assigned_teacher_id' =>
            $teacherId,

            'class_code' => $code,
            'name' => 'Foundation Class',
            'start_date' => '2026-09-01',
            'end_date' => '2026-10-01',
            'capacity' => 20,
            'delivery_mode' => 'standard',

            'class_status' =>
            CourseClassStatus::Planned,
        ];
    }
}
