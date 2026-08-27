<?php

namespace Database\Factories;

use App\Models\AttendanceStatus;
use App\Models\Center;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceStatus>
 */
class AttendanceStatusFactory extends Factory
{
    protected $model =
    AttendanceStatus::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'name' =>
            fake()->words(
                2,
                true
            ),

            'code' =>
            strtoupper(
                fake()
                    ->unique()
                    ->bothify(
                        'ATT-####??'
                    )
            ),

            'contribution_value' =>
            fake()->randomElement([
                0,
                50,
                75,
                100,
            ]),

            'is_active' =>
            true,
        ];
    }

    public function forCenter(
        Center $center
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $center->id,
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'is_active' =>
                true,
            ]
        );
    }

    public function inactive(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'is_active' =>
                false,
            ]
        );
    }
}
