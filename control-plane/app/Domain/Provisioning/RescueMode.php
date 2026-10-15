<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\ServerPayload;
use App\Enums\ServerState;
use App\Jobs\RunServerActionJob;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Tryb ratunkowy: maszyna startuje z czystego systemu (nakładka na szablon
 * oznaczony jako ratunkowy, domyślnie pierwszy Debian/Ubuntu KVM) z tymi
 * samymi adresami, a jej dysk jest podłączony jako /dev/vdi. Klient loguje się
 * jako root hasłem z panelu, naprawia system i kończy tryb — maszyna wraca do
 * dokładnie tej samej definicji.
 */
class RescueMode
{
    public static function template(): ?OsTemplate
    {
        $kvm = fn () => OsTemplate::query()->where('virtualization', 'kvm')->where('is_active', true)->whereNotNull('image_file');

        return $kvm()->where('is_rescue', true)->orderBy('sort_order')->first()
            ?? $kvm()->whereIn('family', ['debian', 'ubuntu'])->orderByDesc('version')->first();
    }

    public function start(Server $server, ?User $actor = null, array $sshKeys = []): ServerJob
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Tryb ratunkowy jest dostępny tylko dla maszyn KVM.'));
        }
        if ($server->inRescue()) {
            throw new \DomainException(__('Maszyna już jest w trybie ratunkowym.'));
        }
        if ($server->isSuspended() || $server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
        if ($server->isElevated()) {
            throw new \DomainException(__('Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany dysku.'));
        }
        $template = self::template();
        if ($template === null) {
            throw new \DomainException(__('Brak szablonu systemu ratunkowego — administrator musi oznaczyć szablon KVM jako ratunkowy.'));
        }
        $password = Str::password(20, symbols: false);
        $server->forceFill(['rescue_password' => $password])->save();

        return $this->dispatch($server, $actor, ['action' => 'start', 'template' => $template->image_file, 'ssh_keys' => array_values($sshKeys)], 'server.rescue_started');
    }

    public function stop(Server $server, ?User $actor = null): ServerJob
    {
        if (! $server->inRescue()) {
            throw new \DomainException(__('Maszyna nie jest w trybie ratunkowym.'));
        }
        if ($server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }

        return $this->dispatch($server, $actor, ['action' => 'stop'], 'server.rescue_stopped');
    }

    /** Ładunek dla agenta (hasło czytane z bazy — nie leży w kolejce panelu). */
    public static function agentPayload(Server $server, array $payload): array
    {
        if (($payload['action'] ?? null) !== 'start') {
            return ['action' => 'stop'];
        }

        return [
            'action' => 'start',
            'template' => $payload['template'],
            'root_password' => $server->rescue_password,
            'ssh_keys' => $payload['ssh_keys'] ?? [],
            'interfaces' => ServerPayload::interfaces($server),
            'nameservers' => ServerPayload::nameservers($server),
            'mac' => ServerPayload::mac($server),
        ];
    }

    /** Wynik z agenta: włączony albo wyłączony tryb. */
    public static function apply(Server $server, array $result): void
    {
        $on = (bool) ($result['rescue'] ?? false);
        $server->forceFill(['rescue_at' => $on ? now() : null, 'rescue_password' => $on ? $server->rescue_password : null])->save();
        $server->markState(ServerState::fromAgentState($result['state'] ?? 'running'));
    }

    private function dispatch(Server $server, ?User $actor, array $payload, string $event): ServerJob
    {
        $job = ServerJob::create([
            'server_id' => $server->id,
            'hypervisor_id' => $server->hypervisor_id,
            'user_id' => $actor?->id,
            'action' => 'rescue',
            'status' => ServerJob::STATUS_QUEUED,
            'payload' => $payload,
        ]);
        AuditLog::record($event, $server, [], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }
}
