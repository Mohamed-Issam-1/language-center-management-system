<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class CenterScopedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware([
            'web',
            'auth',
            'tenant.context',
        ])->group(function (): void {
            Route::get(
                '/_test/tenant-people',
                function () {
                    return response()->json([
                        'ids' => Person::query()
                            ->forCurrentTenant()
                            ->orderBy('id')
                            ->pluck('id')
                            ->all(),
                    ]);
                }
            );

            Route::get(
                '/_test/tenant-people/{personId}',
                function (string $personId) {
                    $person = Person::query()
                        ->forCurrentTenant()
                        ->findOrFail($personId);

                    return response()->json([
                        'id' => $person->id,
                        'center_id' => $person->center_id,
                        'national_id_number' => $person->national_id_number,
                    ]);
                }
            );

            Route::patch(
                '/_test/tenant-people/{personId}',
                function (
                    Request $request,
                    string $personId
                ) {
                    $person = Person::query()
                        ->forCurrentTenant()
                        ->findOrFail($personId);

                    $person->update([
                        'national_id_number' => $request->string(
                            'national_id_number'
                        )->toString(),
                    ]);

                    return response()->json([
                        'id' => $person->id,
                        'center_id' => $person->center_id,
                        'national_id_number' => $person->national_id_number,
                    ]);
                }
            );

            Route::get(
                '/_test/tenant-users',
                function () {
                    return response()->json([
                        'ids' => User::query()
                            ->forCurrentTenant()
                            ->orderBy('id')
                            ->pluck('id')
                            ->all(),
                    ]);
                }
            );
        });
    }

    public function test_center_scoped_person_listing_returns_only_current_center_records(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $userA = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $secondPersonA = Person::factory()
            ->for($centerA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $response = $this
            ->actingAs($userA)
            ->getJson('/_test/tenant-people');

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertContains(
            $userA->person_id,
            $ids
        );

        $this->assertContains(
            $secondPersonA->id,
            $ids
        );

        $this->assertNotContains(
            $personB->id,
            $ids
        );

        $this->assertCount(
            2,
            $ids
        );
    }

    public function test_center_scoped_account_can_directly_read_own_center_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $user = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $response = $this
            ->actingAs($user)
            ->getJson(
                '/_test/tenant-people/'
                    . $person->id
            );

        $response
            ->assertOk()
            ->assertJson([
                'id' => $person->id,
                'center_id' => $center->id,
            ]);
    }

    public function test_direct_read_of_another_center_record_returns_not_found(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $userA = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $response = $this
            ->actingAs($userA)
            ->getJson(
                '/_test/tenant-people/'
                    . $personB->id
            );

        $response->assertNotFound();
    }

    public function test_center_scoped_account_can_update_own_center_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $user = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create([
                'national_id_number' => '111111111',
            ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(
                '/_test/tenant-people/'
                    . $person->id,
                [
                    'national_id_number' => '222222222',
                ]
            );

        $response
            ->assertOk()
            ->assertJson([
                'id' => $person->id,
                'center_id' => $center->id,
                'national_id_number' => '222222222',
            ]);

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $person->id,
                'center_id' => $center->id,
                'national_id_number' => '222222222',
            ]
        );
    }

    public function test_direct_update_of_another_center_record_is_blocked_and_record_is_unchanged(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $userA = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create([
                'national_id_number' => '333333333',
            ]);

        $response = $this
            ->actingAs($userA)
            ->patchJson(
                '/_test/tenant-people/'
                    . $personB->id,
                [
                    'national_id_number' => '444444444',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $personB->id,
                'center_id' => $centerB->id,
                'national_id_number' => '333333333',
            ]
        );

        $this->assertDatabaseMissing(
            'people',
            [
                'id' => $personB->id,
                'national_id_number' => '444444444',
            ]
        );
    }

    public function test_request_center_id_cannot_change_center_scoped_query(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $userA = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $response = $this
            ->actingAs($userA)
            ->getJson(
                '/_test/tenant-people?center_id='
                    . $centerB->id
            );

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertContains(
            $userA->person_id,
            $ids
        );

        $this->assertNotContains(
            $personB->id,
            $ids
        );
    }

    public function test_center_scoped_user_query_excludes_other_centers_and_platform_accounts(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createCenterScopedUser(
            SystemRole::CenterOwner,
            $centerA
        );

        $teacherA = $this->createCenterScopedUser(
            SystemRole::Teacher,
            $centerA
        );

        $centerB = Center::factory()
            ->active()
            ->create();

        $teacherB = $this->createCenterScopedUser(
            SystemRole::Teacher,
            $centerB
        );

        $platformOwner = User::factory()
            ->create();

        $response = $this
            ->actingAs($ownerA)
            ->getJson('/_test/tenant-users');

        $response->assertOk();

        $ids = $response->json('ids');

        $this->assertContains(
            $ownerA->id,
            $ids
        );

        $this->assertContains(
            $teacherA->id,
            $ids
        );

        $this->assertNotContains(
            $teacherB->id,
            $ids
        );

        $this->assertNotContains(
            $platformOwner->id,
            $ids
        );
    }

    public function test_platform_scoped_account_cannot_use_center_operational_scope_implicitly(): void
    {
        $platformOwner = User::factory()
            ->create();

        Center::factory()
            ->active()
            ->has(
                Person::factory(),
                'people'
            )
            ->create();

        $response = $this
            ->actingAs($platformOwner)
            ->getJson('/_test/tenant-people');

        $response->assertForbidden();
    }

    public function test_center_scoped_query_fails_safely_without_established_tenant_context(): void
    {
        app(TenantContext::class)
            ->clear();

        $this->expectException(
            LogicException::class
        );

        Person::query()
            ->forCurrentTenant()
            ->get();
    }

    private function createCenterScopedUser(
        SystemRole $role,
        Center $center
    ): User {
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
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}
