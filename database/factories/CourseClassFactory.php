<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Support\Enums\CourseClassStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseClass>
 */
class CourseClassFactory extends Factory
{
    protected $model = CourseClass::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            /*
             * Resolve Branch under the same Center.
             */
            'branch_id' => function (
                array $attributes
            ): int {
                return Branch::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            /*
             * Resolve Course under the same Center.
             */
            'course_id' => function (
                array $attributes
            ): int {
                return Course::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            /*
             * Classroom belongs to the exact same Branch and
             * Center selected for this Course Class.
             */
            'assigned_classroom_id' =>
            function (
                array $attributes
            ): int {
                return Classroom::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'branch_id' =>
                        $attributes['branch_id'],
                    ])
                    ->id;
            },

            /*
             * Teacher operational record belongs to the same
             * Center.
             */
            'assigned_teacher_id' =>
            function (
                array $attributes
            ): int {
                return Teacher::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'class_code' => strtoupper(
                fake()->unique()
                    ->bothify(
                        'CLS-####??'
                    )
            ),

            'name' => 'Class '
                . fake()->unique()
                ->numberBetween(
                    100,
                    99999
                ),

            'start_date' =>
            now()
                ->addDays(7)
                ->toDateString(),

            'end_date' =>
            now()
                ->addDays(35)
                ->toDateString(),

            'capacity' =>
            fake()->numberBetween(
                10,
                30
            ),

            /*
             * No fixed delivery-mode domain has been approved yet.
             */
            'delivery_mode' =>
            fake()->word(),

            'class_status' =>
            CourseClassStatus::Planned,
        ];
    }

    public function forBranch(
        Branch $branch
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,
            ]
        );
    }

    public function forCourse(
        Course $course
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $course->center_id,

                'course_id' =>
                $course->id,
            ]
        );
    }

    public function forClassroom(
        Classroom $classroom
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $classroom->center_id,

                'branch_id' =>
                $classroom->branch_id,

                'assigned_classroom_id' =>
                $classroom->id,
            ]
        );
    }

    public function forTeacher(
        Teacher $teacher
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $teacher->center_id,

                'assigned_teacher_id' =>
                $teacher->id,
            ]
        );
    }

    public function planned(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'class_status' =>
                CourseClassStatus::Planned,
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'class_status' =>
                CourseClassStatus::Active,
            ]
        );
    }

    public function completed(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'class_status' =>
                CourseClassStatus::Completed,
            ]
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'class_status' =>
                CourseClassStatus::Cancelled,
            ]
        );
    }
}
