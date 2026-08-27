<?php

namespace App\Services\Scheduling;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ScheduleManagementService
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
        private readonly ScheduleConflictDetector $conflicts,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $actor,
        CourseClass $courseClass,
        Classroom $classroom,
        Teacher $teacher,
        array $attributes
    ): ClassSchedule {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $courseClass,
                $classroom,
                $teacher,
                $attributes,
                $centerId
            ): ClassSchedule {
                /*
                 * Lock order:
                 *
                 * Course Class
                 * → Branch
                 * → Classroom
                 * → Teacher
                 *
                 * Schedule conflict checks run only after
                 * resource rows have been locked.
                 */
                $courseClass =
                    $this->lockCourseClass(
                        $courseClass,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            ClassSchedule::class,
                            $courseClass,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'A Class Schedule can be created only for an Active Course Class.'
                    );
                }

                $branch =
                    $this->lockBranchById(
                        $courseClass->branch_id,
                        $centerId
                    );

                $this->ensureBranchOperational(
                    $branch
                );

                $classroom =
                    $this->lockClassroom(
                        $classroom,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacher(
                        $teacher,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $dayOfWeek =
                    $this->requiredDayOfWeek(
                        $attributes
                    );

                $startTime =
                    $this->requiredTime(
                        $attributes,
                        'start_time',
                        'Class Schedule start time'
                    );

                $endTime =
                    $this->requiredTime(
                        $attributes,
                        'end_time',
                        'Class Schedule end time'
                    );

                $this->ensureTimeRange(
                    $startTime,
                    $endTime
                );

                $effectiveFrom =
                    $this->requiredDate(
                        $attributes,
                        'effective_from',
                        'Class Schedule effective start date'
                    );

                $effectiveUntil =
                    $this->requiredDate(
                        $attributes,
                        'effective_until',
                        'Class Schedule effective end date'
                    );

                $this->ensureEffectivePeriod(
                    $courseClass,
                    $effectiveFrom,
                    $effectiveUntil
                );

                $this->ensureWithinBranchWorkingHours(
                    $branch,
                    $dayOfWeek,
                    $startTime,
                    $endTime
                );

                /*
                 * The resource rows above are locked before
                 * querying conflicts. This serializes competing
                 * operations that share a Class, Classroom,
                 * Teacher, or Branch.
                 */
                $this->conflicts
                    ->assertNoConflicts(
                        centerId: $centerId,

                        classId: $courseClass->id,

                        teacherId: $teacher->id,

                        classroomId: $classroom->id,

                        dayOfWeek: $dayOfWeek,

                        startTime: $startTime,

                        endTime: $endTime,

                        effectiveFrom: $effectiveFrom,

                        effectiveUntil: $effectiveUntil
                    );

                $schedule =
                    ClassSchedule::query()
                    ->withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'class_id' =>
                        $courseClass->id,

                        'classroom_id' =>
                        $classroom->id,

                        'teacher_id' =>
                        $teacher->id,

                        'day_of_week' =>
                        $dayOfWeek,

                        'start_time' =>
                        $startTime,

                        'end_time' =>
                        $endTime,

                        'effective_from' =>
                        $effectiveFrom,

                        'effective_until' =>
                        $effectiveUntil,

                        /*
                             * Caller cannot choose lifecycle
                             * status for a new Schedule.
                             */
                        'status' =>
                        ClassScheduleStatus::Active,
                    ]);

                $schedule->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_schedule.created',
                    subject: $schedule,
                    beforeValues: null,
                    afterValues: $this->scheduleAuditValues(
                        $schedule
                    )
                );

                return $schedule;
            },
            3
        );
    }

    public function update(
        User $actor,
        ClassSchedule $schedule,
        array $attributes
    ): ClassSchedule {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $schedule,
                $attributes,
                $centerId
            ): ClassSchedule {
                $schedule =
                    $this->lockSchedule(
                        $schedule,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $schedule
                    );

                $courseClass =
                    $this->lockCourseClassById(
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
                        'A Cancelled Class Schedule cannot be updated.'
                    );
                }

                $classroomId =
                    $schedule->classroom_id;

                if (
                    array_key_exists(
                        'classroom_id',
                        $attributes
                    )
                ) {
                    $classroomId =
                        $this->positiveIdentifier(
                            $attributes['classroom_id'],
                            'Classroom identifier'
                        );
                }

                $teacherId =
                    $schedule->teacher_id;

                if (
                    array_key_exists(
                        'teacher_id',
                        $attributes
                    )
                ) {
                    $teacherId =
                        $this->positiveIdentifier(
                            $attributes['teacher_id'],
                            'Teacher identifier'
                        );
                }

                /*
             * Repeating the same update is idempotent and must
             * not create another Audit Record.
             */
                if (
                    $classroomId
                    === $schedule->classroom_id
                    && $teacherId
                    === $schedule->teacher_id
                ) {
                    return $schedule;
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'A Class Schedule can be updated only while its Course Class is Active.'
                    );
                }

                $branch =
                    $this->lockBranchById(
                        $courseClass->branch_id,
                        $centerId
                    );

                $this->ensureBranchOperational(
                    $branch
                );

                $classroom =
                    $this->lockClassroomById(
                        $classroomId,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacherById(
                        $teacherId,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $this->conflicts
                    ->assertNoConflicts(
                        centerId: $centerId,

                        classId: $schedule->class_id,

                        teacherId: $teacher->id,

                        classroomId: $classroom->id,

                        dayOfWeek: $schedule->day_of_week,

                        startTime: $schedule->start_time,

                        endTime: $schedule->end_time,

                        effectiveFrom: $schedule
                            ->effective_from
                            ->format('Y-m-d'),

                        effectiveUntil: $schedule
                            ->effective_until
                            ->format('Y-m-d'),

                        ignoreScheduleId: $schedule->id
                    );

                $beforeValues =
                    $this->scheduleAuditValues(
                        $schedule
                    );

                $schedule->forceFill([
                    'classroom_id' =>
                    $classroom->id,

                    'teacher_id' =>
                    $teacher->id,
                ])->save();

                $schedule->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_schedule.updated',
                    subject: $schedule,
                    beforeValues: $beforeValues,
                    afterValues: $this->scheduleAuditValues(
                        $schedule
                    )
                );

                return $schedule;
            },
            3
        );
    }

    public function reschedule(
        User $actor,
        ClassSchedule $schedule,
        array $attributes
    ): ClassSchedule {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $schedule,
                $attributes,
                $centerId
            ): ClassSchedule {
                $schedule =
                    $this->lockSchedule(
                        $schedule,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'reschedule',
                        $schedule
                    );

                $courseClass =
                    $this->lockCourseClassById(
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
                        'A Cancelled Class Schedule cannot be rescheduled.'
                    );
                }

                $dayOfWeek =
                    $this->requiredDayOfWeek(
                        $attributes
                    );

                $startTime =
                    $this->requiredTime(
                        $attributes,
                        'start_time',
                        'Class Schedule start time'
                    );

                $endTime =
                    $this->requiredTime(
                        $attributes,
                        'end_time',
                        'Class Schedule end time'
                    );

                $this->ensureTimeRange(
                    $startTime,
                    $endTime
                );

                $effectiveFrom =
                    $this->requiredDate(
                        $attributes,
                        'effective_from',
                        'Class Schedule effective start date'
                    );

                $effectiveUntil =
                    $this->requiredDate(
                        $attributes,
                        'effective_until',
                        'Class Schedule effective end date'
                    );

                $this->ensureEffectivePeriod(
                    $courseClass,
                    $effectiveFrom,
                    $effectiveUntil
                );

                /*
             * Exact replay is idempotent.
             */
                if (
                    $dayOfWeek
                    === $schedule->day_of_week
                    && $startTime
                    === $schedule->start_time
                    && $endTime
                    === $schedule->end_time
                    && $effectiveFrom
                    === $schedule
                    ->effective_from
                    ->format('Y-m-d')
                    && $effectiveUntil
                    === $schedule
                    ->effective_until
                    ->format('Y-m-d')
                ) {
                    return $schedule;
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'A Class Schedule can be rescheduled only while its Course Class is Active.'
                    );
                }

                $branch =
                    $this->lockBranchById(
                        $courseClass->branch_id,
                        $centerId
                    );

                $this->ensureBranchOperational(
                    $branch
                );

                $classroom =
                    $this->lockClassroomById(
                        $schedule->classroom_id,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacherById(
                        $schedule->teacher_id,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
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

                        classId: $schedule->class_id,

                        teacherId: $teacher->id,

                        classroomId: $classroom->id,

                        dayOfWeek: $dayOfWeek,

                        startTime: $startTime,

                        endTime: $endTime,

                        effectiveFrom: $effectiveFrom,

                        effectiveUntil: $effectiveUntil,

                        ignoreScheduleId: $schedule->id
                    );

                $beforeValues =
                    $this->scheduleAuditValues(
                        $schedule
                    );

                $schedule->forceFill([
                    'day_of_week' =>
                    $dayOfWeek,

                    'start_time' =>
                    $startTime,

                    'end_time' =>
                    $endTime,

                    'effective_from' =>
                    $effectiveFrom,

                    'effective_until' =>
                    $effectiveUntil,
                ])->save();

                $schedule->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_schedule.rescheduled',
                    subject: $schedule,
                    beforeValues: $beforeValues,
                    afterValues: $this->scheduleAuditValues(
                        $schedule
                    )
                );

                return $schedule;
            },
            3
        );
    }

    public function cancel(
        User $actor,
        ClassSchedule $schedule
    ): ClassSchedule {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $schedule,
                $centerId
            ): ClassSchedule {
                $schedule =
                    $this->lockSchedule(
                        $schedule,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'cancel',
                        $schedule
                    );

                $courseClass =
                    $this->lockCourseClassById(
                        $schedule->class_id,
                        $centerId
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                /*
             * Cancellation preserves history and is idempotent.
             *
             * Do not require the Branch, Teacher, Classroom,
             * or Course Class to still be operational merely
             * to close an existing historical Schedule.
             */
                if ($schedule->isCancelled()) {
                    return $schedule;
                }

                $beforeValues =
                    $this->scheduleAuditValues(
                        $schedule
                    );

                $schedule->forceFill([
                    'status' =>
                    ClassScheduleStatus::Cancelled,
                ])->save();

                $schedule->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'class_schedule.cancelled',
                    subject: $schedule,
                    beforeValues: $beforeValues,
                    afterValues: $this->scheduleAuditValues(
                        $schedule
                    )
                );

                return $schedule;
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
        CourseClass $courseClass,
        int $centerId
    ): CourseClass {
        return $this->lockCourseClassById(
            (int) $courseClass->getKey(),
            $centerId
        );
    }

    private function lockCourseClassById(
        int $courseClassId,
        int $centerId
    ): CourseClass {
        $persisted =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseClassId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persisted->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockBranchById(
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
        Classroom $classroom,
        int $centerId,
        int $branchId
    ): Classroom {
        return $this->lockClassroomById(
            (int) $classroom->getKey(),
            $centerId,
            $branchId
        );
    }

    private function lockClassroomById(
        int $classroomId,
        int $centerId,
        int $branchId
    ): Classroom {
        $persisted =
            Classroom::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persisted->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Classroom is outside the authorized Center scope.'
            );
        }

        if (
            $persisted->branch_id
            !== $branchId
        ) {
            throw new AuthorizationException(
                'The Classroom is outside the Course Class Branch scope.'
            );
        }

        return $persisted;
    }

    private function lockTeacher(
        Teacher $teacher,
        int $centerId
    ): Teacher {
        return $this->lockTeacherById(
            (int) $teacher->getKey(),
            $centerId
        );
    }

    private function lockTeacherById(
        int $teacherId,
        int $centerId
    ): Teacher {
        $persisted =
            Teacher::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persisted->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Teacher is outside the authorized Center scope.'
            );
        }

        return $persisted;
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
                    'Center Owner Schedule operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Class Schedules.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch Manager Schedule operations require an assigned Branch context.'
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
                'The Class Schedule operation is outside the assigned Branch scope.'
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
                'A Class Schedule cannot be created in a deactivated Branch.'
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
                'The scheduled Classroom must be active.'
            );
        }

        if (
            $classroom->availability_status
            !== ClassroomAvailabilityStatus::Available
        ) {
            throw new DomainException(
                'The scheduled Classroom must be available.'
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
                'The scheduled Teacher must be active.'
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

    private function requiredDayOfWeek(
        array $attributes
    ): int {
        if (
            ! array_key_exists(
                'day_of_week',
                $attributes
            )
        ) {
            throw new DomainException(
                'Class Schedule day of week is required.'
            );
        }

        $value =
            $attributes['day_of_week'];

        if (
            is_int($value)
        ) {
            $day = $value;
        } elseif (
            is_string($value)
            && ctype_digit(
                trim($value)
            )
        ) {
            $day =
                (int) trim($value);
        } else {
            throw new DomainException(
                'Class Schedule day of week must be an integer from 1 to 7.'
            );
        }

        if (
            $day < 1
            || $day > 7
        ) {
            throw new DomainException(
                'Class Schedule day of week must be an integer from 1 to 7.'
            );
        }

        return $day;
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

        if (
            strlen($value) === 5
        ) {
            $value .= ':00';
        }

        return $value;
    }

    private function ensureTimeRange(
        string $startTime,
        string $endTime
    ): void {
        if (
            $startTime >= $endTime
        ) {
            throw new DomainException(
                'Class Schedule end time must be after start time.'
            );
        }
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
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value =
            $attributes[$key];

        if (
            $value instanceof DateTimeInterface
        ) {
            return $value->format(
                'Y-m-d'
            );
        }

        if (
            ! is_string($value)
        ) {
            throw new DomainException(
                "{$label} must use YYYY-MM-DD format."
            );
        }

        $value = trim($value);

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

    private function ensureEffectivePeriod(
        CourseClass $courseClass,
        string $effectiveFrom,
        string $effectiveUntil
    ): void {
        if (
            $effectiveFrom
            > $effectiveUntil
        ) {
            throw new DomainException(
                'Class Schedule effective end date must not be before its effective start date.'
            );
        }

        $classStart =
            $courseClass
            ->start_date
            ->format('Y-m-d');

        $classEnd =
            $courseClass
            ->end_date
            ->format('Y-m-d');

        if (
            $effectiveFrom < $classStart
            || $effectiveUntil > $classEnd
        ) {
            throw new DomainException(
                'Class Schedule effective period must remain inside the Course Class date range.'
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
            self::DAY_NAMES[$dayOfWeek];

        $dayHours =
            $workingHours[$dayName] ?? null;

        if (
            ! is_array($dayHours)
        ) {
            throw new DomainException(
                'The Branch has no approved working hours for the selected day.'
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
                'The approved Branch working hours are invalid for the selected day.'
            );
        }

        if (
            $startTime < $opensAt
            || $endTime > $closesAt
        ) {
            throw new DomainException(
                'The Class Schedule falls outside the approved Branch working hours.'
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
     * @return array<string, mixed>
     */
    private function scheduleAuditValues(
        ClassSchedule $schedule
    ): array {
        return [
            'center_id' =>
            $schedule->center_id,

            'class_id' =>
            $schedule->class_id,

            'classroom_id' =>
            $schedule->classroom_id,

            'teacher_id' =>
            $schedule->teacher_id,

            'day_of_week' =>
            $schedule->day_of_week,

            'start_time' =>
            $schedule->start_time,

            'end_time' =>
            $schedule->end_time,

            'effective_from' =>
            $schedule
                ->effective_from
                ->format('Y-m-d'),

            'effective_until' =>
            $schedule
                ->effective_until
                ->format('Y-m-d'),

            'status' =>
            $schedule->status->value,
        ];
    }
}
