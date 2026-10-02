<?php

namespace App\Domain\Admin;

use App\Domain\Settings\MailSettings;
use App\Domain\Updates\Updates;
use App\Enums\ServerState;
use App\Models\AppJob;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\TemplateDownload;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Dane pulpitu administracji: kafelki stanu, zasoby węzłów, zadania,
 * aktywni użytkownicy i ostatnie usługi. Każda sekcja liczona osobno,
 * żeby widok pokazywał tylko to, do czego personel ma uprawnienia.
 */
class Dashboard
{
    public const RECENT_SIZES = [5, 10, 20, 30];

    public function __construct(private readonly Updates $updates) {}

    /** @return array<string, int> licznik maszyn w grupach stanu */
    public function servers(): array
    {
        $byState = Server::query()->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state');
        $sum = fn (ServerState ...$states) => (int) collect($states)->sum(fn ($s) => $byState[$s->value] ?? 0);

        return [
            'running' => $sum(ServerState::Running),
            'stopped' => $sum(ServerState::Stopped),
            'busy' => $sum(ServerState::Building, ServerState::Rebuilding, ServerState::Resizing, ServerState::Deleting),
            'suspended' => $sum(ServerState::Suspended),
            'error' => $sum(ServerState::Error),
            'total' => (int) $byState->sum(),
        ];
    }

    /** @return array<string, int> */
    public function apps(): array
    {
        $active = AppServer::query()->whereNull('suspended_at');

        return [
            'ready' => (clone $active)->where('status', AppServer::STATUS_READY)->count(),
            'installing' => (clone $active)->where('status', AppServer::STATUS_INSTALLING)->count(),
            'failed' => (clone $active)->where('status', AppServer::STATUS_INSTALL_FAILED)->count(),
            'suspended' => AppServer::query()->whereNotNull('suspended_at')->count(),
            'total' => AppServer::query()->count(),
        ];
    }

    /** @return array<string, int> */
    public function ipv4(): array
    {
        $v4 = IpAddress::query()->where('version', 4);

        return [
            'free' => (clone $v4)->whereNull('server_id')->where('is_reserved', false)->count(),
            'used' => (clone $v4)->whereNotNull('server_id')->count(),
            'reserved' => (clone $v4)->whereNull('server_id')->where('is_reserved', true)->count(),
            'total' => (clone $v4)->count(),
        ];
    }

    /** @return array<string, mixed> wersja, aktualizacje, poczta, węzły, nieudane zadania */
    public function system(Collection $nodes): array
    {
        $version = $this->updates->panelVersion();
        $latest = $this->updates->cachedLatest();
        $since = now()->subDay();

        return [
            'version' => $version ? Updates::short($version) : null,
            'up_to_date' => $version && $latest ? $latest['sha'] === $version : null,
            'mail' => MailSettings::configured(),
            'nodes_online' => $nodes->filter(fn ($n) => $n->enrolled_at !== null && $n->isOnline())->count(),
            'nodes_total' => $nodes->whereNotNull('enrolled_at')->count(),
            'failed_jobs' => ServerJob::query()->where('status', ServerJob::STATUS_FAILED)->where('updated_at', '>=', $since)->count()
                + AppJob::query()->where('status', AppJob::STATUS_FAILED)->where('updated_at', '>=', $since)->count(),
            'customers' => User::query()->where('role', User::ROLE_CUSTOMER)->count(),
        ];
    }

