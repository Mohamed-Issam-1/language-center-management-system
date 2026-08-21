<?php

namespace Database\Factories;

use App\Models\BranchManager;
use App\Models\Center;
use App\Models\Person;
use App\Support\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchManager>
 */
class BranchManagerFactory extends Factory
{
    protected $model = BranchManager::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            'person_id' => function (
                array $attributes
            ): int {
                return Person::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'user_id' => null,
            'status' => StaffStatus::Active,
            'deactivated_at' => null,
        ];
    }

    public function forPerson(
        Person $person
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' => $person->center_id,
                'person_id' => $person->id,
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' => StaffStatus::Active,
                'deactivated_at' => null,
            ]
        );
    }

    public function deactivated(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' => now(),
            ]
        );
    }
}
