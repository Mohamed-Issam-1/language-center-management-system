<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionInactivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.lifetime' => 1440,
        ]);

        $this->app['session']->forgetDrivers();
    }

    public function test_session_inactivity_lifetime_is_twenty_four_hours(): void
    {
        $this->assertSame(
            1440,
            config('session.lifetime')
        );
    }

    public function test_database_session_remains_valid_before_twenty_four_hours_of_inactivity(): void
    {
        $handler = $this->databaseSessionHandler();

        $sessionId = Str::random(40);

        $handler->write(
            $sessionId,
            'active-session-payload'
        );

        DB::table('sessions')
            ->where('id', $sessionId)
            ->update([
                'last_activity' => now()
                    ->subMinutes(1439)
                    ->getTimestamp(),
            ]);

        $payload = $handler->read($sessionId);

        $this->assertSame(
            'active-session-payload',
            $payload
        );
    }

    public function test_database_session_expires_after_more_than_twenty_four_hours_of_inactivity(): void
    {
        $handler = $this->databaseSessionHandler();

        $sessionId = Str::random(40);

        $handler->write(
            $sessionId,
            'expired-session-payload'
        );

        DB::table('sessions')
            ->where('id', $sessionId)
            ->update([
                'last_activity' => now()
                    ->subMinutes(1441)
                    ->getTimestamp(),
            ]);

        $payload = $handler->read($sessionId);

        $this->assertSame(
            '',
            $payload
        );
    }

    public function test_session_activity_refreshes_last_activity_timestamp(): void
    {
        $handler = $this->databaseSessionHandler();

        $sessionId = Str::random(40);

        $handler->write(
            $sessionId,
            'initial-session-payload'
        );

        $oldTimestamp = now()
            ->subMinutes(60)
            ->getTimestamp();

        DB::table('sessions')
            ->where('id', $sessionId)
            ->update([
                'last_activity' => $oldTimestamp,
            ]);

        $handler->write(
            $sessionId,
            'refreshed-session-payload'
        );

        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->first();

        $this->assertNotNull($session);

        $this->assertGreaterThan(
            $oldTimestamp,
            $session->last_activity
        );

        $this->assertSame(
            'refreshed-session-payload',
            $handler->read($sessionId)
        );
    }

    private function databaseSessionHandler(): DatabaseSessionHandler
    {
        $store = $this->app['session']
            ->driver('database');

        $handler = $store->getHandler();

        $this->assertInstanceOf(
            DatabaseSessionHandler::class,
            $handler
        );

        return $handler;
    }
}
