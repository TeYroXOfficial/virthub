<?php

namespace App\Domain\Console;

use App\Domain\Agent\AgentClient;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dostęp do konsoli w trzech jednorazowych krokach:
 *
 *  1. bilet (60 s)  — wydawany w panelu albo przez API dla właściciela maszyny,
 *  2. sesja (60 s)  — powstaje, gdy właściciel otworzy stronę konsoli z biletem;
 *                     jej identyfikator trafia do przeglądarki jako adres WebSocket,
 *  3. wymiana       — przekaźnik konsoli oddaje sesję panelowi (wspólny sekret)
 *                     i dostaje podpisane parametry połączenia z agentem.
 *
 * Każdy krok zużywa poprzedni, więc przechwycony adres jest bezużyteczny po
 * pierwszym użyciu, a sekret węzła nigdy nie opuszcza serwera panelu.
 */
class ConsoleSessions
{
    private const TTL = 60;

    public static function enabled(): bool
    {
        return filled(config('virthub.console_secret'));
    }

    public function issueTicket(Server $server, User $user): string
    {
        $token = Str::random(48);

        Cache::put("console:{$token}", [
            'server_id' => $server->id,
            'user_id' => $user->id,
        ], now()->addSeconds(self::TTL));

        return $token;
    }

    /** @return array{server_id: int, user_id: int}|null */
    public function takeTicket(string $token): ?array
    {
        return Cache::pull("console:{$token}");
    }

    public function openSession(Server $server): string
    {
        $session = Str::random(64);

        Cache::put("console-session:{$session}", ['server_id' => $server->id], now()->addSeconds(self::TTL));

        return $session;
    }

    /**
     * @return array{url: string, headers: array<string, string>, ca_pem: ?string, kind: string, server_id: int}|null
     */
    public function redeem(string $session): ?array
    {
        $data = Cache::pull("console-session:{$session}");

        if ($data === null) {
            return null;
        }

        $server = Server::with('hypervisor')->find($data['server_id']);

        if ($server === null || $server->hypervisor === null || blank($server->agent_uuid)) {
            return null;
        }

        return [
            ...(new AgentClient($server->hypervisor))->consoleConnection($server->agent_uuid),
            'kind' => $server->isContainer() ? 'terminal' : 'vnc',
            'server_id' => $server->id,
        ];
    }
}
