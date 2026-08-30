<?php

namespace Tests\Feature\Auth;

use App\Models\Center;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_screen_is_available_and_lists_only_active_centers(): void
    {
        Center::factory()
            ->active()
            ->create([
                'code' =>
                'REG-ACTIVE',

                'name' =>
                'Active Language Center',
            ]);

        Center::factory()
            ->suspended()
            ->create([
                'code' =>
                'REG-SUSPENDED',

                'name' =>
                'Suspended Language Center',
            ]);

        $response =
            $this->get(
                '/register'
            );

        $response
            ->assertOk()
            ->assertInertia(
                fn(
                    Assert $page
                ): Assert =>
                $page
                    ->component(
                        'Auth/Register'
                    )
                    ->has(
                        'centers',
                        1
                    )
                    ->where(
                        'centers.0.code',
                        'REG-ACTIVE'
                    )
                    ->where(
                        'centers.0.name',
                        'Active Language Center'
                    )
            );

        $this->assertGuest();
    }

    public function test_generic_registration_submission_is_not_available(): void
    {
        $response =
            $this->post(
                '/register',
                [
                    'name' =>
                    'Test User',

                    'email' =>
                    'test@example.com',

                    'password' =>
                    'password',

                    'password_confirmation' =>
                    'password',
                ]
            );

        /*
         * GET /register exists only to display the
         * Registration Request page.
         *
         * There is deliberately no POST /register route.
         */
        $response->assertMethodNotAllowed();

        $this->assertGuest();
    }
}
