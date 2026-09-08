<?php

namespace App\Services\Accounts;

use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CenterPersonIdentityUpdateService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    /**
     * Update shared Person identity information.
     *
     * Immutable through this workflow:
     *
     * - Center
     * - National ID
     * - Personal picture
     * - User Account identity / role / credentials
     *
     * @param array<string, mixed> $attributes
     */
    public function update(
        User $actor,
        Person $person,
        array $attributes
    ): Person {
        return DB::transaction(
            function () use (
                $actor,
                $person,
                $attributes
            ): Person {
                [
                    $actor,
                    $centerId,
                ] = $this->authorizedActor(
                    $actor
                );

                $person =
                    $this->lockPersistedPerson(
                        $person,
                        $centerId
                    );

                $data =
                    $this->normalizeAttributes(
                        $attributes
                    );

                $beforeValues =
                    $this->personAuditValues(
                        $person
                    );

                $person->forceFill([
                    'full_name' =>
                    $data['full_name'],

                    'date_of_birth' =>
                    $data['date_of_birth'],

                    'city_of_residence' =>
                    $data['city_of_residence'],

                    'email' =>
                    $data['email'],

                    'phone_number' =>
                    $data['phone_number'],
                ]);

                /*
                 * Avoid duplicate Audit history for a
                 * no-op identity update.
                 */
                if (! $person->isDirty()) {
                    return $person->refresh();
                }

                $person->save();
                $person->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'person.identity_updated',
                    subject: $person,
                    beforeValues: $beforeValues,
                    afterValues: $this->personAuditValues(
                        $person
                    )
                );

                return $person;
            },
            3
        );
    }

    /**
     * Person is shared across operational roles.
     *
     * Therefore only the Center Owner receives authority
     * to change shared identity data.
     *
     * @return array{0:User, 1:int}
     */
    private function authorizedActor(
        User $actor
    ): array {
        $actorId =
            $this->numericModelId(
                $actor,
                'identity-management actor'
            );

        $actor =
            User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $actorId
            )
            ->lockForUpdate()
            ->first();

        if ($actor === null) {
            throw new InvalidArgumentException(
                'The identity-management actor no longer exists.'
            );
        }

        if (
            $actor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active User Account may manage Person identity information.'
            );
        }

        if (
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            throw new AuthorizationException(
                'Only a Center Owner may manage shared Person identity information.'
            );
        }

        $center =
            $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
            || $actor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant Center do not match.'
            );
        }

        /*
         * A Person can simultaneously back Student and
         * Staff roles. Requiring both capabilities makes
         * this shared-identity operation deliberately
         * narrower than either domain alone.
         */
        Gate::forUser($actor)
            ->authorize(
                SystemPermission
                ::ManageStudentRecords
                    ->value
            );

        Gate::forUser($actor)
            ->authorize(
                SystemPermission
                ::ManageStaffAccounts
                    ->value
            );

        return [
            $actor,
            $center->id,
        ];
    }

    private function lockPersistedPerson(
        Person $person,
        int $centerId
    ): Person {
        $personId =
            $this->numericModelId(
                $person,
                'Person'
            );

        $person =
            Person::withoutGlobalScopes()
            ->whereKey(
                $personId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            throw new AuthorizationException(
                'The Person is outside the authorized Center scope.'
            );
        }

        return $person;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{
     *     full_name:string,
     *     date_of_birth:string,
     *     city_of_residence:string,
     *     email:string,
     *     phone_number:string
     * }
     */
    private function normalizeAttributes(
        array $attributes
    ): array {
        $allowedKeys = [
            'full_name',
            'date_of_birth',
            'city_of_residence',
            'email',
            'phone_number',
        ];

        $unsupported =
            array_values(
                array_diff(
                    array_keys(
                        $attributes
                    ),
                    $allowedKeys
                )
            );

        if ($unsupported !== []) {
            throw new DomainException(
                'Person identity update received unsupported fields: '
                    . implode(
                        ', ',
                        $unsupported
                    )
                    . '.'
            );
        }

        $fullName =
            $this->requiredString(
                $attributes,
                'full_name',
                'Full name',
                255
            );

        $city =
            $this->requiredString(
                $attributes,
                'city_of_residence',
                'City of residence',
                150
            );

        $email =
            Str::lower(
                $this->requiredString(
                    $attributes,
                    'email',
                    'Personal email',
                    255
                )
            );

        $phone =
            $this->requiredString(
                $attributes,
                'phone_number',
                'Phone number',
                50
            );

        $dateValue =
            $attributes['date_of_birth'] ?? null;

        if (
            $dateValue
            instanceof DateTimeInterface
        ) {
            $dateOfBirth =
                $dateValue->format(
                    'Y-m-d'
                );
        } else {
            $dateOfBirth =
                trim(
                    (string)
                    $dateValue
                );
        }

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $dateOfBirth
            );

        if (
            $date === false
            || $date->format(
                'Y-m-d'
            ) !== $dateOfBirth
        ) {
            throw new DomainException(
                'Date of birth must use a valid YYYY-MM-DD date.'
            );
        }

        if (
            $dateOfBirth
            > now()->toDateString()
        ) {
            throw new DomainException(
                'Date of birth cannot be in the future.'
            );
        }

        if (
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'A valid personal email address is required.'
            );
        }

        return [
            'full_name' =>
            $fullName,

            'date_of_birth' =>
            $dateOfBirth,

            'city_of_residence' =>
            $city,

            'email' =>
            $email,

            'phone_number' =>
            $phone,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requiredString(
        array $attributes,
        string $key,
        string $label,
        int $maxLength
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
                $label
                    . ' is required.'
            );
        }

        if (
            mb_strlen(
                $value
            ) > $maxLength
        ) {
            throw new DomainException(
                $label
                    . ' must not exceed '
                    . $maxLength
                    . ' characters.'
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
            $person
                ->date_of_birth
                ?->format(
                    'Y-m-d'
                ),

            'city_of_residence' =>
            $person
                ->city_of_residence,

            'email' =>
            $person->email,

            'phone_number' =>
            $person
                ->phone_number,

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
            || $model->getKey()
            === null
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
                && ctype_digit(
                    $key
                )
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