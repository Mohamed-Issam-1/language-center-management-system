<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Student;
use App\Support\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            /*
             * Resolve both Branch and Person under the same
             * Center generated for this Student so the composite
             * foreign keys remain valid.
             */
            'branch_id' => function (
                array $attributes
            ): int {
                return Branch::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

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

            /*
             * Student records do not require a login account.
             * Account linkage is explicit when needed.
             */
            'user_id' => null,

            'status' =>
            StudentStatus::Active,

            'archived_at' => null,
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
                'status' =>
                StudentStatus::Active,
                'archived_at' => null,
            ]
        );
    }

    public function archived(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                StudentStatus::Archived,
                'archived_at' => now(),
            ]
        );
    }
}
