<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_self_service_forgot_password_screen_is_not_available(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertNotFound();
    }

    public function test_self_service_forgot_password_submission_is_not_available(): void
    {
        $response = $this->post('/forgot-password', [
            'email' => 'user@example.test',
        ]);

        $response->assertNotFound();
    }

    public function test_self_service_reset_password_screen_is_not_available(): void
    {
        $response = $this->get(
            '/reset-password/example-token'
        );

        $response->assertNotFound();
    }

    public function test_self_service_reset_password_submission_is_not_available(): void
    {
        $response = $this->post('/reset-password', [
            'token' => 'example-token',
            'email' => 'user@example.test',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNotFound();
    }
}
