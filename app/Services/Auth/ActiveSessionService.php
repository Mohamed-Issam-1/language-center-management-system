<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActiveSessionService
{
    public function forUser(
        User $user,
        string $currentSessionId
    ): Collection {
        return $this->activeSessionsQuery($user)
            ->orderByDesc('last_activity')
            ->get([
                'id',
                'ip_address',
                'user_agent',
                'last_activity',
            ])
            ->map(function (object $session) use (
                $currentSessionId
            ): array {
                return [
                    'key' => $this->publicKey($session->id),

                    'ip_address' => $session->ip_address,

                    'user_agent' => $session->user_agent,

                    'last_activity' => Carbon::createFromTimestamp(
                        $session->last_activity
                    )->toIso8601String(),

                    'is_current' => hash_equals(
                        $currentSessionId,
                        $session->id
                    ),
                ];
            });
    }

    public function findOwnedActiveSession(
        User $user,
        string $sessionKey
    ): ?object {
        if (! preg_match('/^[a-f0-9]{64}$/', $sessionKey)) {
            return null;
        }

        foreach (
            $this->activeSessionsQuery($user)->get() as $session
        ) {
            if (
                hash_equals(
                    $this->publicKey($session->id),
                    $sessionKey
                )
            ) {
                return $session;
            }
        }

        return null;
    }

    public function terminate(
        User $user,
        string $sessionId
    ): bool {
        return $this->sessionQuery()
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->delete() === 1;
    }

    private function activeSessionsQuery(
        User $user
    ): Builder {
        $cutoff = now()
            ->subMinutes(
                (int) config('session.lifetime')
            )
            ->getTimestamp();

        return $this->sessionQuery()
            ->where('user_id', $user->id)
            ->where(
                'last_activity',
                '>=',
                $cutoff
            );
    }

    private function sessionQuery(): Builder
    {
        return DB::connection(
            config('session.connection')
        )->table(
            config('session.table', 'sessions')
        );
    }

    private function publicKey(
        string $sessionId
    ): string {
        return hash_hmac(
            'sha256',
            $sessionId,
            (string) config('app.key')
        );
    }
}
