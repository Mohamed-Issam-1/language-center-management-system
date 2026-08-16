<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertTrue(
            Hash::check(
                'new-password',
                $user->password
            )
        );

        $this->assertNotNull(
            $user->password_changed_at
        );
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertTrue(
            Hash::check(
                'password',
                $user->password
            )
        );

        $this->assertNull(
            $user->password_changed_at
        );
    }

    public function test_forced_password_change_clears_temporary_password_state(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(
                route('dashboard', absolute: false)
            );

        $user->refresh();

        $this->assertFalse(
            $user->must_change_password
        );

        $this->assertNull(
            $user->temporary_password_used_at
        );

        $this->assertNotNull(
            $user->password_changed_at
        );

        $this->assertTrue(
            Hash::check(
                'new-password',
                $user->password
            )
        );
    }

    public function test_forced_password_change_requires_a_different_password(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertTrue(
            $user->must_change_password
        );

        $this->assertNotNull(
            $user->temporary_password_used_at
        );

        $this->assertNull(
            $user->password_changed_at
        );

        $this->assertTrue(
            Hash::check(
                'password',
                $user->password
            )
        );
    }
}
