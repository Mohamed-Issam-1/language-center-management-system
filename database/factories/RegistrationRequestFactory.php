<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\RegistrationRequest;
use App\Support\Enums\RegistrationRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationRequest>
 */
class RegistrationRequestFactory extends Factory
{
    protected $model =
    RegistrationRequest::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'national_id_number' =>
            fake()
                ->unique()
                ->numerify(
                    '#########'
                ),

            'full_name' =>
            fake()->name(),

            'date_of_birth' =>
            fake()
                ->dateTimeBetween(
                    '-70 years',
                    '-10 years'
                )
                ->format('Y-m-d'),

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

            'status' =>
            RegistrationRequestStatus::Pending,

            'selected_role_id' =>
            null,

            'selected_branch_id' =>
            null,

            'reviewed_by_user_id' =>
            null,

            'reviewed_at' =>
            null,

            'rejection_reason' =>
            null,

            'pending_marker' =>
            1,
        ];
    }
}
