<?php

namespace Database\Factories;

use App\Models\Role;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            /*
             * Temporary Breeze compatibility fields.
             * These will be removed when the authentication layer is
             * fully migrated to LCMS account identifiers.
             */
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),

            /*
             * Default test user is platform-scoped.
             */
            'center_id' => null,
            'person_id' => null,

            'role_id' => function (): int {
                return Role::query()->firstOrCreate(
                    ['code' => SystemRole::PlatformOwner->value],
                    ['name' => SystemRole::PlatformOwner->label()],
                )->id;
            },

            'account_login_identifier' => fake()->unique()->userName(),
            'recovery_email' => fake()->safeEmail(),
            'status' => AccountStatus::Active,

            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => null,
            'password_changed_at' => null,
            'deactivated_at' => null,

            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn(array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => AccountStatus::Pending,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => AccountStatus::Deactivated,
            'deactivated_at' => now(),
        ]);
    }
}
