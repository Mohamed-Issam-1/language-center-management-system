<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            'name' => fake()->unique()->languageCode()
                . ' Language',

            'code' => strtoupper(
                fake()->unique()
                    ->bothify('LANG-###??')
            ),

            'description' => fake()->sentence(),

            'status' =>
            AcademicRecordStatus::Active,

            'archived_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                AcademicRecordStatus::Active,

                'archived_at' => null,
            ]
        );
    }

    public function archived(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                AcademicRecordStatus::Archived,

                'archived_at' => now(),
            ]
        );
    }
}
