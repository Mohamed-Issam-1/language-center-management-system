<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Scheduling\SessionManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\SystemRole;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );

        $this->service =
            app(
                SessionManagementService::class
            );
    }

    public function test_center_owner_generates_all_schedule_occurrences(): void
    {
        $context =
            $this->context();

        $generated =
            $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        /*
         * Mondays in September 2026:
         * 07, 14, 21, 28.
         */
        $this->assertCount(
            4,
            $generated
        );

        $this->assertSame(
            [
                '2026-09-07',
                '2026-09-14',
                '2026-09-21',
                '2026-09-28',
            ],
            $generated
                ->map(
                    fn(ClassSession $session) =>
                    $session
                        ->session_date
                        ->format('Y-m-d')
                )
                ->values()
                ->all()
        );

        foreach ($generated as $session) {
            $this->assertSame(
                $context['schedule']->id,
                $session->schedule_id
            );

            $this->assertSame(
                $context['class']->id,
                $session->class_id
            );

            $this->assertSame(
                $context['room']->id,
                $session->classroom_id
            );

            $this->assertSame(
                $context['teacher']->id,
                $session->teacher_id
            );

            $this->assertSame(
                '09:00:00',
                $session->start_time
            );

            $this->assertSame(
                '10:30:00',
                $session->end_time
            );
        }

        $this->assertSame(
            4,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.generated'
                )
                ->count()
        );
    }

    public function test_generation_is_idempotent(): void
    {
        $context =
            $this->context();

        $first =
            $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        $second =
            $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        $this->assertCount(
            4,
            $first
        );

        $this->assertCount(
            0,
            $second
        );

        $this->assertSame(
            4,
            ClassSession::query()
                ->withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $context['schedule']->id
                )
                ->count()
        );

        $this->assertSame(
            4,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.generated'
                )
                ->count()
        );
    }

    public function test_existing_occurrence_is_preserved_and_not_regenerated(): void
    {
        $context =
            $this->context();

        $existing =
            ClassSession::factory()
            ->forSchedule(
                $context['schedule']
            )
            ->cancelled()
            ->create([
                'session_date' =>
                '2026-09-14',
            ]);

        $generated =
            $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        $this->assertCount(
            3,
            $generated
        );

        $this->assertDatabaseHas(
            'class_sessions',
            [
                'id' =>
                $existing->id,

                'session_status' =>
                'cancelled',
            ]
        );

        $this->assertSame(
            4,
            ClassSession::query()
                ->withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $context['schedule']->id
                )
                ->count()
        );
    }

    public function test_cancelled_schedule_cannot_generate_sessions(): void
    {
        $context =
            $this->context();

        $context['schedule']
            ->forceFill([
                'status' =>
                ClassScheduleStatus::Cancelled,
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Sessions can be generated only from an Active Class Schedule.'
        );

        $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );
    }

    public function test_branch_manager_can_generate_sessions_in_assigned_branch(): void
    {
        $context =
            $this->context(
                manager: true
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $context['branch']
            );

        $generated =
            $this->service
            ->generateForSchedule(
                $context['manager'],
                $context['schedule']
            );

        $this->assertCount(
            4,
            $generated
        );
    }

    public function test_branch_manager_cannot_generate_sessions_outside_assigned_branch(): void
    {
        $context =
            $this->context(
                manager: true
            );

        $otherBranch =
            Branch::factory()
            ->for(
                $context['center']
            )
            ->active()
            ->create([
                'working_hours' =>
                $this->workingHours(),
            ]);

        app(BranchContext::class)
            ->establishBranchScope(
                $otherBranch
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service
            ->generateForSchedule(
                $context['manager'],
                $context['schedule']
            );
    }

    public function test_generation_revalidates_current_working_hours(): void
    {
        $context =
            $this->context();

        $context['branch']
            ->forceFill([
                'working_hours' => [
                    'monday' => [
                        'opens_at' =>
                        '12:00',

                        'closes_at' =>
                        '18:00',
                    ],
                ],
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Class Session falls outside the approved Branch working hours.'
        );

        $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );
    }

    public function test_conflict_rolls_back_all_sessions_and_audits(): void
    {
        $context =
            $this->context();

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $context['branch']
            )
            ->active()
            ->available()
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $context['center']->id,
            ]);

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $context['branch']
            )
            ->forClassroom(
                $otherRoom
            )
            ->forTeacher(
                $otherTeacher
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        $otherSchedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '13:00:00',
                'end_time' => '14:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        /*
         * Concrete conflicting occurrence on the third Monday.
         */
        ClassSession::factory()
            ->forSchedule(
                $otherSchedule
            )
            ->create([
                'teacher_id' =>
                $context['teacher']->id,

                'session_date' =>
                '2026-09-21',

                'start_time' =>
                '09:30:00',

                'end_time' =>
                '11:00:00',
            ]);

        $sessionCountBefore =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->count();

        $auditCountBefore =
            AuditRecord::query()
            ->count();

        try {
            $this->service
                ->generateForSchedule(
                    $context['owner'],
                    $context['schedule']
                );

            $this->fail(
                'Expected Session conflict was not thrown.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Teacher conflict',
                $exception->getMessage()
            );
        }

        /*
         * Sessions for 07 and 14 may have been inserted before
         * reaching 21, but the transaction must remove them.
         */
        $this->assertSame(
            $sessionCountBefore,
            ClassSession::query()
                ->withoutGlobalScopes()
                ->count()
        );

        $this->assertSame(
            $auditCountBefore,
            AuditRecord::query()
                ->count()
        );

        $this->assertSame(
            0,
            ClassSession::query()
                ->withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $context['schedule']->id
                )
                ->count()
        );
    }

    public function test_scheduled_session_can_update_topic_and_resources(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $newRoom =
            Classroom::factory()
            ->forBranch(
                $context['branch']
            )
            ->active()
            ->available()
            ->create();

        $newTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $context['center']->id,
            ]);

        $updated =
            $this->service->update(
                $context['owner'],
                $session,
                [
                    'topic' =>
                    'Introduction to Grammar',

                    'classroom_id' =>
                    $newRoom->id,

                    'teacher_id' =>
                    $newTeacher->id,
                ]
            );

        $this->assertSame(
            'Introduction to Grammar',
            $updated->topic
        );

        $this->assertSame(
            $newRoom->id,
            $updated->classroom_id
        );

        $this->assertSame(
            $newTeacher->id,
            $updated->teacher_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.updated',

                'subject_type' =>
                $updated->getTable(),

                'subject_id' =>
                $updated->id,
            ]
        );
    }

    public function test_session_update_is_idempotent(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $before =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_session.updated'
            )
            ->count();

        $this->service->update(
            $context['owner'],
            $session,
            [
                'topic' =>
                $session->topic,

                'classroom_id' =>
                $session->classroom_id,

                'teacher_id' =>
                $session->teacher_id,
            ]
        );

        $this->assertSame(
            $before,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.updated'
                )
                ->count()
        );
    }

    public function test_session_resource_update_rechecks_conflicts(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $context['branch']
            )
            ->active()
            ->available()
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $context['center']->id,
            ]);

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $context['branch']
            )
            ->forClassroom(
                $otherRoom
            )
            ->forTeacher(
                $otherTeacher
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        $otherSchedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        ClassSession::factory()
            ->forSchedule(
                $otherSchedule
            )
            ->create([
                'teacher_id' =>
                $otherTeacher->id,

                'session_date' =>
                $session
                    ->session_date
                    ->format('Y-m-d'),

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

        $this->service->update(
            $context['owner'],
            $session,
            [
                'teacher_id' =>
                $otherTeacher->id,
            ]
        );
    }

    public function test_session_can_be_rescheduled_to_another_day(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $rescheduled =
            $this->service->reschedule(
                $context['owner'],
                $session,
                [
                    'session_date' =>
                    '2026-09-08',

                    'start_time' =>
                    '11:00',

                    'end_time' =>
                    '12:30',
                ]
            );

        $session->refresh();

        $this->assertSame(
            '2026-09-07',
            $session
                ->occurrence_date
                ->format('Y-m-d')
        );

        $this->assertSame(
            '2026-09-08',
            $session
                ->session_date
                ->format('Y-m-d')
        );

        $this->assertSame(
            '2026-09-08',
            $rescheduled
                ->session_date
                ->format('Y-m-d')
        );

        $this->assertSame(
            '11:00:00',
            $rescheduled->start_time
        );

        $this->assertSame(
            '12:30:00',
            $rescheduled->end_time
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.rescheduled',

                'subject_type' =>
                $rescheduled->getTable(),

                'subject_id' =>
                $rescheduled->id,
            ]
        );
    }

    public function test_replaying_same_reschedule_is_idempotent(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $before =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_session.rescheduled'
            )
            ->count();

        $this->service->reschedule(
            $context['owner'],
            $session,
            [
                'session_date' =>
                $session
                    ->session_date
                    ->format('Y-m-d'),

                'start_time' =>
                $session->start_time,

                'end_time' =>
                $session->end_time,
            ]
        );

        $this->assertSame(
            $before,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.rescheduled'
                )
                ->count()
        );
    }

    public function test_reschedule_revalidates_actual_day_working_hours(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Class Session falls outside the approved Branch working hours.'
        );

        $this->service->reschedule(
            $context['owner'],
            $session,
            [
                'session_date' =>
                '2026-09-08',

                'start_time' =>
                '17:30',

                'end_time' =>
                '18:30',
            ]
        );
    }

    public function test_reschedule_rechecks_session_conflicts(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $context['branch']
            )
            ->active()
            ->available()
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $context['center']->id,
            ]);

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $context['branch']
            )
            ->forClassroom(
                $otherRoom
            )
            ->forTeacher(
                $otherTeacher
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        $otherSchedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->active()
            ->create([
                'teacher_id' =>
                $context['teacher']->id,

                'day_of_week' =>
                2,

                'start_time' =>
                '11:00:00',

                'end_time' =>
                '12:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        ClassSession::factory()
            ->forSchedule(
                $otherSchedule
            )
            ->create([
                'teacher_id' =>
                $context['teacher']->id,

                'session_date' =>
                '2026-09-08',

                'start_time' =>
                '11:00:00',

                'end_time' =>
                '12:30:00',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Teacher conflict with Class Session'
        );

        $this->service->reschedule(
            $context['owner'],
            $session,
            [
                'session_date' =>
                '2026-09-08',

                'start_time' =>
                '11:30',

                'end_time' =>
                '12:45',
            ]
        );
    }

    public function test_session_cancellation_requires_reason_and_is_audited(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $cancelled =
            $this->service->cancel(
                $context['owner'],
                $session,
                'Teacher unavailable'
            );

        $this->assertSame(
            ClassSessionStatus::Cancelled,
            $cancelled->session_status
        );

        $this->assertSame(
            'Teacher unavailable',
            $cancelled->cancellation_reason
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.cancelled',

                'subject_type' =>
                $cancelled->getTable(),

                'subject_id' =>
                $cancelled->id,
            ]
        );
    }

    public function test_session_cancellation_is_idempotent(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $cancelled =
            $this->service->cancel(
                $context['owner'],
                $session,
                'Original reason'
            );

        $count =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_session.cancelled'
            )
            ->count();

        $again =
            $this->service->cancel(
                $context['owner'],
                $cancelled,
                'Different reason'
            );

        $this->assertSame(
            'Original reason',
            $again->cancellation_reason
        );

        $this->assertSame(
            $count,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.cancelled'
                )
                ->count()
        );
    }

    public function test_scheduled_session_can_be_completed_and_audited(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $completed =
            $this->service->complete(
                $context['owner'],
                $session
            );

        $this->assertSame(
            ClassSessionStatus::Completed,
            $completed->session_status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_session.completed',

                'subject_type' =>
                $completed->getTable(),

                'subject_id' =>
                $completed->id,
            ]
        );
    }

    public function test_session_completion_is_idempotent(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $completed =
            $this->service->complete(
                $context['owner'],
                $session
            );

        $count =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_session.completed'
            )
            ->count();

        $this->service->complete(
            $context['owner'],
            $completed
        );

        $this->assertSame(
            $count,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_session.completed'
                )
                ->count()
        );
    }

    public function test_cancelled_session_cannot_be_completed(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $session =
            $this->service->cancel(
                $context['owner'],
                $session,
                'Cancelled'
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Cancelled Class Session cannot be completed.'
        );

        $this->service->complete(
            $context['owner'],
            $session
        );
    }

    public function test_completed_session_cannot_be_cancelled(): void
    {
        $context =
            $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $session =
            $this->service->complete(
                $context['owner'],
                $session
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Completed Class Session cannot be cancelled.'
        );

        $this->service->cancel(
            $context['owner'],
            $session,
            'Too late'
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function firstGeneratedSession(
        array $context
    ): ClassSession {
        $this->service
            ->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        return ClassSession::query()
            ->withoutGlobalScopes()
            ->where(
                'schedule_id',
                $context['schedule']->id
            )
            ->orderBy(
                'session_date'
            )
            ->firstOrFail();
    }

    public function test_generation_does_not_recreate_original_occurrence_after_reschedule(): void
    {
        $context = $this->context();

        $session =
            $this->firstGeneratedSession(
                $context
            );

        $this->service->reschedule(
            $context['owner'],
            $session,
            [
                'session_date' => '2026-09-08',
                'start_time' => '11:00',
                'end_time' => '12:30',
            ]
        );

        $session->refresh();

        $this->assertSame(
            '2026-09-07',
            $session
                ->occurrence_date
                ->format('Y-m-d')
        );

        $this->assertSame(
            '2026-09-08',
            $session
                ->session_date
                ->format('Y-m-d')
        );

        $generated =
            $this->service->generateForSchedule(
                $context['owner'],
                $context['schedule']
            );

        $this->assertCount(
            0,
            $generated
        );

        $this->assertSame(
            4,
            ClassSession::query()
                ->withoutGlobalScopes()
                ->where(
                    'schedule_id',
                    $context['schedule']->id
                )
                ->count()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        bool $manager = false
    ): array {
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
                'working_hours' =>
                $this->workingHours(),
            ]);

        $room =
            Classroom::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->available()
            ->create();

        $teacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forClassroom(
                $room
            )
            ->forTeacher(
                $teacher
            )
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-09-30',
            ]);

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '10:30:00',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ]);

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $result = [
            'center' =>
            $center,

            'branch' =>
            $branch,

            'room' =>
            $room,

            'teacher' =>
            $teacher,

            'class' =>
            $courseClass,

            'schedule' =>
            $schedule,

            'owner' =>
            $owner,
        ];

        if ($manager) {
            $managerUser =
                $this->createUserForRole(
                    SystemRole::BranchManager,
                    $center
                );

            BranchManagerAssignment::query()
                ->create([
                    'center_id' =>
                    $center->id,

                    'user_id' =>
                    $managerUser->id,

                    'branch_id' =>
                    $branch->id,

                    'started_at' =>
                    now()
                        ->subDay(),

                    'ended_at' =>
                    null,

                    'active_marker' =>
                    1,
                ]);

            $result['manager'] =
                $managerUser;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function workingHours(): array
    {
        return [
            'monday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],

            'tuesday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],
        ];
    }
    private function createUserForRole(
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
