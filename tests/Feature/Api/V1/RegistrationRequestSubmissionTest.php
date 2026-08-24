<?php

namespace Tests\Feature\Api\V1;

use App\Models\Center;
use App\Models\RegistrationRequest;
use App\Support\Enums\RegistrationRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationRequestSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_can_submit_pending_registration_request_for_active_center(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'code' =>
                'REG-CENTER-01',
            ]);

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload()
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Registration request submitted successfully.'
            )
            ->assertJsonPath(
                'data.center_code',
                'REG-CENTER-01'
            )
            ->assertJsonPath(
                'data.status',
                RegistrationRequestStatus::Pending->value
            )
            ->assertJsonStructure([
                'message',

                'data' => [
                    'id',
                    'center_code',
                    'status',
                    'submitted_at',
                ],
            ]);

        $this->assertDatabaseHas(
            'registration_requests',
            [
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '123456789',

                'full_name' =>
                'Ahmad Mohammed',

                'date_of_birth' =>
                '2001-05-15',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'ahmad@example.test',

                'phone_number' =>
                '+970599123456',

                'personal_picture_path' =>
                null,

                'status' =>
                RegistrationRequestStatus::Pending->value,

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
            ]
        );

        /*
         * Public registration creates the review request only.
         */
        $this->assertDatabaseCount(
            'people',
            0
        );

        $this->assertDatabaseCount(
            'users',
            0
        );

        $this->assertDatabaseCount(
            'students',
            0
        );

        $this->assertDatabaseCount(
            'teachers',
            0
        );

        $this->assertDatabaseCount(
            'branch_managers',
            0
        );

        $this->assertDatabaseCount(
            'finance_employees',
            0
        );
    }

    public function test_submission_normalizes_identity_strings_and_email(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload([
                    'national_id_number' =>
                    '  123456789  ',

                    'full_name' =>
                    '  Ahmad Mohammed  ',

                    'city_of_residence' =>
                    '  Gaza  ',

                    'email' =>
                    '  AHMAD@EXAMPLE.TEST  ',

                    'phone_number' =>
                    '  +970599123456  ',
                ])
            );

        $response->assertCreated();

        $this->assertDatabaseHas(
            'registration_requests',
            [
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '123456789',

                'full_name' =>
                'Ahmad Mohammed',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'ahmad@example.test',

                'phone_number' =>
                '+970599123456',
            ]
        );
    }

    public function test_optional_personal_picture_is_stored_on_private_local_disk(): void
    {
        Storage::fake(
            'local'
        );

        Storage::fake(
            'public'
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        $picture =
            UploadedFile::fake()
            ->image(
                'portrait.jpg',
                300,
                300
            )
            ->size(
                100
            );

        $response =
            $this
            ->withHeader(
                'Accept',
                'application/json'
            )
            ->post(
                $this->endpoint(
                    $center
                ),
                array_merge(
                    $this->payload(),
                    [
                        'personal_picture' =>
                        $picture,
                    ]
                )
            );

        $response->assertCreated();

        $registration =
            RegistrationRequest::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->firstOrFail();

        $this->assertNotNull(
            $registration
                ->personal_picture_path
        );

        $this->assertStringStartsWith(
            'registration-requests/'
                . $center->id
                . '/personal-pictures/',
            $registration
                ->personal_picture_path
        );

        Storage::disk(
            'local'
        )->assertExists(
            $registration
                ->personal_picture_path
        );

        Storage::disk(
            'public'
        )->assertMissing(
            $registration
                ->personal_picture_path
        );
    }

    public function test_second_pending_request_for_same_national_id_in_same_center_is_rejected(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload()
        )->assertCreated();

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload([
                    'email' =>
                    'another@example.test',
                ])
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A pending registration request already exists for this National ID in this language center.'
            );

        $this->assertSame(
            1,
            RegistrationRequest::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'national_id_number',
                    '123456789'
                )
                ->count()
        );
    }

    public function test_duplicate_failure_removes_newly_uploaded_private_picture(): void
    {
        Storage::fake(
            'local'
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload()
        )->assertCreated();

        $picture =
            UploadedFile::fake()
            ->image(
                'duplicate.jpg',
                300,
                300
            );

        $response =
            $this
            ->withHeader(
                'Accept',
                'application/json'
            )
            ->post(
                $this->endpoint(
                    $center
                ),
                array_merge(
                    $this->payload([
                        'email' =>
                        'duplicate@example.test',
                    ]),
                    [
                        'personal_picture' =>
                        $picture,
                    ]
                )
            );

        $response->assertUnprocessable();

        $this->assertSame(
            [],
            Storage::disk(
                'local'
            )->allFiles(
                'registration-requests/'
                    . $center->id
                    . '/personal-pictures'
            )
        );
    }

    public function test_same_national_id_may_submit_to_different_centers(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $this->postJson(
            $this->endpoint(
                $centerA
            ),
            $this->payload()
        )->assertCreated();

        $this->postJson(
            $this->endpoint(
                $centerB
            ),
            $this->payload()
        )->assertCreated();

        $this->assertSame(
            2,
            RegistrationRequest::withoutGlobalScopes()
                ->where(
                    'national_id_number',
                    '123456789'
                )
                ->count()
        );
    }

    public function test_completed_historical_request_does_not_block_new_pending_request(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        RegistrationRequest::factory()
            ->for(
                $center
            )
            ->create([
                'national_id_number' =>
                '123456789',

                'status' =>
                RegistrationRequestStatus::Rejected,

                'pending_marker' =>
                null,
            ]);

        $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload()
        )->assertCreated();

        $this->assertSame(
            2,
            RegistrationRequest::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'national_id_number',
                    '123456789'
                )
                ->count()
        );

        $this->assertSame(
            1,
            RegistrationRequest::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'national_id_number',
                    '123456789'
                )
                ->where(
                    'pending_marker',
                    1
                )
                ->count()
        );
    }

    public function test_suspended_center_rejects_public_registration(): void
    {
        $center =
            Center::factory()
            ->suspended()
            ->create();

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload()
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Registration is not available for this language center.'
            );

        $this->assertDatabaseCount(
            'registration_requests',
            0
        );
    }

    public function test_public_submission_cannot_override_administrative_fields_or_center(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $otherCenter =
            Center::factory()
            ->active()
            ->create();

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                array_merge(
                    $this->payload(),
                    [
                        /*
                         * These fields are intentionally not part
                         * of validated public input.
                         */
                        'center_id' =>
                        $otherCenter->id,

                        'status' =>
                        RegistrationRequestStatus::Approved->value,

                        'selected_role_id' =>
                        999999,

                        'selected_branch_id' =>
                        999999,

                        'reviewed_by_user_id' =>
                        999999,

                        'reviewed_at' =>
                        now()->toISOString(),

                        'rejection_reason' =>
                        'Injected value',

                        'pending_marker' =>
                        null,
                    ]
                )
            );

        $response->assertCreated();

        $registration =
            RegistrationRequest::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $center->id,
            $registration->center_id
        );

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $registration->status
        );

        $this->assertNull(
            $registration
                ->selected_role_id
        );

        $this->assertNull(
            $registration
                ->selected_branch_id
        );

        $this->assertNull(
            $registration
                ->reviewed_by_user_id
        );

        $this->assertNull(
            $registration
                ->reviewed_at
        );

        $this->assertNull(
            $registration
                ->rejection_reason
        );

        $this->assertSame(
            1,
            $registration
                ->pending_marker
        );
    }

    public function test_invalid_public_identity_payload_is_rejected(): void
    {
        Storage::fake(
            'local'
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        $notAnImage =
            UploadedFile::fake()
            ->create(
                'notes.txt',
                10,
                'text/plain'
            );

        $response =
            $this
            ->withHeader(
                'Accept',
                'application/json'
            )
            ->post(
                $this->endpoint(
                    $center
                ),
                array_merge(
                    $this->payload([
                        'date_of_birth' =>
                        now()
                            ->addDay()
                            ->format(
                                'Y-m-d'
                            ),

                        'phone_number' =>
                        '0599123456',
                    ]),
                    [
                        'personal_picture' =>
                        $notAnImage,
                    ]
                )
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'date_of_birth',
                'phone_number',
                'personal_picture',
            ]);

        $this->assertDatabaseCount(
            'registration_requests',
            0
        );

        $this->assertSame(
            [],
            Storage::disk(
                'local'
            )->allFiles()
        );
    }

    public function test_unknown_center_code_returns_not_found(): void
    {
        $this->postJson(
            '/api/v1/centers/UNKNOWN-CENTER/registration-requests',
            $this->payload()
        )->assertNotFound();

        $this->assertDatabaseCount(
            'registration_requests',
            0
        );
    }

    private function endpoint(
        Center $center
    ): string {
        return '/api/v1/centers/'
            . $center->code
            . '/registration-requests';
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(
        array $overrides = []
    ): array {
        return array_merge(
            [
                'national_id_number' =>
                '123456789',

                'full_name' =>
                'Ahmad Mohammed',

                'date_of_birth' =>
                '2001-05-15',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'ahmad@example.test',

                'phone_number' =>
                '+970599123456',
            ],
            $overrides
        );
    }
}
