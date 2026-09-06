<?php

namespace Database\Factories;

use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicLevel>
 */
class AcademicLevelFactory extends Factory
{
    protected $model = AcademicLevel::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            /*
             * Resolve the Language under the same Center generated
             * for this Academic Level so the composite foreign key
             * remains valid.
             */
            'language_id' => function (
                array $attributes
            ): int {
                return Language::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'name' => 'Level '
                . fake()->unique()
                ->numberBetween(
                    1,
                    100000
                ),

            'code' => strtoupper(
                fake()->unique()
                    ->bothify('LVL-###??')
            ),

            'sequence_number' =>
            fake()->numberBetween(
                1,
                1000
            ),

            'description' => fake()->sentence(),

            'status' =>
            AcademicRecordStatus::Active,

            'archived_at' => null,
        ];
    }

    public function forLanguage(
        Language $language
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $language->center_id,

                'language_id' =>
                $language->id,
            ]
        );
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
