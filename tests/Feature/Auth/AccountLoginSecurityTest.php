<?php

namespace Tests\Feature\Auth;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_email_is_not_accepted_as_the_login_identifier(): void
    {
        $user = User::factory()->create([
            'account_login_identifier' => 'platform.owner',
            'email' => 'owner@example.test',
        ]);

        $response = $this->post('/login', [
            'account_login_identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();
    }

    public function test_failed_password_increments_failed_login_attempts(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'wrong-password',
        ]);

        $user->refresh();

        $this->assertSame(
            1,
            $user->failed_login_attempts
        );

        $this->assertNull($user->locked_until);
        $this->assertGuest();
    }

    public function test_fifth_consecutive_failed_attempt_locks_the_account_for_fifteen_minutes(): void
    {
        $user = User::factory()->create();

        $now = now()->startOfSecond();

        $this->travelTo($now);

        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->post('/login', [
                    'account_login_identifier' => $user->account_login_identifier,
                    'password' => 'wrong-password',
                ]);
            }

            $user->refresh();

            $this->assertSame(
                5,
                $user->failed_login_attempts
            );

            $this->assertNotNull($user->locked_until);

            $this->assertTrue(
                $user->locked_until->equalTo(
                    $now->copy()->addMinutes(15)
                )
            );

            $this->assertGuest();
        } finally {
            $this->travelBack();
        }
    }

    public function test_locked_account_cannot_login_even_with_the_correct_password(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $response = $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();

        $this->assertNull(
            $user->fresh()->last_login_at
        );
    }

    public function test_login_is_allowed_after_the_lock_period_expires(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->subSecond(),
        ])->save();

        $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $user->refresh();

        $this->assertSame(
            0,
            $user->failed_login_attempts
        );

        $this->assertNull($user->locked_until);

        $this->assertNotNull($user->last_login_at);
    }

    public function test_successful_login_resets_previous_failed_attempts_and_updates_last_login(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'failed_login_attempts' => 3,
        ])->save();

        $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $user->refresh();

        $this->assertSame(
            0,
            $user->failed_login_attempts
        );

        $this->assertNull($user->locked_until);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_pending_account_cannot_authenticate(): void
    {
        $user = User::factory()
            ->pending()
            ->create();

        $response = $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_authenticate(): void
    {
        $user = User::factory()
            ->deactivated()
            ->create();

        $response = $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();
    }

    public function test_center_scoped_account_cannot_authenticate_when_center_is_suspended(): void
    {
        $user = $this->createCenterScopedUser(
            CenterStatus::Suspended
        );

        $response = $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(
            'account_login_identifier'
        );

        $this->assertGuest();
    }

    public function test_active_center_scoped_account_can_authenticate(): void
    {
        $user = $this->createCenterScopedUser(
            CenterStatus::Active
        );

        $this->post('/login', [
            'account_login_identifier' => $user->account_login_identifier,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    private function createCenterScopedUser(
        CenterStatus $centerStatus
    ): User {
        $center = Center::factory()->create([
            'status' => $centerStatus,
        ]);

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()->create([
            'center_id' => $center->id,
            'person_id' => $person->id,
            'role_id' => $this->role(
                SystemRole::Teacher
            )->id,
            'status' => AccountStatus::Active,
        ]);
    }

    private function role(SystemRole $role): Role
    {
        return Role::query()
            ->where('code', $role->value)
            ->firstOrFail();
    }
}
