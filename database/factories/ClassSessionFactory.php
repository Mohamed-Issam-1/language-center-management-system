<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Support\Enums\ClassSessionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSession>
 */
class ClassSessionFactory extends Factory
{
    protected $model = ClassSession::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'schedule_id' =>
            function (
                array $attributes
            ): int {
                return ClassSchedule::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'class_id' =>
            function (
                array $attributes
            ): int {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->class_id;
            },

            'classroom_id' =>
            function (
                array $attributes
            ): int {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->classroom_id;
            },

            'teacher_id' =>
            function (
                array $attributes
            ): int {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->teacher_id;
            },

            'session_date' =>
            function (
                array $attributes
            ): string {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->effective_from
                    ->toDateString();
            },

            'occurrence_date' =>
            function (
                array $attributes
            ): string {
                $sessionDate =
                    $attributes['session_date'];

                if (
                    $sessionDate instanceof
                    \DateTimeInterface
                ) {
                    return $sessionDate
                        ->format('Y-m-d');
                }

                return (string) $sessionDate;
            },

            'start_time' =>
            function (
                array $attributes
            ): string {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->start_time;
            },

            'end_time' =>
            function (
                array $attributes
            ): string {
                return ClassSchedule::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['schedule_id']
                    )
                    ->end_time;
            },

            'topic' => null,

            'session_status' =>
            ClassSessionStatus::Scheduled,

            'cancellation_reason' =>
            null,
        ];
    }

    public function forSchedule(
        ClassSchedule $schedule
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $schedule->center_id,

                'class_id' =>
                $schedule->class_id,

                'schedule_id' =>
                $schedule->id,

                'classroom_id' =>
                $schedule->classroom_id,

                'teacher_id' =>
                $schedule->teacher_id,

                'session_date' =>
                $schedule
                    ->effective_from
                    ->toDateString(),

                'start_time' =>
                $schedule->start_time,

                'end_time' =>
                $schedule->end_time,
            ]
        );
    }

    public function scheduled(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'session_status' =>
                ClassSessionStatus::Scheduled,

                'cancellation_reason' =>
                null,
            ]
        );
    }

    public function completed(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'session_status' =>
                ClassSessionStatus::Completed,

                'cancellation_reason' =>
                null,
            ]
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'session_status' =>
                ClassSessionStatus::Cancelled,

                'cancellation_reason' =>
                'Cancelled for testing.',
            ]
        );
    }
}
