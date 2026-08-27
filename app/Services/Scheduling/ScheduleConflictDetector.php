<?php

namespace App\Services\Scheduling;

use App\Models\ClassSchedule;
use App\Support\Enums\ClassScheduleStatus;
use DomainException;

class ScheduleConflictDetector
{
    public function assertNoConflicts(
        int $centerId,
        int $classId,
        int $teacherId,
        int $classroomId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        string $effectiveFrom,
        string $effectiveUntil,
        ?int $ignoreScheduleId = null
    ): void {
        /*
         * FR-201 / FR-207:
         * A Teacher cannot be assigned to overlapping
         * Class Schedules.
         */
        $teacherConflict =
            $this->findOverlap(
                centerId: $centerId,
                resourceColumn: 'teacher_id',
                resourceId: $teacherId,
                dayOfWeek: $dayOfWeek,
                startTime: $startTime,
                endTime: $endTime,
                effectiveFrom: $effectiveFrom,
                effectiveUntil: $effectiveUntil,
                ignoreScheduleId: $ignoreScheduleId
            );

        if ($teacherConflict !== null) {
            throw new DomainException(
                $this->conflictMessage(
                    resourceLabel: 'Teacher',
                    resourceId: $teacherId,
                    conflict: $teacherConflict
                )
            );
        }

        /*
         * FR-208:
         * A Classroom cannot host overlapping schedules.
         */
        $classroomConflict =
            $this->findOverlap(
                centerId: $centerId,
                resourceColumn: 'classroom_id',
                resourceId: $classroomId,
                dayOfWeek: $dayOfWeek,
                startTime: $startTime,
                endTime: $endTime,
                effectiveFrom: $effectiveFrom,
                effectiveUntil: $effectiveUntil,
                ignoreScheduleId: $ignoreScheduleId
            );

        if ($classroomConflict !== null) {
            throw new DomainException(
                $this->conflictMessage(
                    resourceLabel: 'Classroom',
                    resourceId: $classroomId,
                    conflict: $classroomConflict
                )
            );
        }

        /*
         * FR-202 / FR-209:
         * The same Class cannot have overlapping
         * schedule assignments.
         */
        $classConflict =
            $this->findOverlap(
                centerId: $centerId,
                resourceColumn: 'class_id',
                resourceId: $classId,
                dayOfWeek: $dayOfWeek,
                startTime: $startTime,
                endTime: $endTime,
                effectiveFrom: $effectiveFrom,
                effectiveUntil: $effectiveUntil,
                ignoreScheduleId: $ignoreScheduleId
            );

        if ($classConflict !== null) {
            throw new DomainException(
                $this->conflictMessage(
                    resourceLabel: 'Course Class',
                    resourceId: $classId,
                    conflict: $classConflict
                )
            );
        }
    }

    private function findOverlap(
        int $centerId,
        string $resourceColumn,
        int $resourceId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        string $effectiveFrom,
        string $effectiveUntil,
        ?int $ignoreScheduleId
    ): ?ClassSchedule {
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

            /*
                 * Date ranges overlap when:
                 *
                 * existing.from <= candidate.until
                 * AND
                 * existing.until >= candidate.from
                 */
            ->where(
                'effective_from',
                '<=',
                $effectiveUntil
            )
            ->where(
                'effective_until',
                '>=',
                $effectiveFrom
            )

            /*
                 * Time ranges overlap when:
                 *
                 * existing.start < candidate.end
                 * AND
                 * existing.end > candidate.start
                 *
                 * Adjacent ranges therefore do not conflict.
                 */
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

        if ($ignoreScheduleId !== null) {
            $query->whereKeyNot(
                $ignoreScheduleId
            );
        }

        /*
         * Resource rows are locked by the surrounding
         * Scheduling management transaction.
         *
         * The detector remains detection-only and returns
         * the first conflicting Schedule so callers receive
         * useful conflict details.
         */
        return $query
            ->orderBy('id')
            ->first();
    }

    private function conflictMessage(
        string $resourceLabel,
        int $resourceId,
        ClassSchedule $conflict
    ): string {
        return "The {$resourceLabel} already has an overlapping Class Schedule. "
            . "Conflict: Schedule #{$conflict->id}, "
            . "resource #{$resourceId}, "
            . "time {$conflict->start_time}-{$conflict->end_time}.";
    }
}
