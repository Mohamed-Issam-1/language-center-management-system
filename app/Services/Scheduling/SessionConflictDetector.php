<?php

namespace App\Services\Scheduling;

use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\ClassSessionStatus;
use DateTimeImmutable;
use DomainException;

class SessionConflictDetector
{
    public function assertNoConflicts(
        int $centerId,
        int $classId,
        int $teacherId,
        int $classroomId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        ?int $ignoreSessionId = null,
        ?int $ignoreScheduleId = null
    ): void {
        $this->assertResourceAvailable(
            centerId: $centerId,
            resourceColumn: 'teacher_id',
            resourceId: $teacherId,
            resourceLabel: 'Teacher',
            classId: $classId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            endTime: $endTime,
            ignoreSessionId: $ignoreSessionId,
            ignoreScheduleId: $ignoreScheduleId
        );

        $this->assertResourceAvailable(
            centerId: $centerId,
            resourceColumn: 'classroom_id',
            resourceId: $classroomId,
            resourceLabel: 'Classroom',
            classId: $classId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            endTime: $endTime,
            ignoreSessionId: $ignoreSessionId,
            ignoreScheduleId: $ignoreScheduleId
        );

        $this->assertResourceAvailable(
            centerId: $centerId,
            resourceColumn: 'class_id',
            resourceId: $classId,
            resourceLabel: 'Course Class',
            classId: $classId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            endTime: $endTime,
            ignoreSessionId: $ignoreSessionId,
            ignoreScheduleId: $ignoreScheduleId
        );
    }

    private function assertResourceAvailable(
        int $centerId,
        string $resourceColumn,
        int $resourceId,
        string $resourceLabel,
        int $classId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        ?int $ignoreSessionId,
        ?int $ignoreScheduleId
    ): void {
        $sessionConflict =
            $this->sessionConflict(
                centerId: $centerId,
                resourceColumn: $resourceColumn,
                resourceId: $resourceId,
                sessionDate: $sessionDate,
                startTime: $startTime,
                endTime: $endTime,
                ignoreSessionId: $ignoreSessionId
            );

        if ($sessionConflict !== null) {
            throw new DomainException(
                "{$resourceLabel} conflict with Class Session "
                    . "#{$sessionConflict->id} at "
                    . "{$sessionDate} "
                    . "{$sessionConflict->start_time}-"
                    . "{$sessionConflict->end_time}."
            );
        }

        /*
         * A recurring Schedule still reserves the resource for a
         * date that has not yet been materialized as a Session.
         *
         * Once a concrete Session exists for that Schedule/date,
         * the Session becomes authoritative. This allows one
         * generated occurrence to be cancelled or rescheduled
         * without the recurring Schedule falsely reserving its
         * original slot for that specific date.
         */
        $scheduleConflict =
            $this->scheduleConflict(
                centerId: $centerId,
                resourceColumn: $resourceColumn,
                resourceId: $resourceId,
                sessionDate: $sessionDate,
                startTime: $startTime,
                endTime: $endTime,
                ignoreScheduleId: $ignoreScheduleId
            );

        if ($scheduleConflict !== null) {
            throw new DomainException(
                "{$resourceLabel} conflict with Class Schedule "
                    . "#{$scheduleConflict->id} at "
                    . "{$scheduleConflict->start_time}-"
                    . "{$scheduleConflict->end_time}."
            );
        }
    }

    private function sessionConflict(
        int $centerId,
        string $resourceColumn,
        int $resourceId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        ?int $ignoreSessionId
    ): ?ClassSession {
        $query =
            ClassSession::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                $resourceColumn,
                $resourceId
            )
            ->where(
                'session_date',
                $sessionDate
            )
            ->where(
                'session_status',
                '!=',
                ClassSessionStatus::Cancelled->value
            )
            ->where(
                'start_time',
                '<',
                $endTime
            )
            ->where(
                'end_time',
                '>',
                $startTime
            );

        if ($ignoreSessionId !== null) {
            $query->whereKeyNot(
                $ignoreSessionId
            );
        }

        return $query
            ->orderBy('id')
            ->first();
    }

    private function scheduleConflict(
        int $centerId,
        string $resourceColumn,
        int $resourceId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        ?int $ignoreScheduleId
    ): ?ClassSchedule {
        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $sessionDate
            );

        if ($date === false) {
            throw new DomainException(
                'Class Session date must use YYYY-MM-DD format.'
            );
        }

        $dayOfWeek =
            (int) $date->format('N');

        $query =
            ClassSchedule::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                $resourceColumn,
                $resourceId
            )
            ->where(
                'status',
                ClassScheduleStatus::Active->value
            )
            ->where(
                'day_of_week',
                $dayOfWeek
            )
            ->where(
                'effective_from',
                '<=',
                $sessionDate
            )
            ->where(
                'effective_until',
                '>=',
                $sessionDate
            )
            ->where(
                'start_time',
                '<',
                $endTime
            )
            ->where(
                'end_time',
                '>',
                $startTime
            )

            /*
                 * If this recurring occurrence already has a
                 * concrete Session, its actual Session state/time
                 * controls availability for this date.
                 */
            ->whereDoesntHave(
                'sessions',
                function ($query) use (
                    $sessionDate
                ): void {
                    $query->where(
                        'occurrence_date',
                        $sessionDate
                    );
                }
            );

        if ($ignoreScheduleId !== null) {
            $query->whereKeyNot(
                $ignoreScheduleId
            );
        }

        return $query
            ->orderBy('id')
            ->first();
    }
}
