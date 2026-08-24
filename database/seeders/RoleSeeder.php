<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemRole::cases() as $role) {
            Role::query()->updateOrCreate(
                [
                    'code' => $role->value,
                ],
                [
                    'name' => $role->label(),
                ],
            );
        }
    }
}
