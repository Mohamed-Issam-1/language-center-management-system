<?php

namespace Database\Factories;

use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            /*
             * Generate the Language first under the Course Center.
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

            /*
             * The generated Academic Level must belong to both
             * the same Center and the selected Language.
             */
            'academic_level_id' => function (
                array $attributes
            ): int {
                return AcademicLevel::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'language_id' =>
                        $attributes['language_id'],
                    ])
                    ->id;
            },

            'name' => 'Course '
                . fake()->unique()
                ->numberBetween(
                    1,
                    100000
                ),

            'code' => strtoupper(
                fake()->unique()
                    ->bothify('CRS-####??')
            ),

            'description' => fake()->paragraph(),

            'duration_weeks' =>
            fake()->numberBetween(
                1,
                52
            ),

            'total_hours' =>
            fake()->randomFloat(
                2,
                10,
                300
            ),

            'default_fee' =>
            fake()->randomFloat(
                2,
                0,
                10000
            ),

            'passing_grade' =>
            fake()->randomFloat(
                2,
                50,
                100
            ),

            'minimum_attendance' =>
            fake()->randomFloat(
                2,
                50,
                100
            ),

            'status' =>
            AcademicRecordStatus::Active,

            'archived_at' => null,
        ];
    }

    public function forLanguage(
        Language $language
    ): static {
        return $this->state(
            function (
                array $attributes
            ) use ($language): array {
                return [
                    'center_id' =>
                    $language->center_id,

                    'language_id' =>
                    $language->id,

                    /*
                     * Recreate a compatible Academic Level unless
                     * a later state explicitly replaces it.
                     */
                    'academic_level_id' =>
                    AcademicLevel::factory()
                        ->create([
                            'center_id' =>
                            $language->center_id,

                            'language_id' =>
                            $language->id,
                        ])
                        ->id,
                ];
            }
        );
    }

    public function forAcademicLevel(
        AcademicLevel $academicLevel
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $academicLevel->center_id,

                'language_id' =>
                $academicLevel->language_id,

                'academic_level_id' =>
                $academicLevel->id,
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
