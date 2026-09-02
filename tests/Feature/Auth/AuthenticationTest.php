<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_account_login_identifier(): void
    {
        $user = User::factory()->create([
            'account_login_identifier' => 'platform.owner',
        ]);

        $response = $this->post('/login', [
            'account_login_identifier' => 'platform.owner',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $response->assertRedirect(
            route('login.success', absolute: false)
        );

        $successResponse =
            $this->get('/login/success');

        $successResponse
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert =>
                $page
                    ->component('Auth/Login')
                    ->where(
                        'showSuccessInitially',
                        true
                    )
                    ->where(
                        'successRedirectTo',
                        route(
                            'dashboard',
                            absolute: false
                        )
                    )
                    ->where(
                        'canResetPassword',
                        true
                    )
            );
    }

    public function test_successful_login_preserves_intended_destination(): void
    {
        $user = User::factory()->create([
            'account_login_identifier' =>
            'intended.user',
        ]);

        $response = $this
            ->withSession([
                'url.intended' => '/profile',
            ])
            ->post('/login', [
                'account_login_identifier' =>
                'intended.user',
                'password' => 'password',
            ]);

        $this->assertAuthenticatedAs($user);

        $response->assertRedirect(
            route('login.success', absolute: false)
        );

        $successResponse =
            $this->get('/login/success');

        $successResponse
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert =>
                $page
                    ->component('Auth/Login')
                    ->where(
                        'showSuccessInitially',
                        true
                    )
                    ->where(
                        'successRedirectTo',
                        '/profile'
                    )
            );
    }

    public function test_guest_cannot_access_login_success_screen(): void
    {
        $response = $this->get('/login/success');

        $response->assertRedirect(
            route('login', absolute: false)
        );

        $this->assertGuest();
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/logout');

        $this->assertGuest();

        $response->assertRedirect('/');
    }
}
