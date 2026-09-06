<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_schedule_stores_required_relationships_and_casts(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->validSchedulingContext();

        $schedule = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->active()
            ->create();

        $this->assertSame(
            $center->id,
            $schedule->center_id
        );

        $this->assertTrue(
            $schedule->courseClass
                ->is($courseClass)
        );

        $this->assertTrue(
            $schedule->classroom
                ->is(
                    $courseClass
                        ->assignedClassroom
                )
        );

        $this->assertTrue(
            $schedule->teacher
                ->is(
                    $courseClass
                        ->assignedTeacher
                )
        );

        $this->assertSame(
            ClassScheduleStatus::Active,
            $schedule->status
        );

        $this->assertTrue(
            $schedule->isActive()
        );

        $this->assertFalse(
            $schedule->isCancelled()
        );
    }

    public function test_cancelled_schedule_status_is_cast_to_enum(): void
    {
        [,
            $courseClass,
        ] = $this->validSchedulingContext();

        $schedule = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->cancelled()
            ->create();

        $this->assertSame(
            ClassScheduleStatus::Cancelled,
            $schedule->status
        );

        $this->assertTrue(
            $schedule->isCancelled()
        );
    }

    public function test_class_session_stores_schedule_snapshot_and_status(): void
    {
        [,
            $courseClass,
        ] = $this->validSchedulingContext();

        $schedule = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->create();

        $session = ClassSession::factory()
            ->forSchedule($schedule)
            ->scheduled()
            ->create();

        $this->assertSame(
            $schedule
                ->effective_from
                ->toDateString(),
            $session
                ->occurrence_date
                ->toDateString()
        );

        $this->assertSame(
            $session
                ->occurrence_date
                ->toDateString(),
            $session
                ->session_date
                ->toDateString()
        );

        $this->assertTrue(
            $session->schedule
                ->is($schedule)
        );

        $this->assertTrue(
            $session->courseClass
                ->is($courseClass)
        );

        $this->assertTrue(
            $session->classroom
                ->is($schedule->classroom)
        );

        $this->assertTrue(
            $session->teacher
                ->is($schedule->teacher)
        );

        $this->assertSame(
            ClassSessionStatus::Scheduled,
            $session->session_status
        );

        $this->assertTrue(
            $session->isScheduled()
        );
    }

    public function test_completed_and_cancelled_session_states_are_supported(): void
    {
        [,
            $courseClass,
        ] = $this->validSchedulingContext();

        $scheduleA = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->create();

        $scheduleB = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->create([
                'day_of_week' => 2,
            ]);

        $completed = ClassSession::factory()
            ->forSchedule($scheduleA)
            ->completed()
            ->create();

        $cancelled = ClassSession::factory()
            ->forSchedule($scheduleB)
            ->cancelled()
            ->create();

        $this->assertTrue(
            $completed->isCompleted()
        );

        $this->assertTrue(
            $cancelled->isCancelled()
        );

        $this->assertNotNull(
            $cancelled->cancellation_reason
        );
    }

    public function test_schedule_cannot_reference_class_from_another_center(): void
    {
        [
            $centerA,
            $courseClassA,
        ] = $this->validSchedulingContext();

        $centerB = Center::factory()
            ->active()
            ->create();

        $this->expectException(
            QueryException::class
        );

        ClassSchedule::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $centerB->id,

                'class_id' =>
                $courseClassA->id,

                'classroom_id' =>
                $courseClassA
                    ->assigned_classroom_id,

                'teacher_id' =>
                $courseClassA
                    ->assigned_teacher_id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                $courseClassA
                    ->start_date
                    ->toDateString(),

                'effective_until' =>
                $courseClassA
                    ->end_date
                    ->toDateString(),

                'status' =>
                ClassScheduleStatus::Active,
            ]);
    }

    public function test_schedule_cannot_reference_classroom_from_another_center(): void
    {
        [
            $centerA,
            $courseClassA,
        ] = $this->validSchedulingContext();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        ClassSchedule::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $centerA->id,

                'class_id' =>
                $courseClassA->id,

                'classroom_id' =>
                $classroomB->id,

                'teacher_id' =>
                $courseClassA
                    ->assigned_teacher_id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                $courseClassA
                    ->start_date
                    ->toDateString(),

                'effective_until' =>
                $courseClassA
                    ->end_date
                    ->toDateString(),

                'status' =>
                ClassScheduleStatus::Active,
            ]);
    }

    public function test_schedule_cannot_reference_teacher_from_another_center(): void
    {
        [
            $centerA,
            $courseClassA,
        ] = $this->validSchedulingContext();

        $centerB = Center::factory()
            ->active()
            ->create();

        $teacherB = Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        ClassSchedule::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $centerA->id,

                'class_id' =>
                $courseClassA->id,

                'classroom_id' =>
                $courseClassA
                    ->assigned_classroom_id,

                'teacher_id' =>
                $teacherB->id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                $courseClassA
                    ->start_date
                    ->toDateString(),

                'effective_until' =>
                $courseClassA
                    ->end_date
                    ->toDateString(),

                'status' =>
                ClassScheduleStatus::Active,
            ]);
    }

    public function test_session_schedule_and_class_must_match(): void
    {
        [
            $center,
            $courseClassA,
        ] = $this->validSchedulingContext();

        $branch = $courseClassA->branch;

        $classroomB = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacherB = Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClassB = CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($classroomB)
            ->forTeacher($teacherB)
            ->active()
            ->create();

        $scheduleA = ClassSchedule::factory()
            ->forCourseClass($courseClassA)
            ->create();

        $this->expectException(
            QueryException::class
        );

        ClassSession::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $center->id,

                'class_id' =>
                $courseClassB->id,

                'schedule_id' =>
                $scheduleA->id,

                'occurrence_date' =>
                $scheduleA
                    ->effective_from
                    ->toDateString(),

                'classroom_id' =>
                $scheduleA->classroom_id,

                'teacher_id' =>
                $scheduleA->teacher_id,

                'session_date' =>
                $scheduleA
                    ->effective_from
                    ->toDateString(),

                'start_time' =>
                $scheduleA->start_time,

                'end_time' =>
                $scheduleA->end_time,

                'topic' => null,

                'session_status' =>
                ClassSessionStatus::Scheduled,

                'cancellation_reason' =>
                null,
            ]);
    }

    public function test_same_schedule_cannot_generate_duplicate_session_for_same_date(): void
    {
        [,
            $courseClass,
        ] = $this->validSchedulingContext();

        $schedule = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->create();

        ClassSession::factory()
            ->forSchedule($schedule)
            ->create();

        $this->expectException(
            QueryException::class
        );

        ClassSession::factory()
            ->forSchedule($schedule)
            ->create();
    }

    public function test_same_schedule_cannot_materialize_same_occurrence_twice_after_reschedule(): void
    {
        [,
            $courseClass,
        ] = $this->validSchedulingContext();

        $schedule = ClassSchedule::factory()
            ->forCourseClass($courseClass)
            ->create();

        $occurrenceDate =
            $schedule
            ->effective_from
            ->toDateString();

        ClassSession::factory()
            ->forSchedule($schedule)
            ->create([
                'occurrence_date' =>
                $occurrenceDate,

                'session_date' =>
                $occurrenceDate,
            ]);

        $this->expectException(
            QueryException::class
        );

        ClassSession::factory()
            ->forSchedule($schedule)
            ->create([
                /*
             * Same recurring occurrence,
             * but a different actual Session date.
             */
                'occurrence_date' =>
                $occurrenceDate,

                'session_date' =>
                $schedule
                    ->effective_from
                    ->copy()
                    ->addDay()
                    ->toDateString(),
            ]);
    }

    /**
     * @return array{Center, CourseClass}
     */
    private function validSchedulingContext(): array
    {
        $center = Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create([
                'working_hours' => [
                    'monday' => [
                        'opens_at' => '08:00',
                        'closes_at' => '18:00',
                    ],
                ],
            ]);

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacher = Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->active()
            ->create();

        return [
            $center,
            $courseClass,
        ];
    }
}
