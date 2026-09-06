<?php

namespace App\Services\Enrollment;

use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class EnrollmentManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function enroll(
        User $actor,
        Student $student,
        CourseClass $courseClass,
        array $attributes
    ): Enrollment {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $courseClass,
                $attributes,
                $centerId
            ): Enrollment {
                /*
                 * Enrollment lock ordering is intentionally fixed.
                 *
                 * Course Class is always locked before Student.
                 * Future Enrollment operations must preserve a
                 * deterministic lock order to reduce deadlock risk.
                 */
                $courseClass =
                    $this->lockPersistedCourseClass(
                        $courseClass,
                        $centerId
                    );

                $student =
                    $this->lockPersistedStudent(
                        $student,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            Enrollment::class,
                            $student,
                            $courseClass,
                        ]
                    );

                /*
                 * Authorization Policy validates the persisted
                 * Branch Manager assignment.
                 *
                 * BranchContext independently protects the
                 * operational request scope.
                 */
                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $student->branch_id
                );

                if (! $student->isActive()) {
                    throw new DomainException(
                        'An Archived Student cannot receive a new Enrollment.'
                    );
                }

                /*
                 * Current Enrollment rule:
                 *
                 * Planned and Active Classes accept Enrollment.
                 * Completed and Cancelled Classes do not.
                 */
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
                        'Enrollment is allowed only in a Planned or Active Course Class.'
                    );
                }

                $this->ensureNoDuplicateEnrollment(
                    $centerId,
                    $student,
                    $courseClass
                );

                /*
                 * The Course Class lock serializes capacity changes
                 * for this Class.
                 *
                 * Only Active Enrollment records occupy seats.
                 */
                $this->ensureCapacityAvailable(
                    $centerId,
                    $courseClass
                );

                /*
                 * Current MVP prerequisite rule:
                 *
                 * A prerequisite Course is satisfied when this
                 * Student has a Completed Enrollment in any Class
                 * belonging to that prerequisite Course inside
                 * the same Center.
                 *
                 * requirement_type intentionally has no additional
                 * semantics until approved business values exist.
                 */
                $this->ensurePrerequisitesSatisfied(
                    $centerId,
                    $student,
                    $courseClass
                );

                $enrollmentNumber =
                    $this->requiredEnrollmentNumber(
                        $attributes
                    );

                $enrollmentDate =
                    $this->requiredEnrollmentDate(
                        $attributes
                    );

                $this->ensureEnrollmentNumberAvailable(
                    $centerId,
                    $enrollmentNumber
                );

                /*
                 * center_id, student_id, class_id,
                 * enrollment_status, and eligibility_status
                 * are controlled by the backend.
                 */
                $enrollment =
                    Enrollment::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'student_id' =>
                        $student->id,

                        'class_id' =>
                        $courseClass->id,

                        'enrollment_number' =>
                        $enrollmentNumber,

                        'enrollment_date' =>
                        $enrollmentDate,

                        'enrollment_status' =>
                        EnrollmentStatus::Active,

                        /*
                             * Current MVP eligibility result.
                             *
                             * Enrollment is created only after all
                             * currently approved backend eligibility
                             * checks have succeeded.
                             */
                        'eligibility_status' =>
                        'eligible',

                        'withdrawal_date' =>
                        null,

                        'withdrawal_reason' =>
                        null,
                    ]);

                $enrollment->refresh();

                /*
                 * Enrollment creation is itself a domain-history
                 * event.
                 *
                 * History and Audit remain inside this same
                 * transaction so a later failure rolls back all
                 * successful-operation records.
                 */
                EnrollmentHistory::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'enrollment_id' =>
                        $enrollment->id,

                        'from_class_id' =>
                        null,

                        'to_class_id' =>
                        $courseClass->id,

                        'performed_by_user_id' =>
                        $actor->id,

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

                $this->audit->record(
                    actor: $actor,
                    actionType: 'enrollment.created',
                    subject: $enrollment,
                    afterValues: $this->enrollmentAuditValues(
                        $enrollment
                    )
                );

                return $enrollment;
            },
            3
        );
    }

    public function withdraw(
        User $actor,
        Enrollment $enrollment,
        ?string $reason = null
    ): Enrollment {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $enrollment,
                $reason,
                $centerId
            ): Enrollment {
                /*
             * Lock Enrollment first to resolve its persisted
             * Student/Class references.
             *
             * Shared operational resources then preserve the
             * existing CourseClass -> Student lock ordering.
             */
                $enrollment =
                    $this->lockPersistedEnrollment(
                        $enrollment,
                        $centerId
                    );

                $courseClassReference =
                    CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $enrollment->class_id
                    );

                $courseClass =
                    $this->lockPersistedCourseClass(
                        $courseClassReference,
                        $centerId
                    );

                $studentReference =
                    Student::withoutGlobalScopes()
                    ->findOrFail(
                        $enrollment->student_id
                    );

                $student =
                    $this->lockPersistedStudent(
                        $studentReference,
                        $centerId
                    );

                /*
             * Ensure Policy operates on the exact persisted,
             * locked Student and Course Class instances.
             */
                $enrollment->setRelation(
                    'student',
                    $student
                );

                $enrollment->setRelation(
                    'courseClass',
                    $courseClass
                );

                Gate::forUser($actor)
                    ->authorize(
                        'withdraw',
                        $enrollment
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $student->branch_id
                );

                /*
             * Repeating an already successful withdrawal is
             * idempotent and creates no duplicate History/Audit.
             */
                if ($enrollment->isWithdrawn()) {
                    return $enrollment;
                }

                if (! $enrollment->isActive()) {
                    throw new DomainException(
                        'Only an Active Enrollment can be withdrawn.'
                    );
                }

                $reason =
                    $this->normalizedWithdrawalReason(
                        $reason
                    );

                $beforeValues =
                    $this->enrollmentAuditValues(
                        $enrollment
                    );

                $withdrawalDate =
                    now()->toDateString();

                $enrollment->forceFill([
                    'enrollment_status' =>
                    EnrollmentStatus::Withdrawn,

                    'withdrawal_date' =>
                    $withdrawalDate,

                    'withdrawal_reason' =>
                    $reason,
                ])->save();

                $enrollment->refresh();

                EnrollmentHistory::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'enrollment_id' =>
                        $enrollment->id,

                        /*
                     * Withdrawal removes the Student from the
                     * operational Class without moving to another
                     * Course Class.
                     */
                        'from_class_id' =>
                        $courseClass->id,

                        'to_class_id' =>
                        null,

                        'performed_by_user_id' =>
                        $actor->id,

                        'event_type' =>
                        'withdrawn',

                        'previous_status' =>
                        EnrollmentStatus::Active,

                        'new_status' =>
                        EnrollmentStatus::Withdrawn,

                        'notes' =>
                        $reason,

                        'occurred_at' =>
                        now(),
                    ]);

                $this->audit->record(
                    actor: $actor,
                    actionType: 'enrollment.withdrawn',
                    subject: $enrollment,
                    beforeValues: $beforeValues,
                    afterValues: $this->enrollmentAuditValues(
                        $enrollment
                    )
                );

                return $enrollment;
            },
            3
        );
    }

    public function transfer(
        User $actor,
        Enrollment $enrollment,
        CourseClass $targetClass,
        array $attributes
    ): Enrollment {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $enrollment,
                $targetClass,
                $attributes,
                $centerId
            ): Enrollment {
                /*
             * Enrollment is locked first so source Class and
             * Student identifiers come from persisted state.
             */
                $enrollment =
                    $this->lockPersistedEnrollment(
                        $enrollment,
                        $centerId
                    );

                $sourceClassId =
                    (int) $enrollment->class_id;

                $targetClassId =
                    (int) $targetClass->getKey();

                if (
                    $sourceClassId
                    === $targetClassId
                ) {
                    throw new DomainException(
                        'An Enrollment cannot be transferred to the same Course Class.'
                    );
                }

                /*
             * Source and destination Classes are always locked
             * in ascending ID order.
             *
             * The logical transfer direction must never control
             * lock ordering.
             */
                [
                    $sourceClass,
                    $targetClass,
                ] = $this->lockTransferCourseClasses(
                    $sourceClassId,
                    $targetClassId,
                    $centerId
                );

                $studentReference =
                    Student::withoutGlobalScopes()
                    ->findOrFail(
                        $enrollment->student_id
                    );

                /*
             * Transfer lock order:
             *
             * Enrollment
             * -> Course Classes ordered by ID
             * -> Student
             */
                $student =
                    $this->lockPersistedStudent(
                        $studentReference,
                        $centerId
                    );

                $enrollment->setRelation(
                    'student',
                    $student
                );

                $enrollment->setRelation(
                    'courseClass',
                    $sourceClass
                );

                Gate::forUser($actor)
                    ->authorize(
                        'transfer',
                        [
                            $enrollment,
                            $targetClass,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $sourceClass->branch_id
                );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $targetClass->branch_id
                );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $student->branch_id
                );

                /*
                * A successful transfer repeated toward the exact
                * same target is idempotent.
                */
                if ($enrollment->isTransferred()) {
                    $existingTarget =
                        $this->existingTransferTargetEnrollment(
                            $enrollment,
                            $targetClass
                        );

                    if ($existingTarget !== null) {
                        return $existingTarget;
                    }

                    throw new DomainException(
                        'A Transferred Enrollment cannot be transferred again.'
                    );
                }

                if (! $enrollment->isActive()) {
                    throw new DomainException(
                        'Only an Active Enrollment can be transferred.'
                    );
                }

                if (! $student->isActive()) {
                    throw new DomainException(
                        'An Archived Student cannot be transferred to another Course Class.'
                    );
                }

                if (
                    ! in_array(
                        $targetClass->class_status,
                        [
                            CourseClassStatus::Planned,
                            CourseClassStatus::Active,
                        ],
                        true
                    )
                ) {
                    throw new DomainException(
                        'Transfer is allowed only to a Planned or Active Course Class.'
                    );
                }

                /*
                * Historical Enrollment in the target Class also
                * blocks another Student/Class record.
                */
                $this->ensureNoDuplicateEnrollment(
                    $centerId,
                    $student,
                    $targetClass
                );

                /*
                * Target Class is already locked, therefore capacity
                * checking and creation are serialized.
                */
                $this->ensureCapacityAvailable(
                    $centerId,
                    $targetClass
                );

                $this->ensurePrerequisitesSatisfied(
                    $centerId,
                    $student,
                    $targetClass
                );

                /*
                * Transfer creates a new Enrollment record, therefore
                * it requires its own Center-scoped business identifier
                * and Enrollment date.
                */
                $targetEnrollmentNumber =
                    $this->requiredEnrollmentNumber(
                        $attributes
                    );

                $targetEnrollmentDate =
                    $this->requiredEnrollmentDate(
                        $attributes
                    );

                $this->ensureEnrollmentNumberAvailable(
                    $centerId,
                    $targetEnrollmentNumber
                );

                $sourceBeforeValues =
                    $this->enrollmentAuditValues(
                        $enrollment
                    );

                /*
                * Preserve the source Enrollment as historical.
                */
                $enrollment->forceFill([
                    'enrollment_status' =>
                    EnrollmentStatus::Transferred,
                ])->save();

                $enrollment->refresh();

                /*
                * The target Enrollment is the new operational
                * Enrollment and occupies target Class capacity.
                */
                $targetEnrollment =
                    Enrollment::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'student_id' =>
                        $student->id,

                        'class_id' =>
                        $targetClass->id,

                        'enrollment_number' =>
                        $targetEnrollmentNumber,

                        'enrollment_date' =>
                        $targetEnrollmentDate,

                        'enrollment_status' =>
                        EnrollmentStatus::Active,

                        'eligibility_status' =>
                        'eligible',

                        'withdrawal_date' =>
                        null,

                        'withdrawal_reason' =>
                        null,
                    ]);

                $targetEnrollment->refresh();

                /*
                * Historical event belongs to the source Enrollment
                * because that record transitioned to Transferred.
                */
                EnrollmentHistory::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'enrollment_id' =>
                        $enrollment->id,

                        'from_class_id' =>
                        $sourceClass->id,

                        'to_class_id' =>
                        $targetClass->id,

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

                /*
                * Every Enrollment creation has its own domain
                * history record, including one created by transfer.
                */
                EnrollmentHistory::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'enrollment_id' =>
                        $targetEnrollment->id,

                        'from_class_id' =>
                        null,

                        'to_class_id' =>
                        $targetClass->id,

                        'performed_by_user_id' =>
                        $actor->id,

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

                $this->audit->record(
                    actor: $actor,
                    actionType: 'enrollment.transferred',
                    subject: $enrollment,
                    beforeValues: $sourceBeforeValues,
                    afterValues: $this->enrollmentAuditValues(
                        $enrollment
                    )
                );

                $this->audit->record(
                    actor: $actor,
                    actionType: 'enrollment.created',
                    subject: $targetEnrollment,
                    afterValues: $this->enrollmentAuditValues(
                        $targetEnrollment
                    )
                );

                return $targetEnrollment;
            },
            3
        );
    }

    public function updateStatus(
        User $actor,
        Enrollment $enrollment,
        EnrollmentStatus $targetStatus
    ): Enrollment {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $enrollment,
                $targetStatus,
                $centerId
            ): Enrollment {
                $enrollment =
                    $this->lockPersistedEnrollment(
                        $enrollment,
                        $centerId
                    );

                $courseClassReference =
                    CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $enrollment->class_id
                    );

                $courseClass =
                    $this->lockPersistedCourseClass(
                        $courseClassReference,
                        $centerId
                    );

                $studentReference =
                    Student::withoutGlobalScopes()
                    ->findOrFail(
                        $enrollment->student_id
                    );

                $student =
                    $this->lockPersistedStudent(
                        $studentReference,
                        $centerId
                    );

                $enrollment->setRelation(
                    'student',
                    $student
                );

                $enrollment->setRelation(
                    'courseClass',
                    $courseClass
                );

                Gate::forUser($actor)
                    ->authorize(
                        'updateStatus',
                        $enrollment
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $courseClass->branch_id
                );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $centerId,
                    $student->branch_id
                );

                /*
             * Withdrawn and Transferred have dedicated
             * domain operations and must never be produced
             * by this generic status operation.
             *
             * Returning an Enrollment to Active is also
             * intentionally unsupported.
             */
                if (
                    ! in_array(
                        $targetStatus,
                        [
                            EnrollmentStatus::Completed,
                            EnrollmentStatus::Cancelled,
                        ],
                        true
                    )
                ) {
                    throw new DomainException(
                        'Enrollment status may be updated only to Completed or Cancelled.'
                    );
                }

                /*
             * Repeating the same successful terminal status
             * is idempotent.
             */
                if (
                    $enrollment->enrollment_status
                    === $targetStatus
                ) {
                    return $enrollment;
                }

                /*
             * Completed, Cancelled, Withdrawn, and Transferred
             * are terminal in the current Enrollment lifecycle.
             */
                if (! $enrollment->isActive()) {
                    throw new DomainException(
                        'Only an Active Enrollment may change to Completed or Cancelled.'
                    );
                }

                $beforeValues =
                    $this->enrollmentAuditValues(
                        $enrollment
                    );

                $previousStatus =
                    $enrollment->enrollment_status;

                $enrollment->forceFill([
                    'enrollment_status' =>
                    $targetStatus,
                ])->save();

                $enrollment->refresh();

                $eventType =
                    $targetStatus
                    === EnrollmentStatus::Completed
                    ? 'completed'
                    : 'cancelled';

                /*
             * No Class movement occurs during these status
             * transitions, so from/to Class references remain
             * null. The Enrollment itself already identifies
             * its Course Class.
             */
                EnrollmentHistory::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'enrollment_id' =>
                        $enrollment->id,

                        'from_class_id' =>
                        null,

                        'to_class_id' =>
                        null,

                        'performed_by_user_id' =>
                        $actor->id,

                        'event_type' =>
                        $eventType,

                        'previous_status' =>
                        $previousStatus,

                        'new_status' =>
                        $targetStatus,

                        'notes' =>
                        null,

                        'occurred_at' =>
                        now(),
                    ]);

                $this->audit->record(
                    actor: $actor,
                    actionType: "enrollment.{$eventType}",
                    subject: $enrollment,
                    beforeValues: $beforeValues,
                    afterValues: $this->enrollmentAuditValues(
                        $enrollment
                    )
                );

                return $enrollment;
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

    private function lockPersistedEnrollment(
        Enrollment $enrollment,
        int $centerId
    ): Enrollment {
        $persistedEnrollment =
            Enrollment::withoutGlobalScopes()
            ->whereKey(
                $enrollment->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedEnrollment->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Enrollment is outside the authorized Center scope.'
            );
        }

        return $persistedEnrollment;
    }

    /**
     * @return array{0: CourseClass, 1: CourseClass}
     */
    private function lockTransferCourseClasses(
        int $sourceClassId,
        int $targetClassId,
        int $centerId
    ): array {
        $orderedIds = [
            $sourceClassId,
            $targetClassId,
        ];

        sort(
            $orderedIds,
            SORT_NUMERIC
        );

        $lockedClasses = [];

        foreach ($orderedIds as $classId) {
            $courseClass =
                CourseClass::withoutGlobalScopes()
                ->whereKey(
                    $classId
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

            $lockedClasses[$classId] =
                $courseClass;
        }

        return [
            $lockedClasses[$sourceClassId],
            $lockedClasses[$targetClassId],
        ];
    }

    private function existingTransferTargetEnrollment(
        Enrollment $sourceEnrollment,
        CourseClass $targetClass
    ): ?Enrollment {
        $hasMatchingTransferHistory =
            EnrollmentHistory::withoutGlobalScopes()
            ->where(
                'center_id',
                $sourceEnrollment->center_id
            )
            ->where(
                'enrollment_id',
                $sourceEnrollment->id
            )
            ->where(
                'event_type',
                'transfer'
            )
            ->where(
                'from_class_id',
                $sourceEnrollment->class_id
            )
            ->where(
                'to_class_id',
                $targetClass->id
            )
            ->where(
                'previous_status',
                EnrollmentStatus::Active->value
            )
            ->where(
                'new_status',
                EnrollmentStatus::Transferred->value
            )
            ->exists();

        if (! $hasMatchingTransferHistory) {
            return null;
        }

        $targetEnrollment =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $sourceEnrollment->center_id
            )
            ->where(
                'student_id',
                $sourceEnrollment->student_id
            )
            ->where(
                'class_id',
                $targetClass->id
            )
            ->first();

        if ($targetEnrollment === null) {
            throw new LogicException(
                'Transferred Enrollment target record is missing.'
            );
        }

        return $targetEnrollment;
    }

    private function lockPersistedCourseClass(
        CourseClass $courseClass,
        int $centerId
    ): CourseClass {
        $persistedCourseClass =
            CourseClass::withoutGlobalScopes()
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

        return $persistedCourseClass;
    }

    private function lockPersistedStudent(
        Student $student,
        int $centerId
    ): Student {
        $persistedStudent =
            Student::withoutGlobalScopes()
            ->whereKey(
                $student->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedStudent->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Student record is outside the authorized Center scope.'
            );
        }

        return $persistedStudent;
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
                    'Center Owner Enrollment operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Enrollments.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch Manager Enrollment operations require an assigned Branch context.'
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
                'The Enrollment operation is outside the current Branch scope.'
            );
        }
    }

    private function ensureNoDuplicateEnrollment(
        int $centerId,
        Student $student,
        CourseClass $courseClass
    ): void {
        $exists =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'class_id',
                $courseClass->id
            )
            ->exists();

        if ($exists) {
            throw new DomainException(
                'The Student already has an Enrollment for this Course Class.'
            );
        }
    }

    private function ensureCapacityAvailable(
        int $centerId,
        CourseClass $courseClass
    ): void {
        $activeEnrollmentCount =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'class_id',
                $courseClass->id
            )
            ->where(
                'enrollment_status',
                EnrollmentStatus::Active->value
            )
            ->count();

        if (
            $activeEnrollmentCount
            >= $courseClass->capacity
        ) {
            throw new DomainException(
                'The Course Class has reached its enrollment capacity.'
            );
        }
    }

    private function ensurePrerequisitesSatisfied(
        int $centerId,
        Student $student,
        CourseClass $courseClass
    ): void {
        $prerequisiteCourseIds =
            DB::table(
                'course_prerequisites'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'course_id',
                $courseClass->course_id
            )
            ->pluck(
                'prerequisite_course_id'
            )
            ->map(
                static fn(mixed $id): int =>
                (int) $id
            )
            ->all();

        if ($prerequisiteCourseIds === []) {
            return;
        }

        $completedCourseIds =
            Enrollment::withoutGlobalScopes()
            ->join(
                'course_classes',
                'course_classes.id',
                '=',
                'enrollments.class_id'
            )
            ->where(
                'enrollments.center_id',
                $centerId
            )
            ->where(
                'course_classes.center_id',
                $centerId
            )
            ->where(
                'enrollments.student_id',
                $student->id
            )
            ->where(
                'enrollments.enrollment_status',
                EnrollmentStatus::Completed->value
            )
            ->whereIn(
                'course_classes.course_id',
                $prerequisiteCourseIds
            )
            ->distinct()
            ->pluck(
                'course_classes.course_id'
            )
            ->map(
                static fn(mixed $id): int =>
                (int) $id
            )
            ->all();

        $missingPrerequisiteCourseIds =
            array_diff(
                $prerequisiteCourseIds,
                $completedCourseIds
            );

        if (
            $missingPrerequisiteCourseIds
            !== []
        ) {
            throw new DomainException(
                'The Student has not completed all required prerequisite Courses.'
            );
        }
    }

    private function normalizedWithdrawalReason(
        ?string $reason
    ): ?string {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '') {
            return null;
        }

        if (mb_strlen($reason) > 255) {
            throw new DomainException(
                'Withdrawal reason must not exceed 255 characters.'
            );
        }

        return $reason;
    }

    private function requiredEnrollmentNumber(
        array $attributes
    ): string {
        if (
            ! array_key_exists(
                'enrollment_number',
                $attributes
            )
            || ! is_string(
                $attributes['enrollment_number']
            )
        ) {
            throw new DomainException(
                'Enrollment number is required.'
            );
        }

        $enrollmentNumber =
            trim(
                $attributes['enrollment_number']
            );

        if ($enrollmentNumber === '') {
            throw new DomainException(
                'Enrollment number is required.'
            );
        }

        if (
            mb_strlen(
                $enrollmentNumber
            ) > 50
        ) {
            throw new DomainException(
                'Enrollment number must not exceed 50 characters.'
            );
        }

        return $enrollmentNumber;
    }

    private function requiredEnrollmentDate(
        array $attributes
    ): string {
        if (
            ! array_key_exists(
                'enrollment_date',
                $attributes
            )
        ) {
            throw new DomainException(
                'Enrollment date is required.'
            );
        }

        $value =
            $attributes['enrollment_date'];

        if (
            $value instanceof DateTimeInterface
        ) {
            return $value->format(
                'Y-m-d'
            );
        }

        if (! is_string($value)) {
            throw new DomainException(
                'Enrollment date must use YYYY-MM-DD format.'
            );
        }

        $value = trim($value);

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
                'Enrollment date must use YYYY-MM-DD format.'
            );
        }

        return $value;
    }

    private function ensureEnrollmentNumberAvailable(
        int $centerId,
        string $enrollmentNumber
    ): void {
        $exists =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'enrollment_number',
                $enrollmentNumber
            )
            ->exists();

        if ($exists) {
            throw new DomainException(
                'Enrollment number already exists in this language center.'
            );
        }
    }

    private function enrollmentAuditValues(
        Enrollment $enrollment
    ): array {
        return [
            'id' =>
            (int) $enrollment->id,

            'center_id' =>
            (int) $enrollment->center_id,

            'student_id' =>
            (int) $enrollment->student_id,

            'class_id' =>
            (int) $enrollment->class_id,

            'enrollment_number' =>
            $enrollment->enrollment_number,

            'enrollment_date' =>
            $enrollment->enrollment_date,

            'enrollment_status' =>
            $enrollment->enrollment_status,

            'eligibility_status' =>
            $enrollment->eligibility_status,

            'withdrawal_date' =>
            $enrollment->withdrawal_date,

            'withdrawal_reason' =>
            $enrollment->withdrawal_reason,
        ];
    }
}
