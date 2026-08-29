<?php

namespace App\Services\Registration;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class RegistrationSubmissionService
{
    public function __construct(
        private readonly AccountIdentifierGenerator $identifiers
    ) {}

    public function submit(
        Center $center,
        array $attributes,
        ?UploadedFile $personalPicture = null
    ): RegistrationRequest {
        $identity = $this->normalizeIdentity(
            $attributes
        );

        if (
            $center->status
            !== CenterStatus::Active
        ) {
            throw new DomainException(
                'Registration is not available for this language center.'
            );
        }

        $storedPicturePath = null;

        try {
            if ($personalPicture !== null) {
                $storedPicturePath =
                    $personalPicture->store(
                        'registration-requests/'
                            . $center->getKey()
                            . '/personal-pictures',
                        'local'
                    );

                if (
                    ! is_string($storedPicturePath)
                    || trim($storedPicturePath) === ''
                ) {
                    throw new RuntimeException(
                        'The personal picture could not be stored.'
                    );
                }
            }

            return DB::transaction(
                function () use (
                    $center,
                    $attributes,
                    $identity,
                    $storedPicturePath
                ): RegistrationRequest {
                    $persistedCenter =
                        Center::query()
                            ->whereKey(
                                $center->getKey()
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                    if (
                        $persistedCenter->status
                        !== CenterStatus::Active
                    ) {
                        throw new DomainException(
                            'Registration is not available for this language center.'
                        );
                    }

                    /*
                     * Self-registration is for a selected
                     * Center and Branch.
                     */
                    $branch =
                        Branch::withoutGlobalScopes()
                            ->whereKey(
                                $attributes['branch_id']
                            )
                            ->where(
                                'center_id',
                                $persistedCenter->id
                            )
                            ->lockForUpdate()
                            ->first();

                    if (
                        $branch === null
                        || $branch->status
                        !== BranchStatus::Active
                    ) {
                        throw new DomainException(
                            'The selected branch is not available for registration.'
                        );
                    }

                    /*
                     * Find or create the Person using
                     * Center + National ID.
                     */
                    $person =
                        Person::withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $persistedCenter->id
                            )
                            ->where(
                                'national_id_number',
                                $identity[
                                    'national_id_number'
                                ]
                            )
                            ->lockForUpdate()
                            ->first();

                    if ($person === null) {
                        $person =
                            Person::withoutGlobalScopes()
                                ->create([
                                    'center_id' =>
                                        $persistedCenter->id,

                                    'national_id_number' =>
                                        $identity[
                                            'national_id_number'
                                        ],

                                    'full_name' =>
                                        $identity[
                                            'full_name'
                                        ],

                                    'date_of_birth' =>
                                        $identity[
                                            'date_of_birth'
                                        ],

                                    'city_of_residence' =>
                                        $identity[
                                            'city_of_residence'
                                        ],

                                    'email' =>
                                        $identity['email'],

                                    'phone_number' =>
                                        $identity[
                                            'phone_number'
                                        ],

                                    'personal_picture_path' =>
                                        $storedPicturePath,
                                ]);
                    }

                    /*
                     * Public self-registration is always Student.
                     */
                    $studentRole =
                        Role::query()
                            ->where(
                                'code',
                                SystemRole::Student->value
                            )
                            ->first();

                    if ($studentRole === null) {
                        throw new LogicException(
                            'The Student role has not been seeded.'
                        );
                    }

                    /*
                     * A Person cannot have two Student
                     * accounts in the same Center.
                     */
                    $studentAccountExists =
                        User::withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $persistedCenter->id
                            )
                            ->where(
                                'person_id',
                                $person->id
                            )
                            ->where(
                                'role_id',
                                $studentRole->id
                            )
                            ->lockForUpdate()
                            ->exists();

                    if ($studentAccountExists) {
                        throw new DomainException(
                            'This person already has a Student account in this language center.'
                        );
                    }

                    /*
                     * Prevent multiple pending requests
                     * for the same Person.
                     */
                    $pendingExists =
                        RegistrationRequest::withoutGlobalScopes()
                            ->where(
                                'center_id',
                                $persistedCenter->id
                            )
                            ->where(
                                'person_id',
                                $person->id
                            )
                            ->where(
                                'pending_marker',
                                1
                            )
                            ->exists();

                    if ($pendingExists) {
                        throw new DomainException(
                            'A pending registration request already exists for this person in this language center.'
                        );
                    }

                    /*
                     * The login identifier is system-generated.
                     */
                    $accountIdentifier =
                        $this->identifiers->generate(
                            $persistedCenter,
                            SystemRole::Student
                        );

                    /*
                     * Temporary Breeze compatibility.
                     *
                     * Authentication uses
                     * account_login_identifier and
                     * recovery_email.
                     */
                    $legacyEmail = sprintf(
                        '%s@internal.lcms.invalid',
                        Str::uuid()
                    );

                    /*
                     * SRS FR-46:
                     * create the User now as Pending.
                     */
                    $account =
                        User::withoutGlobalScopes()
                            ->create([
                                'center_id' =>
                                    $persistedCenter->id,

                                'person_id' =>
                                    $person->id,

                                'role_id' =>
                                    $studentRole->id,

                                'account_login_identifier' =>
                                    $accountIdentifier,

                                'recovery_email' =>
                                    $identity['email'],

                                'status' =>
                                    AccountStatus::Pending,

                                'password' =>
                                    Hash::make(
                                        $attributes[
                                            'password'
                                        ]
                                    ),

                                'name' =>
                                    $identity[
                                        'full_name'
                                    ],

                                'email' =>
                                    $legacyEmail,
                            ]);

                    /*
                     * The applicant chose a permanent password.
                     * This is not a temporary-password account.
                     */
                    $account->forceFill([
                        'must_change_password' =>
                            false,

                        'temporary_password_used_at' =>
                            null,

                        'failed_login_attempts' =>
                            0,

                        'locked_until' =>
                            null,

                        'last_login_at' =>
                            null,

                        'password_changed_at' =>
                            now(),

                        'deactivated_at' =>
                            null,
                    ])->save();

                    /*
                     * The request is already classified
                     * as Student and already assigned to
                     * the selected Branch.
                     */
                    return RegistrationRequest::withoutGlobalScopes()
                        ->create([
                            'center_id' =>
                                $persistedCenter->id,

                            'person_id' =>
                                $person->id,

                            'user_id' =>
                                $account->id,

                            'national_id_number' =>
                                $identity[
                                    'national_id_number'
                                ],

                            'full_name' =>
                                $identity[
                                    'full_name'
                                ],

                            'date_of_birth' =>
                                $identity[
                                    'date_of_birth'
                                ],

                            'city_of_residence' =>
                                $identity[
                                    'city_of_residence'
                                ],

                            'email' =>
                                $identity['email'],

                            'phone_number' =>
                                $identity[
                                    'phone_number'
                                ],

                            'personal_picture_path' =>
                                $storedPicturePath,

                            'status' =>
                                RegistrationRequestStatus::Pending,

                            'selected_role_id' =>
                                $studentRole->id,

                            'selected_branch_id' =>
                                $branch->id,

                            'reviewed_by_user_id' =>
                                null,

                            'reviewed_at' =>
                                null,

                            'rejection_reason' =>
                                null,

                            'pending_marker' =>
                                1,
                        ]);
                },
                3
            );
        } catch (Throwable $exception) {
            if (
                $storedPicturePath !== null
            ) {
                Storage::disk(
                    'local'
                )->delete(
                    $storedPicturePath
                );
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $attributes
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
        array $attributes
    ): array {
        $identity = [
            'national_id_number' =>
                trim(
                    (string) (
                        $attributes[
                            'national_id_number'
                        ] ?? ''
                    )
                ),

            'full_name' =>
                trim(
                    (string) (
                        $attributes[
                            'full_name'
                        ] ?? ''
                    )
                ),

            'date_of_birth' =>
                trim(
                    (string) (
                        $attributes[
                            'date_of_birth'
                        ] ?? ''
                    )
                ),

            'city_of_residence' =>
                trim(
                    (string) (
                        $attributes[
                            'city_of_residence'
                        ] ?? ''
                    )
                ),

            'email' =>
                Str::lower(
                    trim(
                        (string) (
                            $attributes[
                                'email'
                            ] ?? ''
                        )
                    )
                ),

            'phone_number' =>
                trim(
                    (string) (
                        $attributes[
                            'phone_number'
                        ] ?? ''
                    )
                ),
        ];

        foreach ($identity as $value) {
            if ($value === '') {
                throw new DomainException(
                    'Complete registration identity information is required.'
                );
            }
        }

        return $identity;
    }
}