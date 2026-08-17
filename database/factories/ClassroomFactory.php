<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    protected $model = Classroom::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            /*
             * Resolve the Branch under the same Center generated
             * for this Classroom so the composite FK remains valid.
             */
            'branch_id' => function (
                array $attributes
            ): int {
                return Branch::factory()
                    ->create([
                        'center_id' => $attributes['center_id'],
                    ])
                    ->id;
            },

            'name' => 'Classroom '
                . fake()->unique()->numberBetween(
                    100,
                    9999
                ),

            'code' => strtoupper(
                fake()->unique()
                    ->bothify('CR-####??')
            ),

            'capacity' => fake()
                ->numberBetween(
                    10,
                    60
                ),

            'location' => fake()
                ->bothify(
                    'Floor ## - Room ###'
                ),

            'availability_status' =>
            ClassroomAvailabilityStatus::Available,

            'status' => ClassroomStatus::Active,
        ];
    }

    public function forBranch(
        Branch $branch
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' => $branch->center_id,
                'branch_id' => $branch->id,
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' => ClassroomStatus::Active,
            ]
        );
    }

    public function deactivated(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' => ClassroomStatus::Deactivated,
            ]
        );
    }

    public function available(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'availability_status' =>
                ClassroomAvailabilityStatus::Available,
            ]
        );
    }

    public function unavailable(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'availability_status' =>
                ClassroomAvailabilityStatus::Unavailable,
            ]
        );
    }
}
