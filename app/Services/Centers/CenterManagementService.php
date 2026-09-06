<?php

namespace App\Services\Centers;

use App\Models\Center;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use DomainException;

class CenterManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $user,
        array $attributes
    ): Center {
        $this->ensurePlatformScope(
            $user
        );

        Gate::forUser($user)
            ->authorize(
                'create',
                Center::class
            );

        return DB::transaction(
            function () use (
                $user,
                $attributes
            ): Center {
                /*
                 * Center lifecycle state is never accepted through
                 * general creation input.
                 *
                 * A new Center begins Suspended according to the
                 * database default and must be activated explicitly.
                 */
                $identifierCode =
                    $this->normalizeIdentifierCode(
                        $attributes['identifier_code'] ?? null
                    );

                $data = Arr::only(
                    $attributes,
                    [
                        'code',
                        'name',
                        'email',
                        'phone',
                        'address',
                        'timezone',
                        'operating_currency_code',
                    ]
                );

                $data['identifier_code'] =
                    $identifierCode;

                $center = Center::query()
                    ->create(
                        $data
                    )
                    ->refresh();

                /*
                * Refresh before recording Audit history because status is
                * supplied by the database default rather than creation input.
                *
                * Audit must reflect the actual persisted state.
                */
                $this->audit->record(
                    actor: $user,
                    actionType: 'center.created',
                    subject: $center,
                    afterValues: $this->centerAuditValues(
                        $center
                    )
                );

                return $center;
            },
            3
        );
    }

    public function update(
        User $user,
        Center $center,
        array $attributes
    ): Center {
        $this->ensurePlatformScope(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $center,
                $attributes
            ): Center {
                $center = $this->lockPersistedCenter(
                    $center
                );

                Gate::forUser($user)
                    ->authorize(
                        'update',
                        $center
                    );

                $beforeValues =
                    $this->centerAuditValues(
                        $center
                    );

                /*
                 * Status is intentionally excluded.
                 *
                 * Lifecycle changes must go through activate()
                 * or suspend().
                 */
                $center->fill(
                    Arr::only(
                        $attributes,
                        [
                            'code',
                            'name',
                            'email',
                            'phone',
                            'address',
                            'timezone',
                            'operating_currency_code',
                        ]
                    )
                );

                if (! $center->isDirty()) {
                    return $center;
                }

                $center->save();
                $center->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'center.updated',
                    subject: $center,
                    beforeValues: $beforeValues,
                    afterValues: $this->centerAuditValues(
                        $center
                    )
                );

                return $center;
            },
            3
        );
    }

    public function activate(
        User $user,
        Center $center
    ): Center {
        $this->ensurePlatformScope(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $center
            ): Center {
                $center = $this->lockPersistedCenter(
                    $center
                );

                Gate::forUser($user)
                    ->authorize(
                        'activate',
                        $center
                    );

                if (
                    $center->status
                    === CenterStatus::Active
                ) {
                    return $center;
                }

                $beforeStatus =
                    $center->status;

                $center->update([
                    'status' =>
                    CenterStatus::Active,
                ]);

                $center->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'center.activated',
                    subject: $center,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' => $center->status,
                    ]
                );

                return $center;
            },
            3
        );
    }

    public function suspend(
        User $user,
        Center $center
    ): Center {
        $this->ensurePlatformScope(
            $user
        );

        return DB::transaction(
            function () use (
                $user,
                $center
            ): Center {
                $center = $this->lockPersistedCenter(
                    $center
                );

                Gate::forUser($user)
                    ->authorize(
                        'suspend',
                        $center
                    );

                if (
                    $center->status
                    === CenterStatus::Suspended
                ) {
                    return $center;
                }

                $beforeStatus =
                    $center->status;

                /*
                 * Suspending a Center preserves all Center,
                 * Person, User, Branch, Classroom, and historical
                 * records. Authentication already blocks accounts
                 * whose Center is not Active.
                 */
                $center->update([
                    'status' =>
                    CenterStatus::Suspended,
                ]);

                $center->refresh();

                $this->audit->record(
                    actor: $user,
                    actionType: 'center.suspended',
                    subject: $center,
                    beforeValues: [
                        'status' => $beforeStatus,
                    ],
                    afterValues: [
                        'status' => $center->status,
                    ]
                );

                return $center;
            },
            3
        );
    }

    private function ensurePlatformScope(
        User $user
    ): void {
        if (
            ! $this->tenant->isEstablished()
            || ! $this->tenant->isPlatformScoped()
        ) {
            throw new AuthorizationException(
                'Center management requires platform-scoped tenant context.'
            );
        }

        /*
         * Do not rely only on a permission string.
         *
         * Center management is a Platform Owner operation and the
         * Platform Owner account itself must remain platform-scoped.
         */
        if (
            $user->systemRole()
            !== SystemRole::PlatformOwner
            || $user->center_id !== null
            || $user->person_id !== null
        ) {
            throw new AuthorizationException(
                'The account cannot manage language centers.'
            );
        }
    }

    private function lockPersistedCenter(
        Center $center
    ): Center {
        /*
         * Center is the tenant root and therefore intentionally has
         * no Center global scope. Re-read the persisted row so
         * authorization and Audit history do not depend on stale
         * or locally modified Eloquent state.
         */
        return Center::query()
            ->whereKey(
                $center->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeIdentifierCode(
        mixed $value
    ): string {
        if (! is_string($value)) {
            throw new DomainException(
                'A Center identifier code is required.'
            );
        }

        $identifierCode = trim(
            $value
        );

        if (
            ! preg_match(
                '/^(0[1-9]|[1-9][0-9])$/',
                $identifierCode
            )
        ) {
            throw new DomainException(
                'The Center identifier code must be a two-digit value from 01 through 99.'
            );
        }

        return $identifierCode;
    }

    /**
     * @return array<string, mixed>
     */
    private function centerAuditValues(
        Center $center
    ): array {
        return [
            'code' =>
            $center->code,

            'identifier_code' =>
            $center->identifier_code,

            'name' =>
            $center->name,

            'email' =>
            $center->email,

            'phone' =>
            $center->phone,

            'address' =>
            $center->address,

            'timezone' =>
            $center->timezone,

            'operating_currency_code' =>
            $center->operating_currency_code,

            'status' =>
            $center->status,
        ];
    }
}
