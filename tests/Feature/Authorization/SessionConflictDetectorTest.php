<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Services\Scheduling\SessionConflictDetector;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private SessionConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector =
            app(
                SessionConflictDetector::class
            );
    }

    public function test_teacher_session_overlap_is_detected(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_b']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_a']->id,

                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Teacher conflict with Class Session'
        );

        $this->candidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_classroom_session_overlap_is_detected(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_b']
            )
            ->create([
                'classroom_id' =>
                $context['room_a']->id,

                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Classroom conflict with Class Session'
        );

        $this->candidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_same_class_session_overlap_is_detected(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_a']
            )
            ->create([
                'teacher_id' =>
                $context['teacher_b']->id,

                'classroom_id' =>
                $context['room_b']->id,

                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Course Class conflict with Class Session'
        );

        $this->candidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );
    }

    public function test_adjacent_sessions_are_allowed(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_a']
            )
            ->create([
                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:00:00',
            ]);

        $this->candidate(
            $context,
            startTime: '10:00:00',
            endTime: '11:00:00'
        );

        $this->assertTrue(true);
    }

    public function test_different_session_dates_do_not_conflict(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_a']
            )
            ->create([
                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->candidate(
            $context,
            sessionDate: '2026-09-14'
        );

        $this->assertTrue(true);
    }

    public function test_cancelled_session_does_not_block_resource(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_a']
            )
            ->cancelled()
            ->create([
                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->candidate(
            $context
        );

        $this->assertTrue(true);
    }

    public function test_completed_session_still_blocks_resource(): void
    {
        $context =
            $this->context();

        ClassSession::factory()
            ->forSchedule(
                $context['schedule_b']
            )
            ->completed()
            ->create([
                'teacher_id' =>
                $context['teacher_a']->id,

                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Teacher conflict with Class Session'
        );

        $this->candidate(
            $context
        );
    }

    public function test_active_schedule_without_generated_session_blocks_resource(): void
    {
        $context =
            $this->context();

        /*
         * schedule_b has no concrete Session on 2026-09-07.
         */
        $context['schedule_b']
            ->forceFill([
                'teacher_id' =>
                $context['teacher_a']->id,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Teacher conflict with Class Schedule'
        );

        $this->candidate(
            $context
        );
    }

    public function test_concrete_cancelled_session_overrides_recurring_schedule_for_that_date(): void
    {
        $context =
            $this->context();

        $context['schedule_b']
            ->forceFill([
                'teacher_id' =>
                $context['teacher_a']->id,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ])
            ->save();

        /*
         * This concrete occurrence exists and is cancelled.
         * The recurring Schedule must therefore not reserve
         * its old slot for this particular date.
         */
        ClassSession::factory()
            ->forSchedule(
                $context['schedule_b']
            )
            ->cancelled()
            ->create([
                'teacher_id' =>
                $context['teacher_a']->id,

                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->candidate(
            $context
        );

        $this->assertTrue(true);
    }

    public function test_current_session_can_be_ignored_during_reschedule(): void
    {
        $context =
            $this->context();

        $session =
            ClassSession::factory()
            ->forSchedule(
                $context['schedule_a']
            )
            ->create([
                'session_date' =>
                '2026-09-07',

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',
            ]);

        $this->candidate(
            $context,
            ignoreSessionId: $session->id
        );

        $this->assertTrue(true);
    }

    public function test_parent_schedule_can_be_ignored_for_its_own_generated_session(): void
    {
        $context =
            $this->context();

        $this->candidate(
            $context,
            ignoreScheduleId: $context['schedule_a']->id
        );

        $this->assertTrue(true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function candidate(
        array $context,
        string $sessionDate = '2026-09-07',
        string $startTime = '09:30:00',
        string $endTime = '10:30:00',
        ?int $ignoreSessionId = null,
        ?int $ignoreScheduleId = null
    ): void {
        $this->detector
            ->assertNoConflicts(
                centerId: $context['center']->id,

                classId: $context['class_a']->id,

                teacherId: $context['teacher_a']->id,

                classroomId: $context['room_a']->id,

                sessionDate: $sessionDate,

                startTime: $startTime,

                endTime: $endTime,

                ignoreSessionId: $ignoreSessionId,

                /*
                * The candidate represents an occurrence of schedule_a.
                * Its own recurring parent Schedule must not conflict
                * with the Session being generated or rescheduled.
                */
                ignoreScheduleId: $ignoreScheduleId
                    ?? $context['schedule_a']->id
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
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
                        'opens_at' =>
                        '08:00',

                        'closes_at' =>
                        '18:00',
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

        $scheduleA =
            ClassSchedule::factory()
            ->forCourseClass(
                $classA
            )
            ->active()
            ->create([
                'day_of_week' => 1,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $scheduleB =
            ClassSchedule::factory()
            ->forCourseClass(
                $classB
            )
            ->active()
            ->create([
                'day_of_week' => 1,

                'start_time' =>
                '13:00:00',

                'end_time' =>
                '14:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        return [
            'center' => $center,
            'branch' => $branch,

            'room_a' => $roomA,
            'room_b' => $roomB,

            'teacher_a' => $teacherA,
            'teacher_b' => $teacherB,

            'class_a' => $classA,
            'class_b' => $classB,

            'schedule_a' => $scheduleA,
            'schedule_b' => $scheduleB,
        ];
    }
}
