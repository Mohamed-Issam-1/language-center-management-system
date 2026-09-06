<?php

namespace Tests\Feature\Filament;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationRequestPersonalPictureAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );

        Storage::fake(
            'local'
        );
    }

    public function test_center_owner_can_view_picture_from_own_center(): void
    {
        $center =
            $this->center(
                '41'
            );

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $path =
            $this->storePicture(
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: $path
            );

        $response =
            $this
                ->actingAs(
                    $owner
                )
                ->get(
                    $this->pictureUrl(
                        $registration
                    )
                );

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'image/png'
            )
            ->assertHeader(
                'X-Content-Type-Options',
                'nosniff'
            )
            ->assertHeader(
                'Cross-Origin-Resource-Policy',
                'same-origin'
            );

        $this->assertStringContainsString(
            'no-store',
            (string)
            $response->headers->get(
                'Cache-Control'
            )
        );
    }

    public function test_center_owner_cannot_view_picture_from_another_center(): void
    {
        $ownCenter =
            $this->center(
                '42'
            );

        $otherCenter =
            $this->center(
                '43'
            );

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $ownCenter
            );

        $path =
            $this->storePicture(
                $otherCenter
            );

        $registration =
            $this->registrationRequest(
                center: $otherCenter,
                picturePath: $path
            );

        $this
            ->actingAs(
                $owner
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    public function test_branch_manager_can_view_student_picture_from_assigned_branch(): void
    {
        $center =
            $this->center(
                '44'
            );

        $branch =
            $this->branch(
                $center
            );

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $path =
            $this->storePicture(
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: $path,
                role: SystemRole::Student,
                branch: $branch
            );

        $this
            ->actingAs(
                $manager
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'image/png'
            );
    }

    public function test_branch_manager_cannot_view_student_picture_from_another_branch(): void
    {
        $center =
            $this->center(
                '45'
            );

        $ownBranch =
            $this->branch(
                $center
            );

        $otherBranch =
            $this->branch(
                $center
            );

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $path =
            $this->storePicture(
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: $path,
                role: SystemRole::Student,
                branch: $otherBranch
            );

        $this
            ->actingAs(
                $manager
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    public function test_branch_manager_cannot_view_unclassified_registration_picture(): void
    {
        $center =
            $this->center(
                '46'
            );

        $branch =
            $this->branch(
                $center
            );

        $manager =
            $this->centerUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $path =
            $this->storePicture(
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: $path
            );

        $this
            ->actingAs(
                $manager
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    public function test_registration_without_picture_returns_not_found(): void
    {
        $center =
            $this->center(
                '47'
            );

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: null
            );

        $this
            ->actingAs(
                $owner
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    public function test_invalid_persisted_picture_path_is_never_served(): void
    {
        $center =
            $this->center(
                '48'
            );

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: 'registration-requests/'
                    .$center->id
                    .'/personal-pictures/../secret.png'
            );

        $this
            ->actingAs(
                $owner
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    public function test_missing_private_picture_file_returns_not_found(): void
    {
        $center =
            $this->center(
                '49'
            );

        $owner =
            $this->centerUser(
                SystemRole::CenterOwner,
                $center
            );

        $registration =
            $this->registrationRequest(
                center: $center,
                picturePath: 'registration-requests/'
                    .$center->id
                    .'/personal-pictures/missing.png'
            );

        $this
            ->actingAs(
                $owner
            )
            ->get(
                $this->pictureUrl(
                    $registration
                )
            )
            ->assertNotFound();
    }

    private function center(
        string $identifierCode
    ): Center {
        return Center::factory()
            ->create([
                'identifier_code' => $identifierCode,

                'status' => CenterStatus::Active,
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->create([
                'center_id' => $center->id,

                'status' => BranchStatus::Active,
            ]);
    }

    private function centerUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
                ->for(
                    $center
                )
                ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,

                'person_id' => $person->id,

                'role_id' => $this->role(
                    $role
                )->id,

                'status' => AccountStatus::Active,

                'must_change_password' => false,
            ]);
    }

    private function assignBranchManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' => $branch->center_id,

                'user_id' => $manager->id,

                'branch_id' => $branch->id,

                'started_at' => now(),

                'ended_at' => null,

                'active_marker' => 1,
            ]);
    }

    private function registrationRequest(
        Center $center,
        ?string $picturePath,
        ?SystemRole $role = null,
        ?Branch $branch = null
    ): RegistrationRequest {
        return RegistrationRequest::factory()
            ->create([
                'center_id' => $center->id,

                'personal_picture_path' => $picturePath,

                'selected_role_id' => $role === null
                    ? null
                    : $this->role(
                        $role
                    )->id,

                'selected_branch_id' => $branch?->id,

                'status' => RegistrationRequestStatus::Pending,

                'pending_marker' => 1,
            ]);
    }

    private function storePicture(
        Center $center
    ): string {
        $path =
            'registration-requests/'
            .$center->id
            .'/personal-pictures/test.png';

        /*
         * Valid 1x1 PNG.
         *
         * A real image is required because the delivery
         * controller validates the actual MIME type.
         */
        $contents =
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true
            );

        $this->assertIsString(
            $contents
        );

        Storage::disk(
            'local'
        )->put(
            $path,
            $contents
        );

        return $path;
    }

    private function pictureUrl(
        RegistrationRequest $registration
    ): string {
        return route(
            'admin.registration-requests.personal-picture',
            [
                'registrationRequest' => $registration->id,
            ]
        );
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}
