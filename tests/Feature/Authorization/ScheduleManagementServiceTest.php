<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Scheduling\ScheduleManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );

        $this->service =
            app(
                ScheduleManagementService::class
            );
    }

    public function test_center_owner_can_create_schedule_inside_working_hours(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $this->assertSame(
            $context['center']->id,
            $schedule->center_id
        );

        $this->assertSame(
            $context['class']->id,
            $schedule->class_id
        );

        $this->assertSame(
            $context['room']->id,
            $schedule->classroom_id
        );

        $this->assertSame(
            $context['teacher']->id,
            $schedule->teacher_id
        );

        $this->assertSame(
            ClassScheduleStatus::Active,
            $schedule->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.created',

                'subject_type' =>
                $schedule->getTable(),

                'subject_id' =>
                $schedule->id,
            ]
        );
    }

    public function test_branch_manager_can_create_schedule_in_assigned_branch(): void
    {
        $context =
            $this->context(
                manager: true
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $context['branch']
            );

        $schedule =
            $this->service->create(
                $context['manager'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $this->assertSame(
            $context['class']->id,
            $schedule->class_id
        );
    }

    public function test_branch_manager_cannot_create_schedule_in_another_branch(): void
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

        $this->service->create(
            $context['manager'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_tenant_context_must_match_authenticated_account(): void
    {
        $context =
            $this->context();

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $otherCenter
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'Authenticated account and tenant context do not match.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_schedule_requires_active_course_class(): void
    {
        $context =
            $this->context();

        $context['class']
            ->forceFill([
                'class_status' =>
                CourseClassStatus::Completed,
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Class Schedule can be created only for an Active Course Class.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_classroom_must_belong_to_course_class_branch(): void
    {
        $context =
            $this->context();

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

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->available()
            ->create();

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The Classroom is outside the Course Class Branch scope.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $otherRoom,
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_teacher_must_belong_to_same_center(): void
    {
        $context =
            $this->context();

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $otherCenter->id,
            ]);

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The Teacher is outside the authorized Center scope.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $otherTeacher,
            $this->validAttributes()
        );
    }

    public function test_unavailable_classroom_cannot_be_scheduled(): void
    {
        $context =
            $this->context();

        $context['room']
            ->forceFill([
                'availability_status' =>
                'unavailable',
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The scheduled Classroom must be available.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_deactivated_teacher_cannot_be_scheduled(): void
    {
        $context =
            $this->context();

        $context['teacher']
            ->forceFill([
                'status' =>
                'deactivated',

                'deactivated_at' =>
                now(),
            ])
            ->save();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The scheduled Teacher must be active.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes()
        );
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $context =
            $this->context();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Class Schedule end time must be after start time.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes([
                'start_time' =>
                '10:00',

                'end_time' =>
                '10:00',
            ])
        );
    }

    public function test_effective_period_must_remain_inside_class_dates(): void
    {
        $context =
            $this->context();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Class Schedule effective period must remain inside the Course Class date range.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes([
                'effective_from' =>
                '2026-08-31',
            ])
        );
    }

    public function test_schedule_outside_branch_working_hours_is_rejected(): void
    {
        $context =
            $this->context();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Class Schedule falls outside the approved Branch working hours.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes([
                'start_time' =>
                '07:30',

                'end_time' =>
                '09:00',
            ])
        );
    }

    public function test_schedule_is_rejected_when_selected_day_has_no_working_hours(): void
    {
        $context =
            $this->context();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Branch has no approved working hours for the selected day.'
        );

        $this->service->create(
            $context['owner'],
            $context['class'],
            $context['room'],
            $context['teacher'],
            $this->validAttributes([
                'day_of_week' => 2,
            ])
        );
    }

    public function test_teacher_conflict_prevents_schedule_creation_and_audit(): void
    {
        $context =
            $this->context();

        ClassSchedule::factory()
            ->forCourseClass(
                $context['class']
            )
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

        $auditCountBefore =
            AuditRecord::query()
            ->count();

        $scheduleCountBefore =
            ClassSchedule::query()
            ->withoutGlobalScopes()
            ->count();

        try {
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes([
                    'start_time' =>
                    '10:00',

                    'end_time' =>
                    '11:00',
                ])
            );

            $this->fail(
                'Expected scheduling conflict was not thrown.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'overlapping Class Schedule',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $scheduleCountBefore,
            ClassSchedule::query()
                ->withoutGlobalScopes()
                ->count()
        );

        $this->assertSame(
            $auditCountBefore,
            AuditRecord::query()
                ->count()
        );
    }

    public function test_schedule_resources_can_be_updated_and_audited(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
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
                $schedule,
                [
                    'classroom_id' =>
                    $newRoom->id,

                    'teacher_id' =>
                    $newTeacher->id,
                ]
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
                'class_schedule.updated',

                'subject_type' =>
                $updated->getTable(),

                'subject_id' =>
                $updated->id,
            ]
        );
    }

    public function test_schedule_resource_update_is_idempotent(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $before =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_schedule.updated'
            )
            ->count();

        $this->service->update(
            $context['owner'],
            $schedule,
            [
                'classroom_id' =>
                $schedule->classroom_id,

                'teacher_id' =>
                $schedule->teacher_id,
            ]
        );

        $this->assertSame(
            $before,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_schedule.updated'
                )
                ->count()
        );
    }

    public function test_schedule_cannot_be_updated_to_classroom_in_another_branch(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
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

        $otherRoom =
            Classroom::factory()
            ->forBranch(
                $otherBranch
            )
            ->active()
            ->available()
            ->create();

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The Classroom is outside the Course Class Branch scope.'
        );

        $this->service->update(
            $context['owner'],
            $schedule,
            [
                'classroom_id' =>
                $otherRoom->id,
            ]
        );
    }

    public function test_resource_update_rechecks_schedule_conflicts(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
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

        ClassSchedule::factory()
            ->forCourseClass(
                $otherClass
            )
            ->create([
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
            'The Teacher already has an overlapping Class Schedule.'
        );

        $this->service->update(
            $context['owner'],
            $schedule,
            [
                'teacher_id' =>
                $otherTeacher->id,
            ]
        );
    }

    public function test_schedule_can_be_rescheduled_and_audited(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $updated =
            $this->service->reschedule(
                $context['owner'],
                $schedule,
                $this->validAttributes([
                    'start_time' =>
                    '10:30',

                    'end_time' =>
                    '12:00',
                ])
            );

        $this->assertSame(
            '10:30:00',
            $updated->start_time
        );

        $this->assertSame(
            '12:00:00',
            $updated->end_time
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.rescheduled',

                'subject_type' =>
                $updated->getTable(),

                'subject_id' =>
                $updated->id,
            ]
        );
    }

    public function test_replaying_same_reschedule_does_not_duplicate_audit(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $before =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_schedule.rescheduled'
            )
            ->count();

        $this->service->reschedule(
            $context['owner'],
            $schedule,
            $this->validAttributes()
        );

        $this->assertSame(
            $before,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_schedule.rescheduled'
                )
                ->count()
        );
    }

    public function test_reschedule_revalidates_branch_working_hours(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Class Schedule falls outside the approved Branch working hours.'
        );

        $this->service->reschedule(
            $context['owner'],
            $schedule,
            $this->validAttributes([
                'start_time' =>
                '17:30',

                'end_time' =>
                '18:30',
            ])
        );
    }

    public function test_active_schedule_can_be_cancelled_and_audited(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $cancelled =
            $this->service->cancel(
                $context['owner'],
                $schedule
            );

        $this->assertSame(
            ClassScheduleStatus::Cancelled,
            $cancelled->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'class_schedule.cancelled',

                'subject_type' =>
                $cancelled->getTable(),

                'subject_id' =>
                $cancelled->id,
            ]
        );
    }

    public function test_cancellation_is_idempotent_without_duplicate_audit(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $this->service->cancel(
            $context['owner'],
            $schedule
        );

        $count =
            AuditRecord::query()
            ->where(
                'action_type',
                'class_schedule.cancelled'
            )
            ->count();

        $this->service->cancel(
            $context['owner'],
            $schedule
        );

        $this->assertSame(
            $count,
            AuditRecord::query()
                ->where(
                    'action_type',
                    'class_schedule.cancelled'
                )
                ->count()
        );
    }

    public function test_cancelled_schedule_cannot_be_updated(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $schedule =
            $this->service->cancel(
                $context['owner'],
                $schedule
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Cancelled Class Schedule cannot be updated.'
        );

        $this->service->update(
            $context['owner'],
            $schedule,
            [
                'teacher_id' =>
                $context['teacher']->id,
            ]
        );
    }

    public function test_cancelled_schedule_cannot_be_rescheduled(): void
    {
        $context =
            $this->context();

        $schedule =
            $this->service->create(
                $context['owner'],
                $context['class'],
                $context['room'],
                $context['teacher'],
                $this->validAttributes()
            );

        $schedule =
            $this->service->cancel(
                $context['owner'],
                $schedule
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'A Cancelled Class Schedule cannot be rescheduled.'
        );

        $this->service->reschedule(
            $context['owner'],
            $schedule,
            $this->validAttributes()
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
            ->forBranch($branch)
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
            ->forBranch($branch)
            ->forClassroom($room)
            ->forTeacher($teacher)
            ->active()
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validAttributes(
        array $overrides = []
    ): array {
        return array_merge(
            [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:30',

                'effective_from' =>
                '2026-09-01',

                'effective_until' =>
                '2026-09-30',
            ],
            $overrides
        );
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
