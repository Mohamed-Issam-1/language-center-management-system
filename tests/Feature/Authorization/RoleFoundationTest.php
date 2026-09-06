<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_six_fixed_system_roles_can_be_seeded(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 6);

        foreach (SystemRole::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'code' => $role->value,
                'name' => $role->label(),
            ]);
        }
    }

    public function test_running_the_role_seeder_multiple_times_does_not_create_duplicate_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 6);
    }

    public function test_role_code_must_be_unique(): void
    {
        Role::query()->create([
            'code' => SystemRole::Teacher->value,
            'name' => 'Teacher',
        ]);

        $this->expectException(QueryException::class);

        Role::query()->create([
            'code' => SystemRole::Teacher->value,
            'name' => 'Another Teacher Role',
        ]);
    }
}
