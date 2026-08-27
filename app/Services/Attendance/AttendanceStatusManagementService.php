<?php

namespace App\Services\Attendance;

use App\Models\AttendanceStatus;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AttendanceStatusManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $actor,
        array $attributes
    ): AttendanceStatus {
        $center = $this->tenant
            ->requireCenter();

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $attributes,
                $center,
                $centerId
            ): AttendanceStatus {
                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            AttendanceStatus::class,
                            $center,
                        ]
                    );

                $name =
                    $this->requiredString(
                        $attributes,
                        'name',
                        'Attendance Status name',
                        100
                    );

                $code =
                    $this->requiredString(
                        $attributes,
                        'code',
                        'Attendance Status code',
                        50
                    );

                $contributionValue =
                    $this->requiredContributionValue(
                        $attributes
                    );

                $this->ensureCodeAvailable(
                    $centerId,
                    $code
                );

                /*
                 * Center ownership and lifecycle state are never
                 * trusted from request input.
                 *
                 * Every newly configured Attendance Status starts
                 * Active.
                 */
                $status =
                    AttendanceStatus::query()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'name' =>
                        $name,

                        'code' =>
                        $code,

                        'contribution_value' =>
                        $contributionValue,

                        'is_active' =>
                        true,
                    ]);

                $status->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'attendance_status.created',
                    subject: $status,
                    afterValues: $this->auditValues(
                        $status
                    )
                );

                return $status;
            },
            3
        );
    }

    public function update(
        User $actor,
        AttendanceStatus $status,
        array $attributes
    ): AttendanceStatus {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $status,
                $attributes,
                $centerId
            ): AttendanceStatus {
                $status =
                    $this->lockPersistedStatus(
                        $status,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $status
                    );

                $beforeValues =
                    $this->auditValues(
                        $status
                    );

                $data = [];

                if (
                    array_key_exists(
                        'name',
                        $attributes
                    )
                ) {
                    $data['name'] =
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Attendance Status name',
                            100
                        );
                }

                if (
                    array_key_exists(
                        'code',
                        $attributes
                    )
                ) {
                    $code =
                        $this->requiredString(
                            $attributes,
                            'code',
                            'Attendance Status code',
                            50
                        );

                    if (
                        $code
                        !== $status->code
                    ) {
                        $this->ensureCodeAvailable(
                            $centerId,
                            $code,
                            $status->id
                        );
                    }

                    $data['code'] =
                        $code;
                }

                if (
                    array_key_exists(
                        'contribution_value',
                        $attributes
                    )
                ) {
                    $data['contribution_value'] =
                        $this->contributionValue(
                            $attributes['contribution_value']
                        );
                }

                /*
                 * center_id and is_active intentionally cannot
                 * be changed through general update.
                 *
                 * Lifecycle changes use activate/deactivate.
                 */
                $status->fill(
                    $data
                );

                if (! $status->isDirty()) {
                    return $status;
                }

                $status->save();
                $status->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'attendance_status.updated',
                    subject: $status,
                    beforeValues: $beforeValues,
                    afterValues: $this->auditValues(
                        $status
                    )
                );

                return $status;
            },
            3
        );
    }

    public function activate(
        User $actor,
        AttendanceStatus $status
    ): AttendanceStatus {
        return $this->changeActiveState(
            $actor,
            $status,
            true
        );
    }

    public function deactivate(
        User $actor,
        AttendanceStatus $status
    ): AttendanceStatus {
        return $this->changeActiveState(
            $actor,
            $status,
            false
        );
    }

    private function changeActiveState(
        User $actor,
        AttendanceStatus $status,
        bool $active
    ): AttendanceStatus {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        return DB::transaction(
            function () use (
                $actor,
                $status,
                $active,
                $centerId
            ): AttendanceStatus {
                $status =
                    $this->lockPersistedStatus(
                        $status,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        $active
                            ? 'activate'
                            : 'deactivate',
                        $status
                    );

                /*
                 * Lifecycle operations are idempotent.
                 *
                 * Repeating the same state does not create
                 * duplicate Audit Records.
                 */
                if (
                    $status->is_active
                    === $active
                ) {
                    return $status;
                }

                $beforeValues =
                    $this->auditValues(
                        $status
                    );

                $status->forceFill([
                    'is_active' =>
                    $active,
                ]);

                $status->save();
                $status->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: $active
                        ? 'attendance_status.activated'
                        : 'attendance_status.deactivated',
                    subject: $status,
                    beforeValues: $beforeValues,
                    afterValues: $this->auditValues(
                        $status
                    )
                );

                return $status;
            },
            3
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center = $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function lockPersistedStatus(
        AttendanceStatus $status,
        int $centerId
    ): AttendanceStatus {
        if (
            ! $status->exists
            || $status->getKey() === null
        ) {
            throw new AuthorizationException(
                'Attendance Status must be a persisted record in the current Center.'
            );
        }

        $persisted =
            AttendanceStatus::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $status->getKey()
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new AuthorizationException(
                'Attendance Status is outside the current Center tenant.'
            );
        }

        return $persisted;
    }

    private function ensureCodeAvailable(
        int $centerId,
        string $code,
        ?int $ignoreStatusId = null
    ): void {
        $query =
            AttendanceStatus::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'code',
                $code
            );

        if ($ignoreStatusId !== null) {
            $query->whereKeyNot(
                $ignoreStatusId
            );
        }

        if (
            $query
            ->lockForUpdate()
            ->first()
            !== null
        ) {
            throw new DomainException(
                'Attendance Status code must be unique inside the Center.'
            );
        }
    }

    private function requiredString(
        array $attributes,
        string $key,
        string $label,
        int $maxLength
    ): string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value =
            $attributes[$key];

        if (! is_string($value)) {
            throw new DomainException(
                "{$label} must be a string."
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            throw new DomainException(
                "{$label} must not be blank."
            );
        }

        if (
            mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function requiredContributionValue(
        array $attributes
    ): string {
        if (
            ! array_key_exists(
                'contribution_value',
                $attributes
            )
        ) {
            throw new DomainException(
                'Attendance Status contribution value is required.'
            );
        }

        return $this->contributionValue(
            $attributes['contribution_value']
        );
    }

    private function contributionValue(
        mixed $value
    ): string {
        if (
            is_bool($value)
            || ! (
                is_int($value)
                || is_float($value)
                || (
                    is_string($value)
                    && is_numeric(
                        trim($value)
                    )
                )
            )
        ) {
            throw new DomainException(
                'Attendance Status contribution value must be numeric.'
            );
        }

        $number = (float) $value;

        if (
            ! is_finite($number)
            || $number < 0
            || $number > 100
        ) {
            throw new DomainException(
                'Attendance Status contribution value must be between 0 and 100.'
            );
        }

        return number_format(
            $number,
            2,
            '.',
            ''
        );
    }

    /**
     * @return array{
     *     name:string,
     *     code:string,
     *     contribution_value:string,
     *     is_active:bool
     * }
     */
    private function auditValues(
        AttendanceStatus $status
    ): array {
        return [
            'name' =>
            $status->name,

            'code' =>
            $status->code,

            'contribution_value' =>
            $status->contribution_value,

            'is_active' =>
            $status->is_active,
        ];
    }
}
