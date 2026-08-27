<?php

namespace App\Services\Scheduling;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SessionManagementService
{
    private const DAY_NAMES = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly SessionConflictDetector $conflicts,
        private readonly AuditRecorder $audit
    ) {}

    /**
     * Generate missing concrete Sessions for every occurrence
     * of the recurring Class Schedule.
     *
     * Existing occurrences are preserved and skipped.
     *
     * @return Collection<int, ClassSession>
     */
    public function generateForSchedule(
        User $actor,
        ClassSchedule $schedule
    ): Collection {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $schedule,
                $centerId
            ): Collection {
                $schedule =
                    $this->lockSchedule(
                        $schedule,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            ClassSession::class,
                            $schedule,
                        ]
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $schedule->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                if (! $schedule->isActive()) {
                    throw new DomainException(
                        'Sessions can be generated only from an Active Class Schedule.'
                    );
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'Sessions can be generated only for an Active Course Class.'
                    );
                }

                $branch =
                    $this->lockBranch(
                        $courseClass->branch_id,
                        $centerId
                    );

                $this->ensureBranchOperational(
                    $branch
                );

                $classroom =
                    $this->lockClassroom(
                        $schedule->classroom_id,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacher(
                        $schedule->teacher_id,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $this->ensureWithinBranchWorkingHours(
                    $branch,
                    $schedule->day_of_week,
                    $schedule->start_time,
                    $schedule->end_time
                );

                $generated = collect();

                foreach (
                    $this->occurrenceDates(
                        $schedule
                    ) as $sessionDate
                ) {
                    /*
                     * Historical occurrence already exists.
                     *
                     * It may be Scheduled, Completed, Cancelled,
                     * or later Rescheduled. Never recreate it.
                     */
                    $existing =
                        ClassSession::query()
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'schedule_id',
                            $schedule->id
                        )
                        ->where(
                            'occurrence_date',
                            $sessionDate
                        )
                        ->first();

                    if ($existing !== null) {
                        continue;
                    }

                    $this->conflicts
                        ->assertNoConflicts(
                            centerId: $centerId,

                            classId: $schedule->class_id,

                            teacherId: $teacher->id,

                            classroomId: $classroom->id,

                            sessionDate: $sessionDate,

                            startTime: $schedule->start_time,

                            endTime: $schedule->end_time,

                            /*
                             * The recurring parent Schedule is
                             * the source of this occurrence and
                             * therefore cannot conflict with it.
                             */
                            ignoreScheduleId: $schedule->id
                        );

                    $session =
                        ClassSession::query()
                        ->withoutGlobalScopes()
                        ->create([
                            'center_id' =>
                            $centerId,

                            'class_id' =>
                            $schedule->class_id,

                            'schedule_id' =>
                            $schedule->id,

                            'occurrence_date' =>
                            $sessionDate,

                            /*
                                 * Snapshot operational resources
                                 * from the Schedule.
                                 */
                            'classroom_id' =>
                            $classroom->id,

                            'teacher_id' =>
                            $teacher->id,

                            'session_date' =>
                            $sessionDate,

                            'start_time' =>
                            $schedule->start_time,

                            'end_time' =>
                            $schedule->end_time,

                            'topic' =>
                            null,

                            'session_status' =>
                            ClassSessionStatus::Scheduled,

                            'cancellation_reason' =>
                            null,
                        ]);

                    $session->refresh();

                    $this->audit->record(
                        actor: $actor,
                        actionType: 'class_session.generated',
                        subject: $session,
                        beforeValues: null,
                        afterValues: $this->sessionAuditValues(
                            $session
                        )
                    );

                    $generated->push(
                        $session
                    );
                }

                return $generated;
            },
            3
        );
    }

    public function update(
        User $actor,
        ClassSession $session,
        array $attributes
    ): ClassSession {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'topic',
                'classroom_id',
                'teacher_id',
            ],
            'Class Session update'
        );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $session,
                $attributes,
                $centerId
            ): ClassSession {
                $session =
                    $this->lockSession(
                        $session,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $session
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $session->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                if (! $session->isScheduled()) {
                    throw new DomainException(
                        'Only a Scheduled Class Session can be updated.'
                    );
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'A Class Session can be updated only while its Course Class is Active.'
                    );
                }

                $topic =
                    array_key_exists(
                        'topic',
                        $attributes
                    )
                    ? $this->nullableText(
                        $attributes['topic'],
                        'Class Session topic'
                    )
                    : $session->topic;

                $classroomId =
                    array_key_exists(
                        'classroom_id',
                        $attributes
                    )
                    ? $this->positiveIdentifier(
                        $attributes['classroom_id'],
                        'Classroom identifier'
                    )
                    : $session->classroom_id;

                $teacherId =
                    array_key_exists(
                        'teacher_id',
                        $attributes
                    )
                    ? $this->positiveIdentifier(
                        $attributes['teacher_id'],
                        'Teacher identifier'
                    )
                    : $session->teacher_id;

                if (
                    $topic === $session->topic
                    && $classroomId
                    === $session->classroom_id
                    && $teacherId
                    === $session->teacher_id
                ) {
                    return $session;
                }

                $resourcesChanged =
                    $classroomId
                    !== $session->classroom_id
                    || $teacherId
                    !== $session->teacher_id;

                if ($resourcesChanged) {
                    $branch =
                        $this->lockBranch(
                            $courseClass->branch_id,
                            $centerId
                        );

                    $this->ensureBranchOperational(
                        $branch
                    );

                    $classroom =
                        $this->lockClassroom(
                            $classroomId,
                            $centerId,
                            $courseClass->branch_id
                        );

                    $this->ensureClassroomOperational(
                        $classroom
                    );

                    $teacher =
                        $this->lockTeacher(
                            $teacherId,
                            $centerId
                        );

                    $this->ensureTeacherOperational(
                        $teacher
                    );

                    $sessionDate =
                        $session
                        ->session_date
                        ->format('Y-m-d');

                    $dayOfWeek =
                        $this->dayOfWeekForDate(
                            $sessionDate
                        );

                    $this->ensureWithinBranchWorkingHours(
                        $branch,
                        $dayOfWeek,
                        $session->start_time,
                        $session->end_time
                    );

                    $this->conflicts
                        ->assertNoConflicts(
                            centerId: $centerId,

                            classId: $session->class_id,

                            teacherId: $teacherId,

                            classroomId: $classroomId,

                            sessionDate: $sessionDate,

                            startTime: $session->start_time,

                            endTime: $session->end_time,

                            ignoreSessionId: $session->id,

                            ignoreScheduleId: $session->schedule_id
                        );
                }

                $beforeValues =
                    $this->sessionAuditValues(
                        $session
                    );

                $session->forceFill([
                    'topic' =>
                    $topic,

                    'classroom_id' =>
                    $classroomId,

                    'teacher_id' =>
                    $teacherId,
                ])->save();

                $session->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_session.updated',
                    subject: $session,
                    beforeValues: $beforeValues,
                    afterValues: $this->sessionAuditValues(
                        $session
                    )
                );

                return $session;
            },
            3
        );
    }

    public function reschedule(
        User $actor,
        ClassSession $session,
        array $attributes
    ): ClassSession {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'session_date',
                'start_time',
                'end_time',
            ],
            'Class Session reschedule'
        );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $session,
                $attributes,
                $centerId
            ): ClassSession {
                $session =
                    $this->lockSession(
                        $session,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'reschedule',
                        $session
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $session->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                if (! $session->isScheduled()) {
                    throw new DomainException(
                        'Only a Scheduled Class Session can be rescheduled.'
                    );
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'A Class Session can be rescheduled only while its Course Class is Active.'
                    );
                }

                $sessionDate =
                    $this->requiredDate(
                        $attributes,
                        'session_date',
                        'Class Session date'
                    );

                $startTime =
                    $this->requiredTime(
                        $attributes,
                        'start_time',
                        'Class Session start time'
                    );

                $endTime =
                    $this->requiredTime(
                        $attributes,
                        'end_time',
                        'Class Session end time'
                    );

                $this->ensureTimeRange(
                    $startTime,
                    $endTime
                );

                $this->ensureSessionDateInsideClass(
                    $courseClass,
                    $sessionDate
                );

                if (
                    $sessionDate
                    === $session
                    ->session_date
                    ->format('Y-m-d')
                    && $startTime
                    === $session->start_time
                    && $endTime
                    === $session->end_time
                ) {
                    return $session;
                }

                $this->ensureNoDuplicateScheduleDate(
                    $session,
                    $sessionDate,
                    $centerId
                );

                $branch =
                    $this->lockBranch(
                        $courseClass->branch_id,
                        $centerId
                    );

                $this->ensureBranchOperational(
                    $branch
                );

                $classroom =
                    $this->lockClassroom(
                        $session->classroom_id,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacher(
                        $session->teacher_id,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $dayOfWeek =
                    $this->dayOfWeekForDate(
                        $sessionDate
                    );

                $this->ensureWithinBranchWorkingHours(
                    $branch,
                    $dayOfWeek,
                    $startTime,
                    $endTime
                );

                $this->conflicts
                    ->assertNoConflicts(
                        centerId: $centerId,

                        classId: $session->class_id,

                        teacherId: $session->teacher_id,

                        classroomId: $session->classroom_id,

                        sessionDate: $sessionDate,

                        startTime: $startTime,

                        endTime: $endTime,

                        ignoreSessionId: $session->id,

                        /*
                     * This concrete occurrence belongs to the
                     * parent Schedule, so that Schedule itself
                     * must not conflict with the exception.
                     */
                        ignoreScheduleId: $session->schedule_id
                    );

                $beforeValues =
                    $this->sessionAuditValues(
                        $session
                    );

                $session->forceFill([
                    'session_date' =>
                    $sessionDate,

                    'start_time' =>
                    $startTime,

                    'end_time' =>
                    $endTime,
                ])->save();

                $session->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_session.rescheduled',
                    subject: $session,
                    beforeValues: $beforeValues,
                    afterValues: $this->sessionAuditValues(
                        $session
                    )
                );

                return $session;
            },
            3
        );
    }

    public function cancel(
        User $actor,
        ClassSession $session,
        string $reason
    ): ClassSession {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $session,
                $reason,
                $centerId
            ): ClassSession {
                $session =
                    $this->lockSession(
                        $session,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'cancel',
                        $session
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $session->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                /*
             * Cancellation is idempotent. The first recorded
             * reason remains authoritative.
             */
                if ($session->isCancelled()) {
                    return $session;
                }

                if ($session->isCompleted()) {
                    throw new DomainException(
                        'A Completed Class Session cannot be cancelled.'
                    );
                }

                $reason =
                    $this->requiredText(
                        $reason,
                        'Class Session cancellation reason'
                    );

                $beforeValues =
                    $this->sessionAuditValues(
                        $session
                    );

                $session->forceFill([
                    'session_status' =>
                    ClassSessionStatus::Cancelled,

                    'cancellation_reason' =>
                    $reason,
                ])->save();

                $session->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_session.cancelled',
                    subject: $session,
                    beforeValues: $beforeValues,
                    afterValues: $this->sessionAuditValues(
                        $session
                    )
                );

                return $session;
            },
            3
        );
    }

    public function complete(
        User $actor,
        ClassSession $session
    ): ClassSession {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $session,
                $centerId
            ): ClassSession {
                $session =
                    $this->lockSession(
                        $session,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'complete',
                        $session
                    );

                $courseClass =
                    $this->lockCourseClass(
                        $session->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                if ($session->isCompleted()) {
                    return $session;
                }

                if ($session->isCancelled()) {
                    throw new DomainException(
                        'A Cancelled Class Session cannot be completed.'
                    );
                }

                $beforeValues =
                    $this->sessionAuditValues(
                        $session
                    );

                $session->forceFill([
                    'session_status' =>
                    ClassSessionStatus::Completed,

                    'cancellation_reason' =>
                    null,
                ])->save();

                $session->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_session.completed',
                    subject: $session,
                    beforeValues: $beforeValues,
                    afterValues: $this->sessionAuditValues(
                        $session
                    )
                );

                return $session;
            },
            3
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center =
            $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function lockSession(
        ClassSession $session,
        int $centerId
    ): ClassSession {
        $persisted =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $session->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persisted->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Class Session is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockSchedule(
        ClassSchedule $schedule,
        int $centerId
    ): ClassSchedule {
        $persisted =
            ClassSchedule::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $schedule->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persisted->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Class Schedule is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockCourseClass(
        int $courseClassId,
        int $centerId
    ): CourseClass {
        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseClassId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $courseClass->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        return $courseClass;
    }

    private function lockBranch(
        int $branchId,
        int $centerId
    ): Branch {
        $branch =
            Branch::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $branchId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $branch->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Branch is outside the authorized Center scope.'
            );
        }

        return $branch;
    }

    private function lockClassroom(
        int $classroomId,
        int $centerId,
        int $branchId
    ): Classroom {
        $classroom =
            Classroom::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $classroom->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Classroom is outside the authorized Center scope.'
            );
        }

        if (
            $classroom->branch_id
            !== $branchId
        ) {
            throw new AuthorizationException(
                'The Classroom is outside the Course Class Branch scope.'
            );
        }

        return $classroom;
    }

    private function lockTeacher(
        int $teacherId,
        int $centerId
    ): Teacher {
        $teacher =
            Teacher::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $teacher->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Teacher is outside the authorized Center scope.'
            );
        }

        return $teacher;
    }

    private function ensureOperationalBranchScope(
        User $actor,
        int $centerId,
        int $branchId
    ): void {
        if (
            ! $this->branchContext
                ->isEstablished()
        ) {
            throw new AuthorizationException(
                'Branch operational context has not been established.'
            );
        }

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $this->branchContext
                    ->isCenterWide()
            ) {
                throw new AuthorizationException(
                    'Center Owner Session operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Class Sessions.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch Manager Session operations require an assigned Branch context.'
            );
        }

        $contextBranch =
            $this->branchContext
            ->branch();

        if (
            $contextBranch === null
            || $contextBranch->center_id
            !== $centerId
            || $contextBranch->id
            !== $branchId
        ) {
            throw new AuthorizationException(
                'The Class Session operation is outside the assigned Branch scope.'
            );
        }
    }

    private function ensureBranchOperational(
        Branch $branch
    ): void {
        if (
            $branch->status
            !== BranchStatus::Active
        ) {
            throw new DomainException(
                'Class Sessions cannot be generated in a deactivated Branch.'
            );
        }
    }

    private function ensureClassroomOperational(
        Classroom $classroom
    ): void {
        if (
            $classroom->status
            !== ClassroomStatus::Active
        ) {
            throw new DomainException(
                'The Session Classroom must be active.'
            );
        }

        if (
            $classroom->availability_status
            !== ClassroomAvailabilityStatus::Available
        ) {
            throw new DomainException(
                'The Session Classroom must be available.'
            );
        }
    }

    private function ensureTeacherOperational(
        Teacher $teacher
    ): void {
        if (
            $teacher->status
            !== StaffStatus::Active
        ) {
            throw new DomainException(
                'The Session Teacher must be active.'
            );
        }
    }

    private function assertOnlyAllowedKeys(
        array $attributes,
        array $allowedKeys,
        string $operation
    ): void {
        $unexpected =
            array_values(
                array_diff(
                    array_keys($attributes),
                    $allowedKeys
                )
            );

        if ($unexpected !== []) {
            throw new DomainException(
                "{$operation} contains unsupported fields: "
                    . implode(', ', $unexpected)
                    . '.'
            );
        }
    }

    private function positiveIdentifier(
        mixed $value,
        string $label
    ): int {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit(
                trim($value)
            )
            && (int) trim($value) > 0
        ) {
            return (int) trim($value);
        }

        throw new DomainException(
            "{$label} must be a positive integer."
        );
    }

    private function nullableText(
        mixed $value,
        string $label
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must be text or null."
            );
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 255) {
            throw new DomainException(
                "{$label} must not exceed 255 characters."
            );
        }

        return $value;
    }

    private function requiredText(
        mixed $value,
        string $label
    ): string {
        $value =
            $this->nullableText(
                $value,
                $label
            );

        if ($value === null) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        return $value;
    }

    private function requiredDate(
        array $attributes,
        string $key,
        string $label
    ): string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
            || ! is_string(
                $attributes[$key]
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value =
            trim(
                $attributes[$key]
            );

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

        $errors =
            DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $date->format('Y-m-d')
            !== $value
        ) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD format."
            );
        }

        return $value;
    }

    private function requiredTime(
        array $attributes,
        string $key,
        string $label
    ): string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
            || ! is_string(
                $attributes[$key]
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value =
            trim(
                $attributes[$key]
            );

        if (
            ! preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
                $value
            )
        ) {
            throw new DomainException(
                "{$label} must use HH:MM or HH:MM:SS format."
            );
        }

        if (strlen($value) === 5) {
            $value .= ':00';
        }

        return $value;
    }

    private function ensureTimeRange(
        string $startTime,
        string $endTime
    ): void {
        if ($startTime >= $endTime) {
            throw new DomainException(
                'Class Session end time must be after start time.'
            );
        }
    }

    private function dayOfWeekForDate(
        string $sessionDate
    ): int {
        return (int) (
            new DateTimeImmutable(
                $sessionDate
            )
        )->format('N');
    }

    private function ensureSessionDateInsideClass(
        CourseClass $courseClass,
        string $sessionDate
    ): void {
        $classStart =
            $courseClass
            ->start_date
            ->format('Y-m-d');

        $classEnd =
            $courseClass
            ->end_date
            ->format('Y-m-d');

        if (
            $sessionDate < $classStart
            || $sessionDate > $classEnd
        ) {
            throw new DomainException(
                'Class Session date must remain inside the Course Class date range.'
            );
        }
    }

    private function ensureNoDuplicateScheduleDate(
        ClassSession $session,
        string $sessionDate,
        int $centerId
    ): void {
        $exists =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'schedule_id',
                $session->schedule_id
            )
            ->where(
                'session_date',
                $sessionDate
            )
            ->whereKeyNot(
                $session->id
            )
            ->exists();

        if ($exists) {
            throw new DomainException(
                'The Class Schedule already has another Class Session on the selected date.'
            );
        }
    }

    private function ensureWithinBranchWorkingHours(
        Branch $branch,
        int $dayOfWeek,
        string $startTime,
        string $endTime
    ): void {
        $workingHours =
            $branch->working_hours;

        if (
            ! is_array($workingHours)
        ) {
            throw new DomainException(
                'The Branch does not have approved working hours.'
            );
        }

        $dayName =
            self::DAY_NAMES[$dayOfWeek] ?? null;

        if ($dayName === null) {
            throw new DomainException(
                'Class Schedule day of week must be an integer from 1 to 7.'
            );
        }

        $dayHours =
            $workingHours[$dayName] ?? null;

        if (
            ! is_array($dayHours)
        ) {
            throw new DomainException(
                'The Branch has no approved working hours for the scheduled day.'
            );
        }

        $opensAt =
            $this->workingHourTime(
                $dayHours['opens_at']
                    ?? null
            );

        $closesAt =
            $this->workingHourTime(
                $dayHours['closes_at']
                    ?? null
            );

        if (
            $opensAt === null
            || $closesAt === null
            || $opensAt >= $closesAt
        ) {
            throw new DomainException(
                'The approved Branch working hours are invalid for the scheduled day.'
            );
        }

        if (
            $startTime < $opensAt
            || $endTime > $closesAt
        ) {
            throw new DomainException(
                'The Class Session falls outside the approved Branch working hours.'
            );
        }
    }

    private function workingHourTime(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            ! preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
                $value
            )
        ) {
            return null;
        }

        if (
            strlen($value) === 5
        ) {
            $value .= ':00';
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceDates(
        ClassSchedule $schedule
    ): array {
        $from =
            new DateTimeImmutable(
                $schedule
                    ->effective_from
                    ->format('Y-m-d')
            );

        $until =
            new DateTimeImmutable(
                $schedule
                    ->effective_until
                    ->format('Y-m-d')
            );

        /*
         * DatePeriod normally excludes the end date, so use the
         * following day as the exclusive boundary.
         */
        $period =
            new DatePeriod(
                $from,
                new DateInterval('P1D'),
                $until->modify('+1 day')
            );

        $dates = [];

        foreach ($period as $date) {
            if (
                (int) $date->format('N')
                !== $schedule->day_of_week
            ) {
                continue;
            }

            $dates[] =
                $date->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionAuditValues(
        ClassSession $session
    ): array {
        return [
            'center_id' =>
            $session->center_id,

            'class_id' =>
            $session->class_id,

            'schedule_id' =>
            $session->schedule_id,

            'classroom_id' =>
            $session->classroom_id,

            'teacher_id' =>
            $session->teacher_id,

            'session_date' =>
            $session
                ->session_date
                ->format('Y-m-d'),

            'start_time' =>
            $session->start_time,

            'end_time' =>
            $session->end_time,

            'topic' =>
            $session->topic,

            'session_status' =>
            $session
                ->session_status
                ->value,

            'cancellation_reason' =>
            $session->cancellation_reason,
        ];
    }
}
