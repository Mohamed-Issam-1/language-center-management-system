<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Center;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model =
    Attendance::class;

    public function definition(): array
    {
        return [
            'center_id' =>
            Center::factory(),

            'session_id' =>
            function (
                array $attributes
            ): int {
                return ClassSession::factory()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            'enrollment_id' =>
            function (
                array $attributes
            ): int {
                $session =
                    ClassSession::query()
                    ->withoutGlobalScopes()
                    ->findOrFail(
                        $attributes['session_id']
                    );

                return Enrollment::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],

                        'class_id' =>
                        $session->class_id,

                        'enrollment_date' =>
                        $session
                            ->session_date
                            ->toDateString(),
                    ])
                    ->id;
            },

            'attendance_status_id' =>
            function (
                array $attributes
            ): int {
                return AttendanceStatus::factory()
                    ->active()
                    ->create([
                        'center_id' =>
                        $attributes['center_id'],
                    ])
                    ->id;
            },

            /*
             * Foundation factory only needs a Center-safe
             * persisted User reference.
             *
             * Role-level Attendance authorization is tested
             * separately in the authorization/service layer.
             */
            'recorded_by_user_id' =>
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

            'late_minutes' =>
            0,

            'excuse' =>
            null,

            'notes' =>
            null,

            'recorded_at' =>
            now(),

            'updated_at' =>
            now(),
        ];
    }

    public function forSession(
        ClassSession $session
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $session->center_id,

                'session_id' =>
                $session->id,
            ]
        );
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

    public function withStatus(
        AttendanceStatus $status
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $status->center_id,

                'attendance_status_id' =>
                $status->id,
            ]
        );
    }

    public function recordedBy(
        User $user
    ): static {
        return $this->state(
            fn(array $attributes): array => [
                'center_id' =>
                $user->center_id,

                'recorded_by_user_id' =>
                $user->id,
            ]
        );
    }
}
