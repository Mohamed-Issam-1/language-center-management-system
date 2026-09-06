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
            'center_id' =>
            Center::factory(),

            'national_id_number' =>
            fake()
                ->unique()
                ->numerify('#########'),

            'full_name' =>
            fake()->name(),

            'date_of_birth' =>
            fake()->dateTimeBetween(
                '-70 years',
                '-10 years'
            )->format('Y-m-d'),

            'city_of_residence' =>
            fake()->city(),

            'email' =>
            fake()->safeEmail(),

            'phone_number' =>
            fake()->numerify(
                '+97059#######'
            ),

            'personal_picture_path' =>
            null,
        ];
    }
}
