<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeInstallment>
 */
class FeeInstallmentFactory extends Factory
{
    protected $model = FeeInstallment::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory()
                ->active(),

            'branch_id' => function (
                array $attributes
            ): int {
                return Branch::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'enrollment_fee_id' =>
            function (
                array $attributes
            ): int {
                return EnrollmentFee::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'branch_id' =>
                        $attributes['branch_id'],
                    ])
                    ->id;
            },

            'sequence_number' =>
            fake()->numberBetween(
                1,
                12
            ),

            'due_date' =>
            now()
                ->addDays(
                    fake()->numberBetween(
                        1,
                        120
                    )
                )
                ->toDateString(),

            'amount' =>
            fake()->randomFloat(
                2,
                25,
                2500
            ),
        ];
    }

    public function forEnrollmentFee(
        EnrollmentFee $fee
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $fee->center_id,

                'branch_id' =>
                $fee->branch_id,

                'enrollment_fee_id' =>
                $fee->id,
            ]
        );
    }

    public function withSequenceNumber(
        int $sequenceNumber
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'sequence_number' =>
                $sequenceNumber,
            ]
        );
    }
}
