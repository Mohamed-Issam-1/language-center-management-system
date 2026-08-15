<?php

namespace Database\Factories;

use App\Models\Center;
use App\Support\Enums\CenterStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Center>
 */
class CenterFactory extends Factory
{
    protected $model = Center::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CTR-####??')),
            'name' => fake()->company() . ' Language Center',
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'timezone' => fake()->timezone(),
            'status' => CenterStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(fn(): array => [
            'status' => CenterStatus::Active,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn(): array => [
            'status' => CenterStatus::Suspended,
        ]);
    }
}
