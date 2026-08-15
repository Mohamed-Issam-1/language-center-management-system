<?php

namespace Tests\Feature\Identity;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_center_scoped_account_is_linked_to_a_person_center_and_one_role(): void
    {
        $center = Center::factory()->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacherRole = $this->role(SystemRole::Teacher);

        $user = User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $teacherRole->id,
        ]);

        $this->assertTrue($user->center->is($center));
        $this->assertTrue($user->person->is($person));
        $this->assertTrue($user->role->is($teacherRole));
    }

    public function test_a_platform_owner_account_can_be_platform_scoped(): void
    {
        $platformOwnerRole = $this->role(SystemRole::PlatformOwner);

        $user = User::factory()->create([
            'center_id' => null,
            'person_id' => null,
            'role_id' => $platformOwnerRole->id,
        ]);

        $this->assertNull($user->center_id);
        $this->assertNull($user->person_id);
        $this->assertSame(
            SystemRole::PlatformOwner->value,
            $user->role->code,
        );
    }

    public function test_account_login_identifier_must_be_unique_platform_wide(): void
    {
        User::factory()->create([
            'account_login_identifier' => 'unique.account',
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'account_login_identifier' => 'unique.account',
        ]);
    }

    public function test_multiple_accounts_may_share_the_same_recovery_email(): void
    {
        $center = Center::factory()->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(SystemRole::Teacher)->id,
            'recovery_email' => 'shared@example.test',
        ]);

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(SystemRole::Student)->id,
            'recovery_email' => 'shared@example.test',
        ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_same_person_cannot_have_two_accounts_for_the_same_role_in_the_same_center(): void
    {
        $center = Center::factory()->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $role = $this->role(SystemRole::Student);

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $role->id,
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_same_person_can_have_separate_accounts_for_different_roles(): void
    {
        $center = Center::factory()->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(SystemRole::Teacher)->id,
        ]);

        User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(SystemRole::Student)->id,
        ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_account_cannot_reference_a_person_from_another_center(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $personFromCenterB = Person::factory()
            ->for($centerB)
            ->create();

        $this->expectException(QueryException::class);

        User::factory()->create([
            'center_id' => $centerA->id,
            'person_id' => $personFromCenterB->id,
            'role_id' => $this->role(SystemRole::Teacher)->id,
        ]);
    }

    public function test_every_account_requires_exactly_one_role_reference(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create([
            'role_id' => null,
        ]);
    }

    public function test_account_status_is_cast_to_account_status_enum(): void
    {
        $user = User::factory()
            ->pending()
            ->create();

        $this->assertInstanceOf(
            AccountStatus::class,
            $user->status,
        );

        $this->assertSame(
            AccountStatus::Pending,
            $user->status,
        );
    }

    private function role(SystemRole $role): Role
    {
        return Role::query()
            ->where('code', $role->value)
            ->firstOrFail();
    }
}
