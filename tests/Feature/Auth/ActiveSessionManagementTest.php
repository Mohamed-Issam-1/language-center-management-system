<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\ActiveSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveSessionManagementTest extends TestCase
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

        /*
     * Laravel JSON test requests do not include cookies by default.
     * Active-session endpoints require the real authenticated
     * session cookie so the current session can be identified.
     */
        $this->withCredentials();
    }

    public function test_user_can_view_only_active_sessions_for_current_account(): void
    {
        $user = $this->loginUser(
            'session.owner'
        );

        $otherUser = User::factory()->create();

        $this->createSession(
            $user,
            now()->subMinutes(30)->getTimestamp(),
            '10.0.0.2',
            'Second Browser'
        );

        $this->createSession(
            $user,
            now()->subMinutes(1441)->getTimestamp(),
            '10.0.0.3',
            'Expired Browser'
        );

        $this->createSession(
            $otherUser,
            now()->getTimestamp(),
            '10.0.0.4',
            'Other Account Browser'
        );

        $response = $this->getJson(
            route(
                'account.sessions.index',
                absolute: false
            )
        );

        $response->assertOk();

        $sessions = $response->json('sessions');

        /*
         * Current authenticated session + the second active
         * session. Expired and other-account sessions are absent.
         */
        $this->assertCount(
            2,
            $sessions
        );

        $this->assertCount(
            1,
            collect($sessions)
                ->where('is_current', true)
        );

        $this->assertFalse(
            collect($sessions)
                ->contains(
                    'ip_address',
                    '10.0.0.3'
                )
        );

        $this->assertFalse(
            collect($sessions)
                ->contains(
                    'ip_address',
                    '10.0.0.4'
                )
        );
    }

    public function test_user_can_terminate_another_active_session_without_terminating_current_session(): void
    {
        $user = $this->loginUser(
            'multi.session.owner'
        );

        $otherSessionId = $this->createSession(
            $user,
            now()->getTimestamp(),
            '10.0.0.20',
            'Other Device'
        );

        $response = $this->getJson(
            route(
                'account.sessions.index',
                absolute: false
            )
        );

        $otherSession = collect(
            $response->json('sessions')
        )->firstWhere(
            'ip_address',
            '10.0.0.20'
        );

        $this->assertNotNull(
            $otherSession
        );

        $deleteResponse = $this->delete(
            route(
                'account.sessions.destroy',
                [
                    'sessionKey' => $otherSession['key'],
                ],
                absolute: false
            )
        );

        $deleteResponse->assertNoContent();

        $this->assertDatabaseMissing(
            'sessions',
            [
                'id' => $otherSessionId,
            ]
        );

        $this->assertAuthenticatedAs(
            $user
        );

        $dashboardResponse = $this->get(
            '/dashboard'
        );

        $dashboardResponse->assertRedirect(
            '/admin'
        );
    }

    public function test_terminated_session_payload_can_no_longer_be_reused(): void
    {
        $user = $this->loginUser(
            'terminated.session.owner'
        );

        $otherSessionId = $this->createSession(
            $user,
            now()->getTimestamp(),
            '10.0.0.30',
            'Terminated Device'
        );

        $listResponse = $this->getJson(
            route(
                'account.sessions.index',
                absolute: false
            )
        );

        $otherSession = collect(
            $listResponse->json('sessions')
        )->firstWhere(
            'ip_address',
            '10.0.0.30'
        );

        $this->delete(
            route(
                'account.sessions.destroy',
                [
                    'sessionKey' => $otherSession['key'],
                ],
                absolute: false
            )
        )->assertNoContent();

        $handler = $this->app['session']
            ->driver('database')
            ->getHandler();

        $this->assertSame(
            '',
            $handler->read($otherSessionId)
        );
    }

    public function test_user_cannot_terminate_session_owned_by_another_account(): void
    {
        $user = $this->loginUser(
            'account.a'
        );

        $otherUser = User::factory()->create([
            'account_login_identifier' => 'account.b',
        ]);

        $otherSessionId = $this->createSession(
            $otherUser,
            now()->getTimestamp(),
            '10.0.0.40',
            'Other Account'
        );

        $otherSessionKey = hash_hmac(
            'sha256',
            $otherSessionId,
            (string) config('app.key')
        );

        $response = $this->delete(
            route(
                'account.sessions.destroy',
                [
                    'sessionKey' => $otherSessionKey,
                ],
                absolute: false
            )
        );

        $response->assertNotFound();

        $this->assertDatabaseHas(
            'sessions',
            [
                'id' => $otherSessionId,
                'user_id' => $otherUser->id,
            ]
        );

        $this->assertAuthenticatedAs(
            $user
        );
    }

    public function test_user_can_terminate_current_session(): void
    {
        $user = $this->loginUser(
            'current.session.owner'
        );

        $response = $this->getJson(
            route(
                'account.sessions.index',
                absolute: false
            )
        );

        $currentSession = collect(
            $response->json('sessions')
        )->firstWhere(
            'is_current',
            true
        );

        $this->assertNotNull(
            $currentSession
        );

        $currentSessionId = DB::table(
            'sessions'
        )
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->value('id');

        $deleteResponse = $this->delete(
            route(
                'account.sessions.destroy',
                [
                    'sessionKey' => $currentSession['key'],
                ],
                absolute: false
            )
        );

        $deleteResponse->assertRedirect('/');

        $this->assertGuest();

        $this->assertDatabaseMissing(
            'sessions',
            [
                'id' => $currentSessionId,
            ]
        );
    }

    private function loginUser(
        string $accountLoginIdentifier
    ): User {
        $user = User::factory()->create([
            'account_login_identifier' => $accountLoginIdentifier,
        ]);

        $response = $this->post('/login', [
            'account_login_identifier' => $accountLoginIdentifier,
            'password' => 'password',
        ]);

        $response->assertRedirect(
            route(
                'login.success',
                absolute: false
            )
        );

        $this->assertAuthenticatedAs(
            $user
        );

        /*
     * Laravel HTTP tests do not automatically persist the
     * session cookie returned by one response into the next
     * request.
     *
     * Capture the authenticated session cookie created during
     * login and explicitly attach it to subsequent requests.
     */
        $sessionCookieName = (string) config(
            'session.cookie'
        );

        $sessionCookie = $response->getCookie(
            $sessionCookieName
        );

        $this->assertNotNull(
            $sessionCookie,
            'Expected login response to contain the session cookie.'
        );

        $this->withCookie(
            $sessionCookieName,
            $sessionCookie->getValue()
        );

        return $user;
    }

    private function createSession(
        User $user,
        int $lastActivity,
        string $ipAddress,
        string $userAgent
    ): string {
        $sessionId = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => base64_encode(
                serialize([])
            ),
            'last_activity' => $lastActivity,
        ]);

        return $sessionId;
    }
}