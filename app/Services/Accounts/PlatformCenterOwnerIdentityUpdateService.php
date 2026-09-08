<?php

namespace App\Services\Accounts;

use App\Models\Person;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlatformCenterOwnerIdentityUpdateService
{
    public function __construct(
        private readonly UserAccountManagementService $accounts,
        private readonly AuditRecorder $audit
    ) {}

    /**
     * Update editable Center Owner identity/contact information.
     *
     * Immutable through this workflow:
     *
     * - Center
     * - National ID
     * - Role
     * - Login Identifier
     * - Account status
     * - Credential/lifecycle state
     *
     * @param array<string, mixed> $attributes
     */
    public function update(
        User $actor,
        User $account,
        array $attributes
    ): User {
        $data =
            $this->normalizeAttributes(
                $attributes
            );

        return DB::transaction(
            function () use (
                $actor,
                $account,
                $data
            ): User {
                /*
                 * This is deliberately the first business
                 * operation.
                 *
                 * UserAccountManagementService re-reads both
                 * actor and target account from persistence,
                 * checks Platform Owner authorization and
                 * platform tenant scope, and refuses a target
                 * that is not a Center Owner.
                 *
                 * Therefore Person data is never mutated before
                 * authoritative account authorization succeeds.
                 */
                $account =
                    $this->accounts
                    ->update(
                        $actor,
                        $account,
                        [
                            'recovery_email' =>
                            $data['recovery_email'],
                        ]
                    );

                if (
                    $account->center_id === null
                    || $account->person_id === null
                ) {
                    throw new DomainException(
                        'The Center Owner account does not have a valid Person and Center relationship.'
                    );
                }

                /*
                 * Resolve Person only from the persisted account
                 * returned by UserAccountManagementService.
                 *
                 * Never trust person_id / center_id supplied by
                 * an in-memory target model.
                 */
                $person =
                    Person::withoutGlobalScopes()
                    ->whereKey(
                        $account->person_id
                    )
                    ->where(
                        'center_id',
                        $account->center_id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($person === null) {
                    throw new DomainException(
                        'The Center Owner Person record no longer exists in the account Center.'
                    );
                }

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
                 * Do not create duplicate Audit history for a
                 * no-op identity update.
                 */
                if ($person->isDirty()) {
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
                } else {
                    $person->refresh();
                }

                /*
                 * Both the User update and Person update are
                 * inside this outer transaction.
                 *
                 * If the Person mutation or its Audit recording
                 * fails, any recovery-email update performed by
                 * UserAccountManagementService is rolled back too.
                 */
                $account->refresh();

                $account->loadMissing([
                    'role',
                    'person',
                    'center',
                ]);

                return $account;
            },
            3
        );
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{
     *     full_name:string,
     *     date_of_birth:string,
     *     city_of_residence:string,
     *     email:string,
     *     phone_number:string,
     *     recovery_email:string
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
            'recovery_email',
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
                'Center Owner identity update received unsupported fields: '
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

        $recoveryEmail =
            Str::lower(
                $this->requiredString(
                    $attributes,
                    'recovery_email',
                    'Recovery email',
                    255
                )
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
            || $date->format('Y-m-d')
            !== $dateOfBirth
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

        if (
            filter_var(
                $recoveryEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'A valid recovery email address is required.'
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

            'recovery_email' =>
            $recoveryEmail,
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
                $label . ' is required.'
            );
        }

        if (
            mb_strlen($value)
            > $maxLength
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
            $person->date_of_birth
                ?->format(
                    'Y-m-d'
                ),

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
}