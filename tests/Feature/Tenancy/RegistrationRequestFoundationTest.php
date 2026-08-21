<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\RegistrationRequest;
use App\Models\Branch;
use App\Support\Enums\RegistrationRequestStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRequestFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_registration_request_stores_submitted_identity_information(): void
    {
        $center = Center::factory()
            ->create();

        $request = RegistrationRequest::factory()
            ->for($center)
            ->create([
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
                'registration/photos/example.jpg',
            ]);

        $this->assertTrue(
            $request->center->is(
                $center
            )
        );

        $this->assertDatabaseHas(
            'registration_requests',
            [
                'id' => $request->id,

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

                'status' =>
                RegistrationRequestStatus::Pending->value,

                'pending_marker' =>
                1,
            ]
        );

        $this->assertSame(
            '2001-05-15',
            $request
                ->date_of_birth
                ->format('Y-m-d')
        );
    }

    public function test_new_registration_request_defaults_to_pending_without_role_or_reviewer(): void
    {
        $request =
            RegistrationRequest::factory()
            ->create();

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $request->status
        );

        $this->assertTrue(
            $request->isPending()
        );

        $this->assertSame(
            1,
            $request->pending_marker
        );

        $this->assertNull(
            $request->selected_role_id
        );

        $this->assertNull(
            $request->reviewed_by_user_id
        );

        $this->assertNull(
            $request->reviewed_at
        );

        $this->assertNull(
            $request->rejection_reason
        );
    }

    public function test_same_person_cannot_have_two_pending_requests_in_same_center(): void
    {
        $center = Center::factory()
            ->create();

        RegistrationRequest::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '111222333',
            ]);

        $this->expectException(
            QueryException::class
        );

        RegistrationRequest::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '111222333',
            ]);
    }

    public function test_registration_request_can_select_branch_from_same_center(): void
    {
        $center = Center::factory()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $request = RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_branch_id' =>
                $branch->id,
            ]);

        $this->assertTrue(
            $request
                ->selectedBranch
                ->is($branch)
        );

        $this->assertSame(
            $center->id,
            $request
                ->selectedBranch
                ->center_id
        );
    }

    public function test_registration_request_cannot_select_branch_from_another_center(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        RegistrationRequest::factory()
            ->for($centerA)
            ->create([
                'selected_branch_id' =>
                $branchB->id,
            ]);
    }

    public function test_registration_request_may_have_no_selected_branch_while_pending_review(): void
    {
        $request =
            RegistrationRequest::factory()
            ->create();

        $this->assertNull(
            $request->selected_branch_id
        );

        $this->assertNull(
            $request->selectedBranch
        );
    }

    public function test_branch_exposes_registration_requests_that_selected_it(): void
    {
        $center = Center::factory()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->create();

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_branch_id' =>
                $branchA->id,
            ]);

        RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_branch_id' =>
                $branchB->id,
            ]);

        $this->assertCount(
            1,
            $branchA
                ->selectedRegistrationRequests
        );

        $this->assertTrue(
            $branchA
                ->selectedRegistrationRequests
                ->first()
                ->is($request)
        );
    }

    public function test_completed_request_history_does_not_block_a_new_pending_request(): void
    {
        $center = Center::factory()
            ->create();

        $oldRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '222333444',
            ]);

        $oldRequest->forceFill([
            'status' =>
            RegistrationRequestStatus::Rejected,

            'pending_marker' =>
            null,

            'reviewed_at' =>
            now(),

            'rejection_reason' =>
            'Information requires correction.',
        ])->save();

        $newRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '222333444',
            ]);

        $this->assertNotSame(
            $oldRequest->id,
            $newRequest->id
        );

        $this->assertSame(
            RegistrationRequestStatus::Pending,
            $newRequest->status
        );

        $this->assertSame(
            2,
            RegistrationRequest::query()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'national_id_number',
                    '222333444'
                )
                ->count()
        );
    }

    public function test_same_national_id_can_have_pending_requests_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $requestA =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create([
                'national_id_number' =>
                '333444555',
            ]);

        $requestB =
            RegistrationRequest::factory()
            ->for($centerB)
            ->create([
                'national_id_number' =>
                '333444555',
            ]);

        $this->assertNotSame(
            $requestA->id,
            $requestB->id
        );

        $this->assertDatabaseCount(
            'registration_requests',
            2
        );
    }

    public function test_center_exposes_its_registration_requests(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $requestA =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create();

        RegistrationRequest::factory()
            ->for($centerB)
            ->create();

        $this->assertCount(
            1,
            $centerA
                ->registrationRequests
        );

        $this->assertTrue(
            $centerA
                ->registrationRequests
                ->first()
                ->is($requestA)
        );
    }

    public function test_registration_request_can_be_scoped_to_current_center(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $requestA =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create();

        RegistrationRequest::factory()
            ->for($centerB)
            ->create();

        app(
            \App\Support\Tenancy\TenantContext::class
        )->establishCenterScope(
            $centerA
        );

        $requests =
            RegistrationRequest::query()
            ->forCurrentTenant()
            ->get();

        $this->assertCount(
            1,
            $requests
        );

        $this->assertTrue(
            $requests
                ->first()
                ->is($requestA)
        );
    }
}
