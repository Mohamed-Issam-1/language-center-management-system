<?php

namespace App\Services\Registration;

use App\Models\Center;
use App\Models\RegistrationRequest;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\RegistrationRequestStatus;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RegistrationSubmissionService
{
    public function submit(
        Center $center,
        array $attributes,
        ?UploadedFile $personalPicture = null
    ): RegistrationRequest {
        $identity =
            $this->normalizeIdentity(
                $attributes
            );

        /*
         * This preliminary check avoids unnecessary file I/O.
         *
         * The Center is loaded again and locked inside the
         * transaction before any business state is persisted.
         */
        if (
            $center->status
            !== CenterStatus::Active
        ) {
            throw new DomainException(
                'Registration is not available for this language center.'
            );
        }

        $storedPicturePath =
            null;

        try {
            /*
             * Registration pictures contain personal data.
             *
             * Store them on the private local disk, never the
             * public disk.
             */
            if ($personalPicture !== null) {
                $storedPicturePath =
                    $personalPicture->store(
                        'registration-requests/'
                            . $center->getKey()
                            . '/personal-pictures',
                        'local'
                    );

                if (
                    ! is_string(
                        $storedPicturePath
                    )
                    || trim(
                        $storedPicturePath
                    ) === ''
                ) {
                    throw new RuntimeException(
                        'The personal picture could not be stored.'
                    );
                }
            }

            return DB::transaction(
                function () use (
                    $center,
                    $identity,
                    $storedPicturePath
                ): RegistrationRequest {
                    /*
                     * Serialize public registration submissions
                     * per Center.
                     *
                     * This makes the application-level duplicate
                     * check safe against concurrent submissions,
                     * while the database unique constraint remains
                     * the final invariant.
                     */
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

                    $pendingExists =
                        RegistrationRequest::withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $persistedCenter->id
                        )
                        ->where(
                            'national_id_number',
                            $identity['national_id_number']
                        )
                        ->where(
                            'pending_marker',
                            1
                        )
                        ->exists();

                    if ($pendingExists) {
                        throw new DomainException(
                            'A pending registration request already exists for this National ID in this language center.'
                        );
                    }

                    /*
                     * Public submission never creates Person,
                     * User, Student, Teacher, or Staff records.
                     *
                     * Administrative review and approval owns
                     * that workflow.
                     */
                    return RegistrationRequest::withoutGlobalScopes()
                        ->create([
                            'center_id' =>
                            $persistedCenter->id,

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

                            'personal_picture_path' =>
                            $storedPicturePath,

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
                        ]);
                },
                3
            );
        } catch (Throwable $exception) {
            /*
             * Filesystem writes are outside the database
             * transaction.
             *
             * Compensate if validation of persisted business
             * state or database persistence fails afterward.
             */
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
                    $attributes['national_id_number'] ?? ''
                )
            ),

            'full_name' =>
            trim(
                (string) (
                    $attributes['full_name'] ?? ''
                )
            ),

            'date_of_birth' =>
            trim(
                (string) (
                    $attributes['date_of_birth'] ?? ''
                )
            ),

            'city_of_residence' =>
            trim(
                (string) (
                    $attributes['city_of_residence'] ?? ''
                )
            ),

            'email' =>
            Str::lower(
                trim(
                    (string) (
                        $attributes['email'] ?? ''
                    )
                )
            ),

            'phone_number' =>
            trim(
                (string) (
                    $attributes['phone_number'] ?? ''
                )
            ),
        ];

        foreach (
            $identity as $value
        ) {
            if ($value === '') {
                throw new DomainException(
                    'Complete registration identity information is required.'
                );
            }
        }

        return $identity;
    }
}
