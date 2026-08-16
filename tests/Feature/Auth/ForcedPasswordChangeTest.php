<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_temporary_password_login_is_allowed_and_marks_it_as_used(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'account_login_identifier' => 'temporary.user',
            ]);

        $response = $this->post('/login', [
            'account_login_identifier' => 'temporary.user',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $response->assertRedirect(
            route('profile.edit', absolute: false)
        );

        $user->refresh();

        $this->assertTrue(
            $user->must_change_password
        );

        $this->assertNotNull(
            $user->temporary_password_used_at
        );
    }

    public function test_user_requiring_password_change_cannot_access_dashboard(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->get('/dashboard');

        $response->assertRedirect(
            route('profile.edit', absolute: false)
        );
    }

    public function test_user_requiring_password_change_can_access_profile_page_for_password_change(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_user_requiring_password_change_cannot_update_profile_information(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
                'name' => 'Original Name',
                'email' => 'original@example.test',
            ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Changed Name',
                'email' => 'changed@example.test',
            ]);

        $response->assertRedirect(
            route('profile.edit', absolute: false)
        );

        $user->refresh();

        $this->assertSame(
            'Original Name',
            $user->name
        );

        $this->assertSame(
            'original@example.test',
            $user->email
        );
    }

    public function test_user_requiring_password_change_cannot_delete_account(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertRedirect(
            route('profile.edit', absolute: false)
        );

        $this->assertNotNull(
            $user->fresh()
        );
    }

    public function test_user_requiring_password_change_can_logout(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->post('/logout');

        $this->assertGuest();

        $response->assertRedirect('/');
    }

    public function test_temporary_password_cannot_be_used_for_a_second_login(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'account_login_identifier' => 'single.use.user',
            ]);

        $this->post('/login', [
            'account_login_identifier' => 'single.use.user',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        $this->assertGuest();

        $response = $this->post('/login', [
            'account_login_identifier' => 'single.use.user',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();

        $user->refresh();

        $this->assertTrue(
            $user->must_change_password
        );

        $this->assertNotNull(
            $user->temporary_password_used_at
        );
    }

    public function test_forced_password_change_restores_normal_access(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'account_login_identifier' => 'change.required',
            ]);

        $this->post('/login', [
            'account_login_identifier' => 'change.required',
            'password' => 'password',
        ]);

        $response = $this->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect(
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

        $dashboardResponse = $this->get('/dashboard');

        $dashboardResponse->assertOk();
    }
}
