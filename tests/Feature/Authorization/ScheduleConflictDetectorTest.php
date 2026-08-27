<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Services\Scheduling\ScheduleConflictDetector;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector =
            app(
                ScheduleConflictDetector::class
            );
    }

    public function test_teacher_overlap_is_detected(): void
    {
        $context =
            $this->schedulingContext();

        $conflict =
            ClassSchedule::factory()
            ->forCourseClass(
                $context['class_b']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_a']->id,

                'classroom_id' =>
                $context['room_b']->id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Teacher already has an overlapping Class Schedule. '
                . "Conflict: Schedule #{$conflict->id}, "
                . "resource #{$context['teacher_a']->id}, "
                . 'time 09:00:00-10:30:00.'
        );

        $this->assertCandidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_classroom_overlap_is_detected(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_b']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_b']->id,

                'classroom_id' =>
                $context['room_a']->id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Classroom already has an overlapping Class Schedule.'
        );

        $this->assertCandidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_same_class_overlap_is_detected(): void
    {
        $context =
            $this->schedulingContext();

        /*
         * Use different Teacher and Classroom so the Class
         * conflict is isolated from the other two checks.
         */
        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_b']->id,

                'classroom_id' =>
                $context['room_b']->id,

                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Course Class already has an overlapping Class Schedule.'
        );

        $this->assertCandidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_adjacent_time_ranges_are_allowed(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->assertCandidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );

        $this->assertTrue(true);
    }

    public function test_non_overlapping_effective_periods_are_allowed(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-15',
            ]);

        $this->assertCandidate(
            $context,
            effectiveFrom: '2026-09-16',
            effectiveUntil: '2026-09-30'
        );

        $this->assertTrue(true);
    }

    public function test_different_weekdays_do_not_conflict(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->assertCandidate(
            $context,
            dayOfWeek: 2
        );

        $this->assertTrue(true);
    }

    public function test_cancelled_schedules_do_not_block_new_schedule(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->cancelled()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->assertCandidate(
            $context
        );

        $this->assertTrue(true);
    }

    public function test_current_schedule_can_be_excluded_during_update(): void
    {
        $context =
            $this->schedulingContext();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $context['class_a']
            )
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $this->assertCandidate(
            $context,
            ignoreScheduleId: $schedule->id
        );

        $this->assertTrue(true);
    }

    public function test_partially_overlapping_effective_period_is_detected(): void
    {
        $context =
            $this->schedulingContext();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class_b']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_a']->id,

                'classroom_id' =>
                $context['room_b']->id,

                'day_of_week' => 1,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',

                'effective_from' =>
                '2026-09-10',

                'effective_until' =>
                '2026-09-20',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Teacher already has an overlapping Class Schedule.'
        );

        $this->assertCandidate(
            $context,
            effectiveFrom: '2026-09-01',
            effectiveUntil: '2026-09-15'
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertCandidate(
        array $context,
        int $dayOfWeek = 1,
        string $startTime = '09:30:00',
        string $endTime = '10:30:00',
        string $effectiveFrom = '2026-09-01',
        string $effectiveUntil = '2026-09-30',
        ?int $ignoreScheduleId = null
    ): void {
        $this->detector
            ->assertNoConflicts(
                centerId: $context['center']->id,

                classId: $context['class_a']->id,

                teacherId: $context['teacher_a']->id,

                classroomId: $context['room_a']->id,

                dayOfWeek: $dayOfWeek,

                startTime: $startTime,

                endTime: $endTime,

                effectiveFrom: $effectiveFrom,

                effectiveUntil: $effectiveUntil,

                ignoreScheduleId: $ignoreScheduleId
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulingContext(): array
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $branch =
            Branch::factory()
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

        $roomA =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $roomB =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $teacherA =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $teacherB =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $classA =
            CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($roomA)
            ->forTeacher($teacherA)
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        $classB =
            CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($roomB)
            ->forTeacher($teacherB)
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        return [
            'center' =>
            $center,

            'branch' =>
            $branch,

            'room_a' =>
            $roomA,

            'room_b' =>
            $roomB,

            'teacher_a' =>
            $teacherA,

            'teacher_b' =>
            $teacherB,

            'class_a' =>
            $classA,

            'class_b' =>
            $classB,
        ];
    }
}
