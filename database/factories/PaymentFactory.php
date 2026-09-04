<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

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

            'student_id' => function (
                array $attributes
            ): int {
                $branch =
                    Branch::query()
                    ->findOrFail(
                        $attributes['branch_id']
                    );

                return Student::factory()
                    ->forBranch($branch)
                    ->active()
                    ->create()
                    ->id;
            },

            'receipt_number' =>
            strtoupper(
                fake()
                    ->unique()
                    ->bothify(
                        'RCT-########??'
                    )
            ),

            'idempotency_key' =>
            fake()
                ->unique()
                ->uuid(),

            'amount' =>
            fake()->randomFloat(
                2,
                10,
                5000
            ),

            'currency_code' =>
            'USD',

            /*
             * No fixed Payment Method enum is approved yet.
             */
            'payment_method' =>
            'cash',

            'paid_at' =>
            now()
                ->startOfSecond(),

            'received_by_user_id' =>
            function (
                array $attributes
            ): int {
                return User::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'reference' =>
            null,

            'notes' =>
            null,

            'status' =>
            PaymentStatus::Posted,

            'reversed_at' =>
            null,

            'reversed_by_user_id' =>
            null,

            'reversal_reason' =>
            null,
        ];
    }

    public function forBranch(
        Branch $branch
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,
            ]
        );
    }

    public function forStudent(
        Student $student
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $student->center_id,

                'student_id' =>
                $student->id,
            ]
        );
    }

    public function receivedBy(
        User $user
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'received_by_user_id' =>
                $user->id,
            ]
        );
    }

    public function posted(): static
    {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                PaymentStatus::Posted,

                'reversed_at' =>
                null,

                'reversed_by_user_id' =>
                null,

                'reversal_reason' =>
                null,
            ]
        );
    }

    public function reversed(
        ?User $user = null
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'status' =>
                PaymentStatus::Reversed,

                'reversed_at' =>
                now(),

                'reversed_by_user_id' =>
                $user?->id
                    ?? function (
                        array $resolvedAttributes
                    ): int {
                        return User::factory()
                            ->create([
                                'center_id' =>
                                $resolvedAttributes['center_id'],
                            ])
                            ->id;
                    },

                'reversal_reason' =>
                'Factory reversal',
            ]
        );
    }
}
