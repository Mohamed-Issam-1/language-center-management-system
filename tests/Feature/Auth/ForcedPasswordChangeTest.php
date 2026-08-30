<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
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
                            'profile.edit',
                            absolute: false
                        )
                    )
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

    public function test_forced_password_change_requires_reauthentication_with_new_password(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'account_login_identifier' =>
                'change.required',
            ]);

        /*
     * First login uses the temporary credential.
     */
        $loginResponse = $this->post(
            '/login',
            [
                'account_login_identifier' =>
                'change.required',

                'password' =>
                'password',
            ]
        );

        $this->assertAuthenticatedAs(
            $user
        );

        $loginResponse->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
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
                        'successRedirectTo',
                        route(
                            'profile.edit',
                            absolute: false
                        )
                    )
            );

        /*
     * Establish a permanent password.
     */
        $passwordResponse = $this->put(
            '/password',
            [
                'current_password' =>
                'password',

                'password' =>
                'new-password',

                'password_confirmation' =>
                'new-password',
            ]
        );

        $passwordResponse->assertRedirect(
            route(
                'login',
                absolute: false
            )
        );

        /*
     * The session authenticated using the temporary
     * credential must be terminated.
     */
        $this->assertGuest();

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

        /*
     * The old temporary password no longer works.
     */
        $oldPasswordResponse = $this->post(
            '/login',
            [
                'account_login_identifier' =>
                'change.required',

                'password' =>
                'password',
            ]
        );

        $oldPasswordResponse
            ->assertSessionHasErrors(
                'account_login_identifier'
            );

        $this->assertGuest();

        /*
     * The new permanent password starts a fresh
     * authenticated session.
     */
        $newPasswordResponse = $this->post(
            '/login',
            [
                'account_login_identifier' =>
                'change.required',

                'password' =>
                'new-password',
            ]
        );

        $this->assertAuthenticatedAs(
            $user
        );

        $newPasswordResponse->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
        );

        $newSuccessResponse =
            $this->get('/login/success');

        $newSuccessResponse
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert =>
                $page
                    ->component('Auth/Login')
                    ->where(
                        'successRedirectTo',
                        route(
                            'dashboard',
                            absolute: false
                        )
                    )
            );

        $dashboardResponse =
            $this->get('/dashboard');

        $dashboardResponse->assertOk();
    }

    public function test_login_success_cannot_bypass_forced_password_change_destination(): void
    {
        $user = User::factory()
            ->requiresPasswordChange()
            ->create([
                'temporary_password_used_at' => now(),
            ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'post_login_redirect' => '/dashboard',
            ])
            ->get('/login/success');

        $response
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
                            'profile.edit',
                            absolute: false
                        )
                    )
            );

        $this->assertNull(
            session('post_login_redirect')
        );
    }
}
