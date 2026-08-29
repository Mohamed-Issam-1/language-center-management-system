<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Center;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationRequestSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Public self-registration always creates a Student account,
         * so the fixed system roles must exist for every test.
         */
        $this->seed(RoleSeeder::class);
    }

    public function test_person_can_submit_pending_registration_request_for_active_center(): void
    {
        $center = $this->createActiveCenter(
            '01',
            [
                'code' => 'REG-CENTER-01',
            ]
        );

        $branch = $this->createBranch($center);

        $response = $this->postJson(
            $this->endpoint($center),
            $this->payload([
                'branch_id' => $branch->id,
            ])
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

        $studentRole = $this->studentRole();

        $registration = RegistrationRequest::withoutGlobalScopes()
            ->where('center_id', $center->id)
            ->firstOrFail();

        $this->assertSame(
            $center->id,
            $registration->center_id
        );

        $this->assertSame(
            '123456789',
            $registration->national_id_number
        );

        $this->assertSame(
            'Ahmad Mohammed',
            $registration->full_name
        );

        $this->assertSame(
            '2001-05-15',
            $registration->date_of_birth->format('Y-m-d')
        );

        $this->assertSame(
            'Gaza',
            $registration->city_of_residence
        );

        $this->assertSame(
            'ahmad@example.test',
            $registration->email
        );

        $this->assertSame(
            '+970599123456',
            $registration->phone_number
        );

        $this->assertNull(
            $registration->personal_picture_path
        );

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $registration->status
        );

        /*
         * Self-registration is always Student according to the SRS.
         */
        $this->assertSame(
            $studentRole->id,
            $registration->selected_role_id
        );

        /*
         * The selected Branch is known during submission.
         */
        $this->assertSame(
            $branch->id,
            $registration->selected_branch_id
        );

        $this->assertNotNull(
            $registration->person_id
        );

        $this->assertNotNull(
            $registration->user_id
        );

        $this->assertNull(
            $registration->reviewed_by_user_id
        );

        $this->assertNull(
            $registration->reviewed_at
        );

        $this->assertNull(
            $registration->rejection_reason
        );

        $this->assertSame(
            1,
            $registration->pending_marker
        );

        /*
         * The SRS requires the Person to exist during
         * self-registration.
         */
        $this->assertDatabaseHas(
            'people',
            [
                'id' => $registration->person_id,
                'center_id' => $center->id,
                'national_id_number' => '123456789',
                'full_name' => 'Ahmad Mohammed',
                'date_of_birth' => '2001-05-15',
                'city_of_residence' => 'Gaza',
                'email' => 'ahmad@example.test',
                'phone_number' => '+970599123456',
            ]
        );

        /*
         * The SRS also requires a pending Student User account
         * to be created during self-registration.
         */
        $account = User::withoutGlobalScopes()
            ->findOrFail(
                $registration->user_id
            );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $registration->person_id,
            $account->person_id
        );

        $this->assertSame(
            $studentRole->id,
            $account->role_id
        );

        $this->assertSame(
            SystemRole::Student,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Pending,
            $account->status
        );

        $this->assertSame(
            'ahmad@example.test',
            $account->recovery_email
        );

        $this->assertNotNull(
            $account->account_login_identifier
        );

        $this->assertNotSame(
            '',
            trim(
                (string) $account->account_login_identifier
            )
        );

        /*
         * The password chosen in the UI must be the password
         * stored on the pending account.
         */
        $this->assertTrue(
            Hash::check(
                'StrongPass1',
                $account->password
            )
        );

        $this->assertFalse(
            (bool) $account->must_change_password
        );

        /*
         * Student operational state is created/reused when the
         * registration is approved, not during submission.
         */
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
        $center = $this->createActiveCenter(
            '02'
        );

        $branch = $this->createBranch(
            $center
        );

        $response = $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload([
                'branch_id' => $branch->id,

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

                'selected_branch_id' =>
                $branch->id,
            ]
        );

        $this->assertDatabaseHas(
            'people',
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

        $this->assertDatabaseHas(
            'users',
            [
                'center_id' =>
                $center->id,

                'role_id' =>
                $this->studentRole()->id,

                'recovery_email' =>
                'ahmad@example.test',

                'status' =>
                AccountStatus::Pending->value,
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

        $center = $this->createActiveCenter(
            '03'
        );

        $branch = $this->createBranch(
            $center
        );

        $picture = UploadedFile::fake()
            ->image(
                'portrait.jpg',
                300,
                300
            )
            ->size(
                100
            );

        $response = $this
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
                        'branch_id' =>
                        $branch->id,
                    ]),
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

        $this->assertTrue(
            Storage::disk(
                'local'
            )->exists(
                $registration
                    ->personal_picture_path
            )
        );

        $this->assertFalse(
            Storage::disk(
                'public'
            )->exists(
                $registration
                    ->personal_picture_path
            )
        );
        /*
         * The created Person uses the same private
         * picture path.
         */
        $this->assertDatabaseHas(
            'people',
            [
                'id' =>
                $registration->person_id,

                'personal_picture_path' =>
                $registration
                    ->personal_picture_path,
            ]
        );
    }

    public function test_second_pending_request_for_same_national_id_in_same_center_is_rejected(): void
    {
        $center = $this->createActiveCenter(
            '04'
        );

        $branch = $this->createBranch(
            $center
        );

        $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload([
                'branch_id' =>
                $branch->id,
            ])
        )->assertCreated();

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload([
                    'branch_id' =>
                    $branch->id,

                    'email' =>
                    'another@example.test',
                ])
            );

        $response
            ->assertUnprocessable();

        /*
         * Whether the duplicate is detected by the pending
         * request invariant or by the already-created Student
         * User invariant, no duplicate registration state
         * may be persisted.
         */
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

        $this->assertSame(
            1,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'role_id',
                    $this->studentRole()->id
                )
                ->count()
        );

        $this->assertDatabaseCount(
            'people',
            1
        );
    }

    public function test_duplicate_failure_removes_newly_uploaded_private_picture(): void
    {
        Storage::fake(
            'local'
        );

        $center = $this->createActiveCenter(
            '05'
        );

        $branch = $this->createBranch(
            $center
        );

        $this->postJson(
            $this->endpoint(
                $center
            ),
            $this->payload([
                'branch_id' =>
                $branch->id,
            ])
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
                        'branch_id' =>
                        $branch->id,

                        'email' =>
                        'duplicate@example.test',
                    ]),
                    [
                        'personal_picture' =>
                        $picture,
                    ]
                )
            );

        $response
            ->assertUnprocessable();

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

        $this->assertSame(
            1,
            RegistrationRequest::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertDatabaseCount(
            'people',
            1
        );

        $this->assertDatabaseCount(
            'users',
            1
        );
    }

    public function test_same_national_id_may_submit_to_different_centers(): void
    {
        $centerA = $this->createActiveCenter(
            '06'
        );

        $centerB = $this->createActiveCenter(
            '07'
        );

        $branchA = $this->createBranch(
            $centerA
        );

        $branchB = $this->createBranch(
            $centerB
        );

        $this->postJson(
            $this->endpoint(
                $centerA
            ),
            $this->payload([
                'branch_id' =>
                $branchA->id,
            ])
        )->assertCreated();

        $this->postJson(
            $this->endpoint(
                $centerB
            ),
            $this->payload([
                'branch_id' =>
                $branchB->id,
            ])
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

        /*
         * Person identity is center-scoped.
         */
        $this->assertDatabaseCount(
            'people',
            2
        );

        $this->assertDatabaseCount(
            'users',
            2
        );

        $this->assertDatabaseHas(
            'people',
            [
                'center_id' =>
                $centerA->id,

                'national_id_number' =>
                '123456789',
            ]
        );

        $this->assertDatabaseHas(
            'people',
            [
                'center_id' =>
                $centerB->id,

                'national_id_number' =>
                '123456789',
            ]
        );
    }

    public function test_completed_historical_request_does_not_block_new_pending_request(): void
    {
        $center = $this->createActiveCenter(
            '08'
        );

        $branch = $this->createBranch(
            $center
        );

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
            $this->payload([
                'branch_id' =>
                $branch->id,
            ])
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

        $this->assertDatabaseCount(
            'people',
            1
        );

        $this->assertDatabaseCount(
            'users',
            1
        );
    }

    public function test_suspended_center_rejects_public_registration(): void
    {
        $center = $this->createSuspendedCenter(
            '09'
        );

        /*
         * branch_id must still pass request validation so the
         * service itself can enforce the suspended Center rule.
         */
        $branch = $this->createBranch(
            $center
        );

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                $this->payload([
                    'branch_id' =>
                    $branch->id,
                ])
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

        $this->assertDatabaseCount(
            'people',
            0
        );

        $this->assertDatabaseCount(
            'users',
            0
        );
    }

    public function test_public_submission_cannot_override_administrative_fields_or_center(): void
    {
        $center = $this->createActiveCenter(
            '10'
        );

        $otherCenter = $this->createActiveCenter(
            '11'
        );

        $branch = $this->createBranch(
            $center
        );

        $response =
            $this->postJson(
                $this->endpoint(
                    $center
                ),
                array_merge(
                    $this->payload([
                        'branch_id' =>
                        $branch->id,
                    ]),
                    [
                        /*
                         * These fields are intentionally not part
                         * of trusted public input.
                         */
                        'center_id' =>
                        $otherCenter->id,

                        'status' =>
                        RegistrationRequestStatus::Approved->value,

                        'selected_role_id' =>
                        999999,

                        'selected_branch_id' =>
                        999999,

                        'person_id' =>
                        999999,

                        'user_id' =>
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

        /*
         * The public applicant cannot choose an arbitrary role.
         * Self-registration always receives Student.
         */
        $this->assertSame(
            $this->studentRole()->id,
            $registration
                ->selected_role_id
        );

        /*
         * branch_id is the valid public registration context.
         * Injected selected_branch_id must be ignored.
         */
        $this->assertSame(
            $branch->id,
            $registration
                ->selected_branch_id
        );

        $this->assertNotNull(
            $registration->person_id
        );

        $this->assertNotNull(
            $registration->user_id
        );

        $this->assertNotSame(
            999999,
            $registration->person_id
        );

        $this->assertNotSame(
            999999,
            $registration->user_id
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

        $this->assertDatabaseHas(
            'users',
            [
                'id' =>
                $registration->user_id,

                'center_id' =>
                $center->id,

                'role_id' =>
                $this->studentRole()->id,

                'status' =>
                AccountStatus::Pending->value,
            ]
        );
    }

    public function test_invalid_public_identity_payload_is_rejected(): void
    {
        Storage::fake(
            'local'
        );

        $center = $this->createActiveCenter(
            '12'
        );

        $branch = $this->createBranch(
            $center
        );

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
                        'branch_id' =>
                        $branch->id,

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

        $this->assertDatabaseCount(
            'people',
            0
        );

        $this->assertDatabaseCount(
            'users',
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
        /*
         * Route-model binding fails before the public request
         * validation/service workflow executes.
         */
        $this->postJson(
            '/api/v1/centers/UNKNOWN-CENTER/registration-requests',
            $this->payload()
        )->assertNotFound();

        $this->assertDatabaseCount(
            'registration_requests',
            0
        );

        $this->assertDatabaseCount(
            'people',
            0
        );

        $this->assertDatabaseCount(
            'users',
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

    private function createActiveCenter(
        string $identifierCode,
        array $attributes = []
    ): Center {
        return Center::factory()
            ->active()
            ->create(
                array_merge(
                    [
                        'identifier_code' =>
                            $identifierCode,
                    ],
                    $attributes
                )
            );
    }

    private function createSuspendedCenter(
        string $identifierCode
    ): Center {
        return Center::factory()
            ->suspended()
            ->create([
                'identifier_code' =>
                    $identifierCode,
            ]);
    }

    private function createBranch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->for(
                $center
            )
            ->create([
                'status' =>
                BranchStatus::Active->value,
            ]);
    }

    private function studentRole(): Role
    {
        return Role::query()
            ->where(
                'code',
                SystemRole::Student->value
            )
            ->firstOrFail();
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

                /*
                 * branch_id is added by each success test because
                 * it must belong to that test's Center.
                 */

                'password' =>
                'StrongPass1',

                'password_confirmation' =>
                'StrongPass1',

                'terms' =>
                true,
            ],
            $overrides
        );
    }
}
