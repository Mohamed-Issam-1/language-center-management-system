<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\FeeInstallment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

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

            /*
             * Create the financial obligation first.
             */
            'fee_installment_id' =>
            function (
                array $attributes
            ): int {
                return FeeInstallment::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'branch_id' =>
                        $attributes['branch_id'],

                        'sequence_number' =>
                        1,
                    ])
                    ->id;
            },

            /*
             * The default Allocation covers the generated
             * Installment amount.
             */
            'amount' => function (
                array $attributes
            ): string {
                return FeeInstallment::query()
                    ->findOrFail(
                        $attributes['fee_installment_id']
                    )
                    ->amount;
            },

            /*
             * Resolve the Student through:
             *
             * Installment -> Fee -> Enrollment -> Student
             *
             * so the generated Payment belongs to the same
             * financial Student context.
             */
            'payment_id' => function (
                array $attributes
            ): int {
                $installment =
                    FeeInstallment::query()
                    ->with(
                        'enrollmentFee.enrollment'
                    )
                    ->findOrFail(
                        $attributes['fee_installment_id']
                    );

                $enrollment =
                    $installment
                    ->enrollmentFee
                    ->enrollment;

                return Payment::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'branch_id' =>
                        $attributes['branch_id'],

                        'student_id' =>
                        $enrollment->student_id,

                        'amount' =>
                        $attributes['amount'],
                    ])
                    ->id;
            },
        ];
    }

    public function forPayment(
        Payment $payment
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $payment->center_id,

                'branch_id' =>
                $payment->branch_id,

                'payment_id' =>
                $payment->id,
            ]
        );
    }

    public function forFeeInstallment(
        FeeInstallment $installment
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $installment->center_id,

                'branch_id' =>
                $installment->branch_id,

                'fee_installment_id' =>
                $installment->id,
            ]
        );
    }
}
