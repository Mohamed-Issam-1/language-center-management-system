<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AttendanceManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function record(
        User $actor,
        ClassSession $session,
        Enrollment $enrollment,
        array $attributes
    ): Attendance {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'attendance_status_id',
                'late_minutes',
                'excuse',
                'notes',
            ],
            'Attendance recording'
        );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $session,
                $enrollment,
                $attributes,
                $centerId
            ): Attendance {
                /*
                 * Lock order for Attendance creation:
                 *
                 * Session -> Enrollment -> Course Class
                 * -> Attendance Status.
                 *
                 * Session lifecycle changes already lock the
                 * Session first, while Enrollment lifecycle
                 * operations do not lock a Session, avoiding
                 * an opposing shared-resource lock cycle.
                 */
                $session =
                    $this->lockSession(
                        $session,
                        $centerId
                    );

                $enrollment =
                    $this->lockEnrollment(
                        $enrollment,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            Attendance::class,
                            $session,
                            $enrollment,
                        ]
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

                $this->ensureSessionAcceptsAttendance(
                    $session
                );

                /*
                 * New Attendance must match the currently
                 * persisted Session/Class pair.
                 */
                if (
                    $enrollment->class_id
                    !== $session->class_id
                ) {
                    throw new DomainException(
                        'The Enrollment does not belong to the Class Session Course Class.'
                    );
                }

                $this->ensureEnrollmentEligibleForSession(
                    $enrollment,
                    $session
                );

                $statusId =
                    $this->requiredPositiveInteger(
                        $attributes,
                        'attendance_status_id',
                        'Attendance Status'
                    );

                $status =
                    $this->lockAttendanceStatusById(
                        $statusId,
                        $centerId
                    );

                if (! $status->isActive()) {
                    throw new DomainException(
                        'An inactive Attendance Status cannot be used for a new Attendance record.'
                    );
                }

                $this->ensureNoExistingAttendance(
                    $centerId,
                    $session->id,
                    $enrollment->id
                );

                $lateMinutes =
                    array_key_exists(
                        'late_minutes',
                        $attributes
                    )
                    ? $this->nonNegativeInteger(
                        $attributes['late_minutes'],
                        'Late minutes'
                    )
                    : 0;

                $excuse =
                    $this->nullableText(
                        $attributes,
                        'excuse',
                        'Attendance excuse',
                        255
                    );

                $notes =
                    $this->nullableText(
                        $attributes,
                        'notes',
                        'Attendance notes'
                    );

                /*
                 * Ownership fields are derived exclusively
                 * from persisted context.
                 *
                 * recorded_by_user_id permanently identifies
                 * the original recorder.
                 */
                $attendance =
                    Attendance::query()
                    ->withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'session_id' =>
                        $session->id,

                        'enrollment_id' =>
                        $enrollment->id,

                        'attendance_status_id' =>
                        $status->id,

                        'recorded_by_user_id' =>
                        $actor->id,

                        'late_minutes' =>
                        $lateMinutes,

                        'excuse' =>
                        $excuse,

                        'notes' =>
                        $notes,
                    ]);

                $attendance->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'attendance.recorded',
                    subject: $attendance,
                    afterValues: $this->attendanceAuditValues(
                        $attendance
                    )
                );

                return $attendance;
            },
            3
        );
    }

    public function update(
        User $actor,
        Attendance $attendance,
        array $attributes
    ): Attendance {
        $this->assertOnlyAllowedKeys(
            $attributes,
            [
                'attendance_status_id',
                'late_minutes',
                'excuse',
                'notes',
            ],
            'Attendance update'
        );

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $attendance,
                $attributes,
                $centerId
            ): Attendance {
                $attendance =
                    $this->lockAttendance(
                        $attendance,
                        $centerId
                    );

                $session =
                    $this->lockSessionById(
                        $attendance->session_id,
                        $centerId
                    );

                $enrollment =
                    $this->lockEnrollmentById(
                        $attendance->enrollment_id,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $attendance
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
                 * Existing historical Attendance may still
                 * be corrected after Enrollment completion,
                 * withdrawal, or transfer.
                 *
                 * A cancelled Session, however, cannot carry
                 * a newly changed Attendance result.
                 */
                $this->ensureSessionAcceptsAttendance(
                    $session
                );

                $beforeValues =
                    $this->attendanceAuditValues(
                        $attendance
                    );

                $data = [];

                if (
                    array_key_exists(
                        'attendance_status_id',
                        $attributes
                    )
                ) {
                    $statusId =
                        $this->requiredPositiveInteger(
                            $attributes,
                            'attendance_status_id',
                            'Attendance Status'
                        );

                    if (
                        $statusId
                        !== $attendance
                        ->attendance_status_id
                    ) {
                        $status =
                            $this->lockAttendanceStatusById(
                                $statusId,
                                $centerId
                            );

                        if (! $status->isActive()) {
                            throw new DomainException(
                                'An inactive Attendance Status cannot replace the current Attendance Status.'
                            );
                        }

                        $data['attendance_status_id'] = $status->id;
                    }
                }

                if (
                    array_key_exists(
                        'late_minutes',
                        $attributes
                    )
                ) {
                    $data['late_minutes'] =
                        $this->nonNegativeInteger(
                            $attributes['late_minutes'],
                            'Late minutes'
                        );
                }

                if (
                    array_key_exists(
                        'excuse',
                        $attributes
                    )
                ) {
                    $data['excuse'] =
                        $this->nullableText(
                            $attributes,
                            'excuse',
                            'Attendance excuse',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'notes',
                        $attributes
                    )
                ) {
                    $data['notes'] =
                        $this->nullableText(
                            $attributes,
                            'notes',
                            'Attendance notes'
                        );
                }

                /*
                 * recorded_by_user_id, recorded_at,
                 * center_id, session_id, and enrollment_id
                 * are intentionally immutable here.
                 */
                $attendance->fill(
                    $data
                );

                if (! $attendance->isDirty()) {
                    return $attendance;
                }

                $attendance->save();
                $attendance->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'attendance.updated',
                    subject: $attendance,
                    beforeValues: $beforeValues,
                    afterValues: $this->attendanceAuditValues(
                        $attendance
                    )
                );

                return $attendance;
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

    private function lockAttendance(
        Attendance $attendance,
        int $centerId
    ): Attendance {
        if (
            ! $attendance->exists
            || $attendance->getKey() === null
        ) {
            throw new AuthorizationException(
                'Attendance must be a persisted record in the current Center.'
            );
        }

        $persisted =
            Attendance::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $attendance->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'The Attendance record is outside the authorized Center scope.'
            );
        }

        return $persisted;
    }

    private function lockSession(
        ClassSession $session,
        int $centerId
    ): ClassSession {
        if (
            ! $session->exists
            || $session->getKey() === null
        ) {
            throw new AuthorizationException(
                'Class Session must be a persisted record in the current Center.'
            );
        }

        return $this->lockSessionById(
            $session->getKey(),
            $centerId
        );
    }

    private function lockSessionById(
        int $sessionId,
        int $centerId
    ): ClassSession {
        $session =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $sessionId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            throw new AuthorizationException(
                'The Class Session is outside the authorized Center scope.'
            );
        }

        return $session;
    }

    private function lockEnrollment(
        Enrollment $enrollment,
        int $centerId
    ): Enrollment {
        if (
            ! $enrollment->exists
            || $enrollment->getKey() === null
        ) {
            throw new AuthorizationException(
                'Enrollment must be a persisted record in the current Center.'
            );
        }

        return $this->lockEnrollmentById(
            $enrollment->getKey(),
            $centerId
        );
    }

    private function lockEnrollmentById(
        int $enrollmentId,
        int $centerId
    ): Enrollment {
        $enrollment =
            Enrollment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollmentId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($enrollment === null) {
            throw new AuthorizationException(
                'The Enrollment is outside the authorized Center scope.'
            );
        }

        return $enrollment;
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
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($courseClass === null) {
            throw new AuthorizationException(
                'The Course Class is outside the authorized Center scope.'
            );
        }

        return $courseClass;
    }

    private function lockAttendanceStatusById(
        int $statusId,
        int $centerId
    ): AttendanceStatus {
        $status =
            AttendanceStatus::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $statusId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($status === null) {
            throw new AuthorizationException(
                'The Attendance Status is outside the authorized Center scope.'
            );
        }

        return $status;
    }

    private function ensureOperationalBranchScope(
        User $actor,
        int $centerId,
        int $branchId
    ): void {
        /*
         * Teacher Attendance scope comes directly from
         * the persisted Class Session teacher assignment.
         *
         * Teacher requests therefore intentionally do not
         * depend on BranchContext.
         */
        if (
            $actor->systemRole()
            === SystemRole::Teacher
        ) {
            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Attendance.'
            );
        }

        if (
            ! $this->branchContext
                ->isEstablished()
        ) {
            throw new AuthorizationException(
                'Branch operational context has not been established.'
            );
        }

        if (
            ! $this->branchContext
                ->isBranchScoped()
        ) {
            throw new AuthorizationException(
                'Branch Manager Attendance operations require an assigned Branch context.'
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
                'The Attendance operation is outside the assigned Branch scope.'
            );
        }
    }

    private function ensureSessionAcceptsAttendance(
        ClassSession $session
    ): void {
        /*
         * Attendance may be entered while the Session is
         * Scheduled or corrected after it is Completed.
         *
         * Cancelled Sessions do not represent a delivered
         * instructional occurrence.
         */
        if ($session->isCancelled()) {
            throw new DomainException(
                'Attendance cannot be recorded or updated for a Cancelled Class Session.'
            );
        }
    }

    private function ensureEnrollmentEligibleForSession(
        Enrollment $enrollment,
        ClassSession $session
    ): void {
        $sessionDate =
            $session
            ->session_date
            ->toDateString();

        $enrollmentDate =
            $enrollment
            ->enrollment_date
            ->toDateString();

        if (
            $sessionDate
            < $enrollmentDate
        ) {
            throw new DomainException(
                'Attendance cannot be recorded for a Class Session before the Enrollment date.'
            );
        }

        switch ($enrollment->enrollment_status) {
            case EnrollmentStatus::Active:
            case EnrollmentStatus::Completed:
                return;

            case EnrollmentStatus::Withdrawn:
                if (
                    $enrollment->withdrawal_date
                    === null
                ) {
                    throw new DomainException(
                        'A Withdrawn Enrollment without a withdrawal date cannot receive historical Attendance.'
                    );
                }

                if (
                    $sessionDate
                    > $enrollment
                    ->withdrawal_date
                    ->toDateString()
                ) {
                    throw new DomainException(
                        'Attendance cannot be recorded for a Class Session after the Enrollment withdrawal date.'
                    );
                }

                return;

            case EnrollmentStatus::Transferred:
                $transferDate =
                    $this->transferDateForEnrollment(
                        $enrollment
                    );

                if ($transferDate === null) {
                    throw new DomainException(
                        'A Transferred Enrollment without transfer history cannot receive new Attendance.'
                    );
                }

                if (
                    $sessionDate
                    > $transferDate
                ) {
                    throw new DomainException(
                        'Attendance cannot be recorded for the source Enrollment after its transfer date.'
                    );
                }

                return;

            case EnrollmentStatus::Cancelled:
                throw new DomainException(
                    'A Cancelled Enrollment cannot receive new Attendance.'
                );
        }
    }

    private function transferDateForEnrollment(
        Enrollment $enrollment
    ): ?string {
        $occurredAt =
            EnrollmentHistory::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $enrollment->center_id
            )
            ->where(
                'enrollment_id',
                $enrollment->id
            )
            ->where(
                'event_type',
                'transfer'
            )
            ->where(
                'new_status',
                EnrollmentStatus::Transferred
                    ->value
            )
            ->orderByDesc(
                'occurred_at'
            )
            ->value(
                'occurred_at'
            );

        if ($occurredAt === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $occurredAt
        )->toDateString();
    }

    private function ensureNoExistingAttendance(
        int $centerId,
        int $sessionId,
        int $enrollmentId
    ): void {
        $existing =
            Attendance::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'session_id',
                $sessionId
            )
            ->where(
                'enrollment_id',
                $enrollmentId
            )
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw new DomainException(
                'Attendance has already been recorded for this Enrollment and Class Session.'
            );
        }
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

        $value =
            $attributes[$key];

        if (
            is_bool($value)
            || ! (
                is_int($value)
                || (
                    is_string($value)
                    && ctype_digit(
                        trim($value)
                    )
                )
            )
        ) {
            throw new DomainException(
                "{$label} must be a positive integer."
            );
        }

        $value = (int) $value;

        if ($value <= 0) {
            throw new DomainException(
                "{$label} must be a positive integer."
            );
        }

        return $value;
    }

    private function nonNegativeInteger(
        mixed $value,
        string $label
    ): int {
        if (
            is_bool($value)
            || ! (
                is_int($value)
                || (
                    is_string($value)
                    && ctype_digit(
                        trim($value)
                    )
                )
            )
        ) {
            throw new DomainException(
                "{$label} must be a non-negative integer."
            );
        }

        $value = (int) $value;

        if ($value < 0) {
            throw new DomainException(
                "{$label} must be a non-negative integer."
            );
        }

        return $value;
    }

    private function nullableText(
        array $attributes,
        string $key,
        string $label,
        ?int $maxLength = null
    ): ?string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
            || $attributes[$key] === null
        ) {
            return null;
        }

        $value =
            $attributes[$key];

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must be a string or null."
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (
            $maxLength !== null
            && mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function assertOnlyAllowedKeys(
        array $attributes,
        array $allowedKeys,
        string $operation
    ): void {
        $unexpectedKeys =
            array_diff(
                array_keys(
                    $attributes
                ),
                $allowedKeys
            );

        if ($unexpectedKeys === []) {
            return;
        }

        sort(
            $unexpectedKeys
        );

        throw new DomainException(
            "{$operation} received unsupported fields: "
                . implode(
                    ', ',
                    $unexpectedKeys
                )
                . '.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceAuditValues(
        Attendance $attendance
    ): array {
        return [
            'session_id' =>
            (int) $attendance->session_id,

            'enrollment_id' =>
            (int) $attendance->enrollment_id,

            'attendance_status_id' =>
            (int) $attendance
                ->attendance_status_id,

            'recorded_by_user_id' =>
            (int) $attendance
                ->recorded_by_user_id,

            'late_minutes' =>
            (int) $attendance
                ->late_minutes,

            'excuse' =>
            $attendance->excuse,

            'notes' =>
            $attendance->notes,

            'recorded_at' =>
            $attendance->recorded_at
                ?->toDateTimeString(),
        ];
    }
}
