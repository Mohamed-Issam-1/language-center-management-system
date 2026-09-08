<?php

namespace App\Services\Accounts;

use App\Models\Center;
use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlatformCenterOwnerProvisioningService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly UserAccountManagementService $accounts,
        private readonly AccountIdentifierGenerator $identifiers,
        private readonly AuditRecorder $audit
    ) {}

    /**
     * @param array<string, mixed> $identity
     *
     * @return array{
     *     account: User,
     *     person: Person,
     *     temporary_password: string,
     *     recipient_email: string
     * }
     */
    public function provision(
        User $actor,
        Center $center,
        array $identity
    ): array {
        return DB::transaction(
            function () use (
                $actor,
                $center,
                $identity
            ): array {
                $actor =
                    $this->lockPersistedActor(
                        $actor
                    );

                $center =
                    $this->lockPersistedCenter(
                        $center
                    );

                $this->authorizeProvisioning(
                    $actor
                );

                $identity =
                    $this->normalizeIdentity(
                        $identity
                    );

                $person =
                    $this->resolveAndSynchronizePerson(
                        $actor,
                        $center,
                        $identity
                    );

                /*
                 * Identifier allocation remains inside the
                 * provisioning transaction.
                 *
                 * If account creation fails later, the sequence
                 * allocation rolls back with the Person changes.
                 */
                $accountIdentifier =
                    $this->identifiers
                    ->generate(
                        $center,
                        SystemRole::CenterOwner
                    );

                /*
                 * Plaintext exists only for initial credential
                 * delivery and is never persisted.
                 */
                $temporaryPassword =
                    Str::password(20);

                /*
                 * UserAccountManagementService remains the
                 * authoritative account-management boundary.
                 *
                 * Because the complete Person now exists, the
                 * low-level service safely reuses that identity
                 * instead of creating an incomplete Person.
                 */
                $account =
                    $this->accounts
                    ->create(
                        actor: $actor,
                        center: $center,
                        nationalIdNumber: $identity['national_id_number'],
                        role: SystemRole::CenterOwner,
                        accountLoginIdentifier: $accountIdentifier,
                        recoveryEmail: $identity['email'],
                        temporaryPassword: $temporaryPassword
                    );

                $person->refresh();

                $account->refresh();

                $account->loadMissing([
                    'role',
                    'person',
                    'center',
                ]);

                return [
                    'account' =>
                    $account,

                    'person' =>
                    $person,

                    'temporary_password' =>
                    $temporaryPassword,

                    'recipient_email' =>
                    $identity['email'],
                ];
            },
            3
        );
    }

    private function authorizeProvisioning(
        User $actor
    ): void {
        if (
            $actor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active Platform Owner may provision Center Owner accounts.'
            );
        }

        if (
            $actor->systemRole()
            !== SystemRole::PlatformOwner
        ) {
            throw new AuthorizationException(
                'Only the Platform Owner may provision Center Owner accounts.'
            );
        }

        if (
            $actor->center_id !== null
            || $actor->person_id !== null
        ) {
            throw new AuthorizationException(
                'Platform Owner account scope is invalid.'
            );
        }

        if (
            ! $this->tenant
                ->isEstablished()
            || ! $this->tenant
                ->isPlatformScoped()
        ) {
            throw new AuthorizationException(
                'Center Owner provisioning requires platform tenant scope.'
            );
        }

        Gate::forUser($actor)
            ->authorize(
                SystemPermission
                ::ManageCenterOwnerAccounts
                    ->value
            );
    }

    /**
     * @param array<string, mixed> $identity
     *
     * @return array{
     *     national_id_number: string,
     *     full_name: string,
     *     date_of_birth: string,
     *     city_of_residence: string,
     *     email: string,
     *     phone_number: string
     * }
     */
    private function normalizeIdentity(
        array $identity
    ): array {
        $allowedKeys = [
            'national_id_number',
            'full_name',
            'date_of_birth',
            'city_of_residence',
            'email',
            'phone_number',
        ];

        $unsupported =
            array_values(
                array_diff(
                    array_keys($identity),
                    $allowedKeys
                )
            );

        if ($unsupported !== []) {
            throw new DomainException(
                'Center Owner provisioning received unsupported fields: '
                    . implode(', ', $unsupported)
                    . '.'
            );
        }

        $nationalIdNumber =
            $this->requiredString(
                $identity,
                'national_id_number',
                'National ID Number'
            );

        $fullName =
            $this->requiredString(
                $identity,
                'full_name',
                'Full name'
            );

        $dateOfBirth =
            $this->requiredString(
                $identity,
                'date_of_birth',
                'Date of birth'
            );

        $cityOfResidence =
            $this->requiredString(
                $identity,
                'city_of_residence',
                'City of residence'
            );

        $email =
            Str::lower(
                $this->requiredString(
                    $identity,
                    'email',
                    'Email address'
                )
            );

        $phoneNumber =
            $this->requiredString(
                $identity,
                'phone_number',
                'Phone number'
            );

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $dateOfBirth
            );

        if (
            $date === false
            || $date->format('Y-m-d')
            !== $dateOfBirth
        ) {
            throw new DomainException(
                'Date of birth must use a valid YYYY-MM-DD date.'
            );
        }

        if (
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'A valid email address is required.'
            );
        }

        return [
            'national_id_number' =>
            $nationalIdNumber,

            'full_name' =>
            $fullName,

            'date_of_birth' =>
            $dateOfBirth,

            'city_of_residence' =>
            $cityOfResidence,

            'email' =>
            $email,

            'phone_number' =>
            $phoneNumber,
        ];
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function resolveAndSynchronizePerson(
        User $actor,
        Center $center,
        array $identity
    ): Person {
        $person =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'national_id_number',
                $identity['national_id_number']
            )
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            $person =
                Person::withoutGlobalScopes()
                ->create([
                    'center_id' =>
                    $center->id,

                    'national_id_number' =>
                    $identity['national_id_number'],

                    'full_name' =>
                    $identity['full_name'],

                    'date_of_birth' =>
                    $identity['date_of_birth'],

                    'city_of_residence' =>
                    $identity['city_of_residence'],

                    'email' =>
                    $identity['email'],

                    'phone_number' =>
                    $identity['phone_number'],
                ])
                ->refresh();

            $this->audit->record(
                actor: $actor,
                actionType: 'person.center_owner_identity_created',
                subject: $person,
                afterValues: $this->personAuditValues(
                    $person
                )
            );

            return $person;
        }

        $beforeValues =
            $this->personAuditValues(
                $person
            );

        /*
         * National ID and Center are identity keys and are not
         * movable through this workflow.
         *
         * Existing personal-picture state is also preserved.
         */
        $person->forceFill([
            'full_name' =>
            $identity['full_name'],

            'date_of_birth' =>
            $identity['date_of_birth'],

            'city_of_residence' =>
            $identity['city_of_residence'],

            'email' =>
            $identity['email'],

            'phone_number' =>
            $identity['phone_number'],
        ]);

        if (! $person->isDirty()) {
            return $person->refresh();
        }

        $person->save();
        $person->refresh();

        $this->audit->record(
            actor: $actor,
            actionType: 'person.center_owner_identity_updated',
            subject: $person,
            beforeValues: $beforeValues,
            afterValues: $this->personAuditValues(
                $person
            )
        );

        return $person;
    }

    private function lockPersistedActor(
        User $actor
    ): User {
        $actorId =
            $this->numericModelId(
                $actor,
                'Platform Owner'
            );

        $persisted =
            User::withoutGlobalScopes()
            ->whereKey($actorId)
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new InvalidArgumentException(
                'The authenticated User Account no longer exists.'
            );
        }

        $persisted->loadMissing(
            'role'
        );

        return $persisted;
    }

    private function lockPersistedCenter(
        Center $center
    ): Center {
        $centerId =
            $this->numericModelId(
                $center,
                'Center'
            );

        $persisted =
            Center::query()
            ->whereKey($centerId)
            ->lockForUpdate()
            ->first();

        if ($persisted === null) {
            throw new InvalidArgumentException(
                'The target Center no longer exists.'
            );
        }

        return $persisted;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requiredString(
        array $attributes,
        string $key,
        string $label
    ): string {
        $value =
            trim(
                (string) (
                    $attributes[$key]
                    ?? ''
                )
            );

        if ($value === '') {
            throw new DomainException(
                $label . ' is required.'
            );
        }

        if (
            mb_strlen($value)
            > 255
        ) {
            throw new DomainException(
                $label
                    . ' must not exceed 255 characters.'
            );
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function personAuditValues(
        Person $person
    ): array {
        return [
            'center_id' =>
            $person->center_id,

            'national_id_number' =>
            $person
                ->national_id_number,

            'full_name' =>
            $person->full_name,

            'date_of_birth' =>
            $person->date_of_birth,

            'city_of_residence' =>
            $person
                ->city_of_residence,

            'email' =>
            $person->email,

            'phone_number' =>
            $person->phone_number,

            'personal_picture_path' =>
            $person
                ->personal_picture_path,
        ];
    }

    private function numericModelId(
        Model $model,
        string $label
    ): int {
        if (
            ! $model->exists
            || $model->getKey() === null
        ) {
            throw new InvalidArgumentException(
                $label
                    . ' must be a persisted record.'
            );
        }

        $key =
            $model->getKey();

        if (
            ! is_int($key)
            && ! (
                is_string($key)
                && ctype_digit($key)
            )
        ) {
            throw new InvalidArgumentException(
                $label
                    . ' must use a numeric identifier.'
            );
        }

        return (int) $key;
    }
}