    /**
     * Węzły z zajętością hosta (z ostatniego raportu agenta) i przydziałem.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function nodes(Collection $nodes): Collection
    {
        $apps = AppServer::query()->selectRaw('hypervisor_id, count(*) as c')->groupBy('hypervisor_id')->pluck('c', 'hypervisor_id');
        $pct = fn ($used, $total) => $total > 0 ? (int) round(max(0, min(1, $used / $total)) * 100) : null;

        return $nodes->map(function (Hypervisor $node) use ($apps, $pct) {
            $h = $node->last_health ?? [];
            $cores = (int) ($h['cpu_cores_total'] ?? $node->cpu_cores_total);

            return [
                'node' => $node,
                'online' => $node->isOnline(),
                'enrolled' => $node->enrolled_at !== null,
                'cpu' => isset($h['cpu_load_1m']) && $cores > 0 ? $pct($h['cpu_load_1m'], $cores) : null,
                'load' => $h['cpu_load_1m'] ?? null,
                'mem' => isset($h['ram_mb_total'], $h['ram_mb_free']) ? $pct($h['ram_mb_total'] - $h['ram_mb_free'], $h['ram_mb_total']) : null,
                'disk' => isset($h['disk_gb_total'], $h['disk_gb_free']) ? $pct($h['disk_gb_total'] - $h['disk_gb_free'], $h['disk_gb_total']) : null,
                'allocated' => $node->utilisationPercent(),
                'servers' => (int) $node->servers_count,
                'apps' => (int) ($apps[$node->id] ?? 0),
            ];
        });
    }

    /**
     * Zadania w toku i nieudane z ostatniej doby — maszyny, aplikacje, szablony.
     *
     * @return array{running: Collection, failed: Collection}
     */
    public function tasks(bool $servers, bool $apps, bool $templates): array
    {
        $since = now()->subDay();
        $row = fn (string $kind, string $action, ?string $subject, ?string $url, string $status, $at, ?string $error = null) => compact('kind', 'action', 'subject', 'url', 'status', 'at', 'error');

        $collect = function (array $statuses, bool $onlyRecent) use ($servers, $apps, $templates, $since, $row) {
            $out = collect();
            if ($servers) {
                ServerJob::query()->with('server:id,hostname')->whereIn('status', $statuses)
                    ->when($onlyRecent, fn ($q) => $q->where('updated_at', '>=', $since))
                    ->latest('updated_at')->limit(8)->get()
                    ->each(fn ($j) => $out->push($row('server', $j->action, $j->server?->hostname, $j->server ? route('panel.servers.show', $j->server) : null, $j->status, $j->updated_at, $j->error)));
            }
            if ($apps) {
                AppJob::query()->with('server:id,name')->whereIn('status', $statuses)
                    ->when($onlyRecent, fn ($q) => $q->where('updated_at', '>=', $since))
                    ->latest('updated_at')->limit(8)->get()
                    ->each(fn ($j) => $out->push($row('app', $j->action, $j->server?->name, $j->server ? route('panel.apps.show', $j->server) : null, $j->status, $j->updated_at, $j->error)));
            }
            if ($templates) {
                $tplStatuses = array_map(fn ($s) => $s === 'running' ? TemplateDownload::STATUS_DOWNLOADING : $s, $statuses);
                TemplateDownload::query()->with(['template:id,name', 'hypervisor:id,name'])->whereIn('status', $tplStatuses)
                    ->when($onlyRecent, fn ($q) => $q->where('updated_at', '>=', $since))
                    ->latest('updated_at')->limit(8)->get()
                    ->each(fn ($d) => $out->push($row('template', 'download', trim(($d->template?->name ?? '?').' → '.($d->hypervisor?->name ?? '?')), route('panel.admin.templates'), $d->status, $d->updated_at, $d->error)));
            }

            return $out->sortByDesc('at')->take(8)->values();
        };

        return [
            'running' => $collect(['queued', 'running'], false),
            'failed' => $collect(['failed'], true),
        ];
    }

    /**
     * Zalogowani w ostatnich 15 minutach (sesje w bazie). Null, gdy sesje
     * trzymane są gdzie indziej i nie da się tego policzyć.
     *
     * @return Collection<int, array{user: User, ip: ?string, at: Carbon}>|null
     */
    public function activeUsers(): ?Collection
    {
        if (config('session.driver') !== 'database') {
            return null;
        }
        try {
            $table = config('session.table', 'sessions');
            if (! Schema::hasTable($table)) {
                return null;
            }
            $rows = DB::table($table)->whereNotNull('user_id')
                ->where('last_activity', '>=', now()->subMinutes(15)->getTimestamp())
                ->orderByDesc('last_activity')->limit(50)->get(['user_id', 'ip_address', 'last_activity'])
                ->unique('user_id')->take(12);
        } catch (Throwable) {
            return null;
        }
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => $users->has($r->user_id) ? [
            'user' => $users[$r->user_id],
            'ip' => $r->ip_address,
            'at' => Carbon::createFromTimestamp($r->last_activity),
        ] : null)->filter()->values();
    }

    /** @return Collection<int, Server|AppServer> najnowsze usługi obu rodzajów */
    public function recentServices(int $limit, bool $servers, bool $apps): Collection
    {
        $items = collect();
        if ($servers) {
            $items = $items->concat(Server::query()->with(['user:id,name,email', 'hypervisor', 'ipAddresses', 'template'])->latest()->limit($limit)->get());
        }
        if ($apps) {
            $items = $items->concat(AppServer::query()->with(['user:id,name,email', 'hypervisor', 'egg', 'allocations'])->latest()->limit($limit)->get());
        }

        return $items->sortByDesc(fn ($i) => $i->created_at)->take($limit)->values();
    }

    /** @return Collection<int, AuditLog> */
    public function recentLogs(int $limit = 8): Collection
    {
        return AuditLog::query()->with('actor:id,email')->latest()->limit($limit)->get();
    }
}
