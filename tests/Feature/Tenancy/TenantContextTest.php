<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        /*
         * Test-only route that exposes only the resolved tenant
         * context. It is not part of the production application.
         */
        Route::middleware([
            'web',
            'auth',
            'tenant.context',
        ])->get(
            '/_test/tenant-context',
            function (
                Request $request,
                TenantContext $tenant
            ) {
                return response()->json([
                    'established' => $tenant->isEstablished(),

                    'scope' => $tenant->isPlatformScoped()
                        ? 'platform'
                        : 'center',

                    'center_id' => $tenant->centerId(),

                    /*
                     * Exposed only to prove that request input does
                     * not control the tenant context.
                     */
                    'requested_center_id' => $request->input(
                        'center_id'
                    ),
                ]);
            }
        );
    }

    public function test_platform_owner_receives_platform_scope_without_a_center(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->role(
                SystemRole::PlatformOwner
            )->id,
            'center_id' => null,
            'person_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response
            ->assertOk()
            ->assertJson([
                'established' => true,
                'scope' => 'platform',
                'center_id' => null,
            ]);
    }

    public function test_center_scoped_account_receives_its_own_center_context(): void
    {
        $user = $this->createCenterScopedUser(
            SystemRole::Teacher
        );

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response
            ->assertOk()
            ->assertJson([
                'established' => true,
                'scope' => 'center',
                'center_id' => $user->center_id,
            ]);
    }

    public function test_request_center_id_cannot_override_authenticated_account_center(): void
    {
        $user = $this->createCenterScopedUser(
            SystemRole::Student
        );

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $response = $this
            ->actingAs($user)
            ->getJson(
                '/_test/tenant-context?center_id='
                    . $otherCenter->id
            );

        $response
            ->assertOk()
            ->assertJson([
                'scope' => 'center',
                'center_id' => $user->center_id,
                'requested_center_id' => (string) $otherCenter->id,
            ]);

        $this->assertNotSame(
            $otherCenter->id,
            $response->json('center_id')
        );
    }

    public function test_center_scoped_account_without_center_is_rejected(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->role(
                SystemRole::Teacher
            )->id,
            'center_id' => null,
            'person_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response->assertForbidden();
    }

    public function test_center_scoped_account_without_person_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $user = User::factory()->create([
            'role_id' => $this->role(
                SystemRole::FinanceEmployee
            )->id,
            'center_id' => $center->id,
            'person_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response->assertForbidden();
    }

    public function test_suspended_center_cannot_continue_a_protected_request(): void
    {
        $user = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            CenterStatus::Suspended
        );

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response->assertForbidden();
    }

    public function test_platform_owner_with_center_scope_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $user = User::factory()->create([
            'role_id' => $this->role(
                SystemRole::PlatformOwner
            )->id,
            'center_id' => $center->id,
            'person_id' => $person->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response->assertForbidden();
    }

    public function test_tenant_context_is_reestablished_for_each_account_request(): void
    {
        $centerUser = $this->createCenterScopedUser(
            SystemRole::Teacher
        );

        $firstResponse = $this
            ->actingAs($centerUser)
            ->getJson('/_test/tenant-context');

        $firstResponse
            ->assertOk()
            ->assertJson([
                'scope' => 'center',
                'center_id' => $centerUser->center_id,
            ]);

        $platformOwner = User::factory()->create([
            'role_id' => $this->role(
                SystemRole::PlatformOwner
            )->id,
            'center_id' => null,
            'person_id' => null,
        ]);

        $secondResponse = $this
            ->actingAs($platformOwner)
            ->getJson('/_test/tenant-context');

        $secondResponse
            ->assertOk()
            ->assertJson([
                'scope' => 'platform',
                'center_id' => null,
            ]);
    }

    public function test_account_with_unknown_system_role_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $unknownRole = Role::query()->create([
            'code' => 'unknown_role',
            'name' => 'Unknown Role',
        ]);

        $user = User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $unknownRole->id,
            'status' => AccountStatus::Active,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/_test/tenant-context');

        $response->assertForbidden();
    }

    private function createCenterScopedUser(
        SystemRole $role,
        CenterStatus $centerStatus = CenterStatus::Active
    ): User {
        $center = Center::factory()->create([
            'status' => $centerStatus,
        ]);

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role($role)->id,
            'status' => AccountStatus::Active,
        ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where('code', $role->value)
            ->firstOrFail();
    }
}
