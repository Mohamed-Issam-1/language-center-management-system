<?php

namespace App\Services\CourseClasses;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
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

final class CourseClassManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $actor,
        Branch $branch,
        Course $course,
        Classroom $classroom,
        Teacher $teacher,
        array $attributes
    ): CourseClass {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $branch,
                $course,
                $classroom,
                $teacher,
                $attributes,
                $centerId
            ): CourseClass {
                $branch = $this->lockBranch(
                    $branch,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            CourseClass::class,
                            $branch,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $branch->id
                );

                if (
                    $branch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Course Class cannot be created in a deactivated Branch.'
                    );
                }

                $course = $this->lockCourse(
                    $course,
                    $centerId
                );

                if (
                    $course->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course Class cannot be created for an archived Course.'
                    );
                }

                $classroom = $this->lockClassroom(
                    $classroom,
                    $centerId,
                    $branch->id
                );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher = $this->lockTeacher(
                    $teacher,
                    $centerId
                );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $capacity =
                    $this->requiredPositiveInteger(
                        $attributes,
                        'capacity',
                        'Course Class capacity'
                    );

                $this->ensureCapacityFitsClassroom(
                    $capacity,
                    $classroom
                );

                $startDate =
                    $this->requiredDate(
                        $attributes,
                        'start_date',
                        'Course Class start date'
                    );

                $endDate =
                    $this->requiredDate(
                        $attributes,
                        'end_date',
                        'Course Class end date'
                    );

                $this->ensureDateRange(
                    $startDate,
                    $endDate
                );

                /*
                 * Tenant ownership, Branch, Course, resources,
                 * and lifecycle state are derived from persisted
                 * authorized records.
                 */
                $courseClass =
                    CourseClass::query()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'branch_id' =>
                        $branch->id,

                        'course_id' =>
                        $course->id,

                        'assigned_classroom_id' =>
                        $classroom->id,

                        'assigned_teacher_id' =>
                        $teacher->id,

                        'class_code' =>
                        $this->requiredString(
                            $attributes,
                            'class_code',
                            'Course Class code',
                            50
                        ),

                        'name' =>
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Course Class name',
                            255
                        ),

                        'start_date' =>
                        $startDate,

                        'end_date' =>
                        $endDate,

                        'capacity' =>
                        $capacity,

                        'delivery_mode' =>
                        $this->requiredString(
                            $attributes,
                            'delivery_mode',
                            'Course Class delivery mode',
                            50
                        ),

                        'class_status' =>
                        CourseClassStatus::Planned,
                    ]);

                $courseClass->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course_class.created',
                    subject: $courseClass,
                    afterValues: $this->courseClassAuditValues(
                        $courseClass
                    )
                );

                return $courseClass;
            },
            3
        );
    }

    public function update(
        User $actor,
        CourseClass $courseClass,
        array $attributes
    ): CourseClass {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $courseClass,
                $attributes,
                $centerId
            ): CourseClass {
                $courseClass =
                    $this->lockCourseClassInCurrentScope(
                        $actor,
                        $courseClass,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $courseClass
                    );

                /*
                 * Re-read immutable parents.
                 *
                 * General updates cannot move a Class to another
                 * Center, Branch, or Course.
                 */
                $branch =
                    $this->lockBranchById(
                        $courseClass->branch_id,
                        $centerId
                    );

                $course =
                    $this->lockCourseById(
                        $courseClass->course_id,
                        $centerId
                    );

                /*
                 * Existing historical Classes remain maintainable
                 * even if their Branch or Course is later made
                 * inactive. Activation rules will be enforced by
                 * the lifecycle operation separately.
                 */
                $classroomId =
                    $courseClass
                    ->assigned_classroom_id;

                if (
                    array_key_exists(
                        'assigned_classroom_id',
                        $attributes
                    )
                ) {
                    $classroomId =
                        $this->positiveIdentifier(
                            $attributes['assigned_classroom_id'],
                            'Assigned Classroom identifier'
                        );
                }

                $classroom =
                    $this->lockClassroomById(
                        $classroomId,
                        $centerId,
                        $branch->id
                    );

                /*
                 * A newly assigned Classroom must be operational.
                 *
                 * We also revalidate the existing Classroom when
                 * capacity is changed because Classroom capacity
                 * bounds the Course Class.
                 */
                if (
                    array_key_exists(
                        'assigned_classroom_id',
                        $attributes
                    )
                    || array_key_exists(
                        'capacity',
                        $attributes
                    )
                ) {
                    $this->ensureClassroomOperational(
                        $classroom
                    );
                }

                $teacherId =
                    $courseClass
                    ->assigned_teacher_id;

                if (
                    array_key_exists(
                        'assigned_teacher_id',
                        $attributes
                    )
                ) {
                    $teacherId =
                        $this->positiveIdentifier(
                            $attributes['assigned_teacher_id'],
                            'Assigned Teacher identifier'
                        );
                }

                $teacher =
                    $this->lockTeacherById(
                        $teacherId,
                        $centerId
                    );

                if (
                    array_key_exists(
                        'assigned_teacher_id',
                        $attributes
                    )
                ) {
                    $this->ensureTeacherOperational(
                        $teacher
                    );
                }

                $capacity =
                    $courseClass->capacity;

                if (
                    array_key_exists(
                        'capacity',
                        $attributes
                    )
                ) {
                    $capacity =
                        $this->requiredPositiveInteger(
                            $attributes,
                            'capacity',
                            'Course Class capacity'
                        );
                }

                $this->ensureCapacityFitsClassroom(
                    $capacity,
                    $classroom
                );

                $startDate =
                    $this->dateValue(
                        $courseClass->start_date
                    );

                if (
                    array_key_exists(
                        'start_date',
                        $attributes
                    )
                ) {
                    $startDate =
                        $this->requiredDate(
                            $attributes,
                            'start_date',
                            'Course Class start date'
                        );
                }

                $endDate =
                    $this->dateValue(
                        $courseClass->end_date
                    );

                if (
                    array_key_exists(
                        'end_date',
                        $attributes
                    )
                ) {
                    $endDate =
                        $this->requiredDate(
                            $attributes,
                            'end_date',
                            'Course Class end date'
                        );
                }

                $this->ensureDateRange(
                    $startDate,
                    $endDate
                );

                $beforeValues =
                    $this->courseClassAuditValues(
                        $courseClass
                    );

                $data = [];

                if (
                    array_key_exists(
                        'class_code',
                        $attributes
                    )
                ) {
                    $data['class_code'] =
                        $this->requiredString(
                            $attributes,
                            'class_code',
                            'Course Class code',
                            50
                        );
                }

                if (
                    array_key_exists(
                        'name',
                        $attributes
                    )
                ) {
                    $data['name'] =
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Course Class name',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'start_date',
                        $attributes
                    )
                ) {
                    $data['start_date'] =
                        $startDate;
                }

                if (
                    array_key_exists(
                        'end_date',
                        $attributes
                    )
                ) {
                    $data['end_date'] =
                        $endDate;
                }

                if (
                    array_key_exists(
                        'capacity',
                        $attributes
                    )
                ) {
                    $data['capacity'] =
                        $capacity;
                }

                if (
                    array_key_exists(
                        'delivery_mode',
                        $attributes
                    )
                ) {
                    $data['delivery_mode'] =
                        $this->requiredString(
                            $attributes,
                            'delivery_mode',
                            'Course Class delivery mode',
                            50
                        );
                }

                if (
                    array_key_exists(
                        'assigned_classroom_id',
                        $attributes
                    )
                ) {
                    $data['assigned_classroom_id'] = $classroom->id;
                }

                if (
                    array_key_exists(
                        'assigned_teacher_id',
                        $attributes
                    )
                ) {
                    $data['assigned_teacher_id'] = $teacher->id;
                }

                /*
                 * center_id, branch_id, course_id, and class_status
                 * are intentionally excluded.
                 */
                $courseClass->fill(
                    $data
                );

                if (! $courseClass->isDirty()) {
                    return $courseClass;
                }

                $courseClass->save();
                $courseClass->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course_class.updated',
                    subject: $courseClass,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseClassAuditValues(
                        $courseClass
                    )
                );

                return $courseClass;
            },
            3
        );
    }

    public function activate(
        User $actor,
        CourseClass $courseClass
    ): CourseClass {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $courseClass,
                $centerId
            ): CourseClass {
                $courseClass =
                    $this->lockCourseClassInCurrentScope(
                        $actor,
                        $courseClass,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'transition',
                        $courseClass
                    );

                /*
             * Repeating an already-successful activation is
             * idempotent and produces no duplicate Audit Record.
             */
                if (
                    $courseClass->class_status
                    === CourseClassStatus::Active
                ) {
                    return $courseClass;
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Planned
                ) {
                    throw new DomainException(
                        'Only a Planned Course Class can be activated.'
                    );
                }

                /*
             * Activation is the operational boundary.
             *
             * Re-read and lock every required parent/resource so
             * activation cannot rely on stale model state.
             */
                $branch =
                    $this->lockBranchById(
                        $courseClass->branch_id,
                        $centerId
                    );

                if (
                    $branch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Course Class cannot be activated while its Branch is deactivated.'
                    );
                }

                $course =
                    $this->lockCourseById(
                        $courseClass->course_id,
                        $centerId
                    );

                if (
                    $course->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course Class cannot be activated while its Course is archived.'
                    );
                }

                $classroom =
                    $this->lockClassroomById(
                        $courseClass
                            ->assigned_classroom_id,
                        $centerId,
                        $courseClass->branch_id
                    );

                $this->ensureClassroomOperational(
                    $classroom
                );

                $teacher =
                    $this->lockTeacherById(
                        $courseClass
                            ->assigned_teacher_id,
                        $centerId
                    );

                $this->ensureTeacherOperational(
                    $teacher
                );

                $this->ensureCapacityFitsClassroom(
                    $courseClass->capacity,
                    $classroom
                );

                $startDate =
                    $this->dateValue(
                        $courseClass->start_date
                    );

                $endDate =
                    $this->dateValue(
                        $courseClass->end_date
                    );

                $this->ensureDateRange(
                    $startDate,
                    $endDate
                );

                $beforeValues =
                    $this->courseClassAuditValues(
                        $courseClass
                    );

                $courseClass->forceFill([
                    'class_status' =>
                    CourseClassStatus::Active,
                ])->save();

                $courseClass->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course_class.activated',
                    subject: $courseClass,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseClassAuditValues(
                        $courseClass
                    )
                );

                return $courseClass;
            },
            3
        );
    }

    public function complete(
        User $actor,
        CourseClass $courseClass
    ): CourseClass {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $courseClass,
                $centerId
            ): CourseClass {
                $courseClass =
                    $this->lockCourseClassInCurrentScope(
                        $actor,
                        $courseClass,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'transition',
                        $courseClass
                    );

                if (
                    $courseClass->class_status
                    === CourseClassStatus::Completed
                ) {
                    return $courseClass;
                }

                if (
                    $courseClass->class_status
                    !== CourseClassStatus::Active
                ) {
                    throw new DomainException(
                        'Only an Active Course Class can be completed.'
                    );
                }

                /*
             * Completion closes an already-operational Class.
             *
             * Parent resources are not required to remain active
             * just to preserve the ability to close historical
             * operational records.
             */
                $beforeValues =
                    $this->courseClassAuditValues(
                        $courseClass
                    );

                $courseClass->forceFill([
                    'class_status' =>
                    CourseClassStatus::Completed,
                ])->save();

                $courseClass->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course_class.completed',
                    subject: $courseClass,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseClassAuditValues(
                        $courseClass
                    )
                );

                return $courseClass;
            },
            3
        );
    }

    public function cancel(
        User $actor,
        CourseClass $courseClass
    ): CourseClass {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $courseClass,
                $centerId
            ): CourseClass {
                $courseClass =
                    $this->lockCourseClassInCurrentScope(
                        $actor,
                        $courseClass,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'transition',
                        $courseClass
                    );

                if (
                    $courseClass->class_status
                    === CourseClassStatus::Cancelled
                ) {
                    return $courseClass;
                }

                if (
                    ! in_array(
                        $courseClass->class_status,
                        [
                            CourseClassStatus::Planned,
                            CourseClassStatus::Active,
                        ],
                        true
                    )
                ) {
                    throw new DomainException(
                        'Only a Planned or Active Course Class can be cancelled.'
                    );
                }

                $beforeValues =
                    $this->courseClassAuditValues(
                        $courseClass
                    );

                $courseClass->forceFill([
                    'class_status' =>
                    CourseClassStatus::Cancelled,
                ])->save();

                $courseClass->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course_class.cancelled',
                    subject: $courseClass,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseClassAuditValues(
                        $courseClass
                    )
                );

                return $courseClass;
            },
            3
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center = $this->tenant
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

    private function lockCourseClassInCurrentScope(
        User $actor,
        CourseClass $courseClass,
        int $centerId
    ): CourseClass {
        $persistedCourseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseClass->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedCourseClass->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        $this->ensureOperationalBranchScope(
            $actor,
            $centerId,
            $persistedCourseClass->branch_id
        );

        return $persistedCourseClass;
    }

    private function lockBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        return $this->lockBranchById(
            (int) $branch->getKey(),
            $centerId
        );
    }

    private function lockBranchById(
        int $branchId,
        int $centerId
    ): Branch {
        $branch = Branch::query()
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

    private function lockCourse(
        Course $course,
        int $centerId
    ): Course {
        return $this->lockCourseById(
            (int) $course->getKey(),
            $centerId
        );
    }

    private function lockCourseById(
        int $courseId,
        int $centerId
    ): Course {
        $course = Course::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $courseId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $course->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Course is outside the authorized Center scope.'
            );
        }

        return $course;
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
        $classroom = Classroom::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $classroom->center_id
            !== $centerId
            || $classroom->branch_id
            !== $branchId
        ) {
            throw new AuthorizationException(
                'The Classroom is outside the authorized Branch scope.'
            );
        }

        return $classroom;
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
        $teacher = Teacher::query()
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
                    'Center Owner Course Class operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Course Classes.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch Manager Course Class operations require an assigned Branch context.'
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
                'The Course Class operation is outside the assigned Branch scope.'
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
                'The assigned Classroom must be active.'
            );
        }

        if (
            $classroom->availability_status
            !== ClassroomAvailabilityStatus::Available
        ) {
            throw new DomainException(
                'The assigned Classroom must be available.'
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
                'The assigned Teacher must be active.'
            );
        }
    }

    private function ensureCapacityFitsClassroom(
        int $capacity,
        Classroom $classroom
    ): void {
        if (
            $capacity
            > $classroom->capacity
        ) {
            throw new DomainException(
                'Course Class capacity cannot exceed Classroom capacity.'
            );
        }
    }

    private function ensureDateRange(
        string $startDate,
        string $endDate
    ): void {
        if (
            $endDate
            < $startDate
        ) {
            throw new DomainException(
                'Course Class end date cannot be before start date.'
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
            || ! is_string(
                $attributes[$key]
            )
        ) {
            throw new DomainException(
                "{$label} is required in YYYY-MM-DD format."
            );
        }

        $value = trim(
            $attributes[$key]
        );

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

        if (
            $date === false
            || $date->format('Y-m-d')
            !== $value
        ) {
            throw new DomainException(
                "{$label} must use a valid YYYY-MM-DD date."
            );
        }

        return $value;
    }

    private function dateValue(
        mixed $value
    ): string {
        if (
            $value instanceof DateTimeInterface
        ) {
            return $value->format(
                'Y-m-d'
            );
        }

        if (is_string($value)) {
            return $value;
        }

        throw new DomainException(
            'Persisted Course Class date is invalid.'
        );
    }

    private function requiredString(
        array $attributes,
        string $key,
        string $label,
        int $maxLength
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

        $value = trim(
            $attributes[$key]
        );

        if ($value === '') {
            throw new DomainException(
                "{$label} is required."
            );
        }

        if (
            mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function requiredPositiveInteger(
        array $attributes,
        string $key,
        string $label
    ): int {
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

        return $this->positiveIdentifier(
            $attributes[$key],
            $label
        );
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
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new DomainException(
            "{$label} must be a positive integer."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function courseClassAuditValues(
        CourseClass $courseClass
    ): array {
        return [
            'center_id' =>
            $courseClass->center_id,

            'branch_id' =>
            $courseClass->branch_id,

            'course_id' =>
            $courseClass->course_id,

            'assigned_classroom_id' =>
            $courseClass
                ->assigned_classroom_id,

            'assigned_teacher_id' =>
            $courseClass
                ->assigned_teacher_id,

            'class_code' =>
            $courseClass->class_code,

            'name' =>
            $courseClass->name,

            'start_date' =>
            $courseClass->start_date,

            'end_date' =>
            $courseClass->end_date,

            'capacity' =>
            $courseClass->capacity,

            'delivery_mode' =>
            $courseClass
                ->delivery_mode,

            'class_status' =>
            $courseClass
                ->class_status,
        ];
    }
}
