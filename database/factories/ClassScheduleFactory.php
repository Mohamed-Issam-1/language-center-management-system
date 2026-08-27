<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\CourseClass;
use App\Support\Enums\ClassScheduleStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSchedule>
 */
class ClassScheduleFactory extends Factory
{
    protected $model = ClassSchedule::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'class_id' =>
            function (
                array $attributes
            ): int {
                return CourseClass::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'classroom_id' =>
            function (
                array $attributes
            ): int {
                return CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['class_id']
                    )
                    ->assigned_classroom_id;
            },

            'teacher_id' =>
            function (
                array $attributes
            ): int {
                return CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['class_id']
                    )
                    ->assigned_teacher_id;
            },

            'day_of_week' => 1,

            'start_time' => '09:00:00',
            'end_time' => '10:30:00',

            'effective_from' =>
            function (
                array $attributes
            ): string {
                return CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['class_id']
                    )
                    ->start_date
                    ->toDateString();
            },

            'effective_until' =>
            function (
                array $attributes
            ): string {
                return CourseClass::withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['class_id']
                    )
                    ->end_date
                    ->toDateString();
            },

            'status' =>
            ClassScheduleStatus::Active,
        ];
    }

    public function forCourseClass(
        CourseClass $courseClass
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $courseClass->center_id,

                'class_id' =>
                $courseClass->id,

                'classroom_id' =>
                $courseClass
                    ->assigned_classroom_id,

                'teacher_id' =>
                $courseClass
                    ->assigned_teacher_id,

                'effective_from' =>
                $courseClass
                    ->start_date
                    ->toDateString(),

                'effective_until' =>
                $courseClass
                    ->end_date
                    ->toDateString(),
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                ClassScheduleStatus::Active,
            ]
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                ClassScheduleStatus::Cancelled,
            ]
        );
    }
}
