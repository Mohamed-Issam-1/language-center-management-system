<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\EnrollmentFeeStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentFee>
 */
class EnrollmentFeeFactory extends Factory
{
    protected $model = EnrollmentFee::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory()
                ->active(),

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
             * Build a valid Enrollment whose Student and Class
             * both belong to this exact Center and Branch.
             */
            'enrollment_id' => function (
                array $attributes
            ): int {
                $branch =
                    Branch::query()
                    ->findOrFail(
                        $attributes['branch_id']
                    );

                $student =
                    Student::factory()
                    ->forBranch($branch)
                    ->active()
                    ->create();

                $courseClass =
                    CourseClass::factory()
                    ->forBranch($branch)
                    ->create();

                return Enrollment::factory()
                    ->forStudent($student)
                    ->forCourseClass($courseClass)
                    ->active()
                    ->create()
                    ->id;
            },

            'amount' =>
            fake()->randomFloat(
                2,
                50,
                5000
            ),

            /*
             * Fixture currency only.
             * The Finance Service will snapshot the actual
             * Center operating currency.
             */
            'currency_code' =>
            'USD',

            'status' =>
            EnrollmentFeeStatus::Active,

            'created_by_user_id' =>
            function (
                array $attributes
            ): int {
                return User::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'voided_by_user_id' =>
            null,

            'voided_at' =>
            null,

            'void_reason' =>
            null,
        ];
    }

    public function forEnrollment(
        Enrollment $enrollment
    ): static {
        $courseClass =
            $enrollment
            ->courseClass()
            ->firstOrFail();

        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $enrollment->center_id,

                'branch_id' =>
                $courseClass->branch_id,

                'enrollment_id' =>
                $enrollment->id,
            ]
        );
    }

    public function createdBy(
        User $user
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'created_by_user_id' =>
                $user->id,
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                EnrollmentFeeStatus::Active,

                'voided_by_user_id' =>
                null,

                'voided_at' =>
                null,

                'void_reason' =>
                null,
            ]
        );
    }

    public function voided(
        ?User $user = null
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                EnrollmentFeeStatus::Voided,

                'voided_by_user_id' =>
                $user?->id
                    ?? function (
                        array $resolvedAttributes
                    ): int {
                        return User::factory()
                            ->create([
                                'center_id' =>
                                $resolvedAttributes['center_id'],
                            ])
                            ->id;
                    },

                'voided_at' =>
                now(),

                'void_reason' =>
                'Factory void',
            ]
        );
    }
}
