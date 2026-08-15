<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'national_id_number' => fake()->unique()->numerify('#########'),
        ];
    }
}
