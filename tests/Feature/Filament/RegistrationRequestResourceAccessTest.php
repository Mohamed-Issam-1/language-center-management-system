<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RegistrationRequests\RegistrationRequestResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRequestResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_registration_requests_from_own_center(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $requestA1 =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create();

        $requestA2 =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,
            ]);

        $requestB =
            RegistrationRequest::factory()
            ->for($centerB)
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $visibleIds =
            RegistrationRequestResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                $requestA1->id,
                $requestA2->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            RegistrationRequestResource
                ::canViewAny()
        );

        $this->assertTrue(
            RegistrationRequestResource
                ::canView(
                    $requestA1
                )
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canView(
                    $requestB
                )
        );
    }

    public function test_branch_manager_sees_only_student_requests_already_assigned_to_own_branch(): void
    {
        $center =
            Center::factory()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->create();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $ownStudentRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,

                'selected_branch_id' =>
                $ownBranch->id,
            ]);

        $ownFinanceRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::FinanceEmployee
                )->id,

                'selected_branch_id' =>
                $ownBranch->id,
            ]);

        $otherBranchStudentRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,

                'selected_branch_id' =>
                $otherBranch->id,
            ]);

        $unclassifiedRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $visibleIds =
            RegistrationRequestResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $ownStudentRequest->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            RegistrationRequestResource
                ::canViewAny()
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canView(
                    $ownFinanceRequest
                )
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canView(
                    $otherBranchStudentRequest
                )
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canView(
                    $unclassifiedRequest
                )
        );

        $this->assertNull(
            RegistrationRequestResource
                ::resolveRecordRouteBinding(
                    $otherBranchStudentRequest
                        ->getKey()
                )
        );
    }

    public function test_platform_owner_and_finance_employee_cannot_access_public_registration_resource(): void
    {
        $platformOwner =
            User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

                'status' =>
                AccountStatus::Active,
            ]);

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            RegistrationRequestResource
                ::canViewAny()
        );

        $center =
            Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create();

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->actingAs(
            $finance
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );

        $this->assertFalse(
            RegistrationRequestResource
                ::canViewAny()
        );
    }

    public function test_registration_request_resource_is_read_only(): void
    {
        $center =
            Center::factory()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canCreate()
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canEdit(
                    $request
                )
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canDelete(
                    $request
                )
        );

        $this->assertFalse(
            RegistrationRequestResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                RegistrationRequestResource
                    ::getPages()
            )
        );
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchManagerContext(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );
    }

    private function assignBranchManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    $role
                )->id,

                'status' =>
                AccountStatus::Active,
            ]);
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
