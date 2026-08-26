<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'student_id' => function (
                array $attributes
            ): int {
                return Student::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'class_id' => function (
                array $attributes
            ): int {
                return CourseClass::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'enrollment_number' =>
            strtoupper(
                fake()
                    ->unique()
                    ->bothify(
                        'ENR-########??'
                    )
            ),

            'enrollment_date' =>
            now()->toDateString(),

            'enrollment_status' =>
            EnrollmentStatus::Active,

            /*
             * Fixture value only.
             * No eligibility enum is approved yet.
             */
            'eligibility_status' =>
            'eligible',

            'withdrawal_date' => null,
            'withdrawal_reason' => null,
        ];
    }

    public function forStudent(
        Student $student
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $student->center_id,

                'student_id' =>
                $student->id,
            ]
        );
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
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'enrollment_status' =>
                EnrollmentStatus::Active,

                'withdrawal_date' =>
                null,

                'withdrawal_reason' =>
                null,
            ]
        );
    }

    public function completed(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]
        );
    }

    public function withdrawn(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'enrollment_status' =>
                EnrollmentStatus::Withdrawn,

                'withdrawal_date' =>
                now()->toDateString(),

                'withdrawal_reason' =>
                'Factory withdrawal',
            ]
        );
    }

    public function transferred(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'enrollment_status' =>
                EnrollmentStatus::Transferred,
            ]
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'enrollment_status' =>
                EnrollmentStatus::Cancelled,
            ]
        );
    }
}
