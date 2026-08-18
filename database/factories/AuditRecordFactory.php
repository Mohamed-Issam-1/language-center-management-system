<?php

namespace Database\Factories;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditRecord>
 */
class AuditRecordFactory extends Factory
{
    protected $model = AuditRecord::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'branch_id' => null,
            'actor_user_id' => User::factory(),
            'actor_role' => SystemRole::PlatformOwner->value,
            'action_type' => 'record.updated',
            'subject_type' => 'record',
            'subject_id' => fake()
                ->numberBetween(
                    1,
                    100000
                ),
            'before_values' => null,
            'after_values' => null,
            'metadata' => null,
            'occurred_at' => now(),
        ];
    }

    public function forCenter(
        Center $center
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' => $center->id,
                'branch_id' => null,
            ]
        );
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

    public function byActor(
        User $actor
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'actor_user_id' => $actor->id,
                'actor_role' =>
                $actor->systemRole()?->value
                    ?? SystemRole::CenterOwner->value,
            ]
        );
    }
}
