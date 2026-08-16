<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Branches\BranchManagementService;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BranchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_only_center_owner_receives_branch_management_permission(): void
    {
        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertTrue(
            $centerOwner->hasPermission(
                SystemPermission::ManageBranches
            )
        );

        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user = $this->createUserForRole(
                $role
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageBranches
                )
            );
        }
    }

    public function test_center_owner_can_manage_branch_inside_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'update',
                    $branch
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'activate',
                    $branch
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'deactivate',
                    $branch
                )
        );
    }

    public function test_center_owner_cannot_manage_branch_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $branchB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'deactivate',
                    $branchB
                )
        );
    }

    public function test_branch_manager_cannot_manage_branch_itself(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $branch
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'deactivate',
                    $branch
                )
        );
    }

    public function test_platform_owner_does_not_implicitly_manage_center_branches(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $branch = Branch::factory()
            ->create();

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'update',
                    $branch
                )
        );
    }

    public function test_branch_creation_uses_tenant_center_and_ignores_supplied_center_id(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterContext(
            $centerA
        );

        $branch = app(
            BranchManagementService::class
        )->create(
            $ownerA,
            [
                'center_id' => $centerB->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'working_hours' => [
                    'monday' => [
                        'opens_at' => '08:00',
                        'closes_at' => '16:00',
                    ],
                ],
                'status' => BranchStatus::Active,
            ]
        );

        $this->assertSame(
            $centerA->id,
            $branch->center_id
        );

        $this->assertNotSame(
            $centerB->id,
            $branch->center_id
        );
    }

    public function test_general_branch_update_cannot_move_branch_or_bypass_status_lifecycle(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branch = Branch::factory()
            ->for($centerA)
            ->active()
            ->create([
                'name' => 'Old Name',
            ]);

        $this->establishCenterContext(
            $centerA
        );

        $updated = app(
            BranchManagementService::class
        )->update(
            $ownerA,
            $branch,
            [
                'name' => 'Updated Name',

                /*
                 * These must be ignored by the general update.
                 */
                'center_id' => $centerB->id,
                'status' => BranchStatus::Deactivated,

                'working_hours' => [
                    'sunday' => [
                        'opens_at' => '09:00',
                        'closes_at' => '15:00',
                    ],
                ],
            ]
        );

        $this->assertSame(
            'Updated Name',
            $updated->name
        );

        $this->assertSame(
            $centerA->id,
            $updated->center_id
        );

        $this->assertSame(
            BranchStatus::Active,
            $updated->status
        );

        $this->assertSame(
            [
                'sunday' => [
                    'opens_at' => '09:00',
                    'closes_at' => '15:00',
                ],
            ],
            $updated->working_hours
        );
    }

    public function test_center_owner_can_activate_and_deactivate_own_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $branch = Branch::factory()
            ->for($center)
            ->deactivated()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $service = app(
            BranchManagementService::class
        );

        $branch = $service->activate(
            $owner,
            $branch
        );

        $this->assertSame(
            BranchStatus::Active,
            $branch->status
        );

        $branch = $service->deactivate(
            $owner,
            $branch
        );

        $this->assertSame(
            BranchStatus::Deactivated,
            $branch->status
        );

        $this->assertDatabaseHas(
            'branches',
            [
                'id' => $branch->id,
                'status' => BranchStatus::Deactivated->value,
            ]
        );
    }

    public function test_service_rejects_cross_center_branch_management(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $this->establishCenterContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(
            BranchManagementService::class
        )->update(
            $ownerA,
            $branchB,
            [
                'name' => 'Unauthorized Update',
            ]
        );
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if ($role === SystemRole::PlatformOwner) {
            return User::factory()->create([
                'center_id' => null,
                'person_id' => null,
                'role_id' => $this->role(
                    $role
                )->id,
            ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(
                $role
            )->id,
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
