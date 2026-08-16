<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Support\Enums\BranchStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            'name' => fake()->company() . ' Branch',

            'code' => strtoupper(
                fake()->unique()->bothify('BR-####??')
            ),

            'phone' => fake()->phoneNumber(),

            'email' => fake()->unique()->safeEmail(),

            'address' => fake()->address(),

            'working_hours' => null,

            'status' => BranchStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' => BranchStatus::Active,
            ]
        );
    }

    public function deactivated(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' => BranchStatus::Deactivated,
            ]
        );
    }
}
