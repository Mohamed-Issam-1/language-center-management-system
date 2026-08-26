<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentHistory>
 */
class EnrollmentHistoryFactory extends Factory
{
    protected $model =
    EnrollmentHistory::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'enrollment_id' => function (
                array $attributes
            ): int {
                return Enrollment::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'from_class_id' => null,

            'to_class_id' => function (
                array $attributes
            ): int {
                return Enrollment::query()
                    ->withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['enrollment_id']
                    )
                    ->class_id;
            },

            'performed_by_user_id' =>
            function (
                array $attributes
            ): int {
                $centerId =
                    $attributes['center_id'];

                $person =
                    Person::factory()
                    ->create([
                        'center_id' =>
                        $centerId,
                    ]);

                $role =
                    Role::query()
                    ->firstOrCreate(
                        [
                            'code' =>
                            SystemRole::CenterOwner
                                ->value,
                        ],
                        [
                            'name' =>
                            SystemRole::CenterOwner
                                ->label(),
                        ]
                    );

                return User::factory()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'person_id' =>
                        $person->id,

                        'role_id' =>
                        $role->id,
                    ])
                    ->id;
            },

            'event_type' => 'created',

            'previous_status' => null,

            'new_status' =>
            EnrollmentStatus::Active,

            'notes' => null,

            'occurred_at' => now(),
        ];
    }

    public function forEnrollment(
        Enrollment $enrollment
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $enrollment->center_id,

                'enrollment_id' =>
                $enrollment->id,
            ]
        );
    }

    public function performedBy(
        User $user
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $user->center_id,

                'performed_by_user_id' =>
                $user->id,
            ]
        );
    }
}
