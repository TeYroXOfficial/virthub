<?php

namespace App\Domain\Metrics;

use App\Domain\Mail\TemplateMailer;
use App\Domain\Webhooks\Webhooks;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Alerty: co minutę liczy metryki z danych, które panel już zbiera (próbki
 * maszyn, heartbeat węzłów, migawki), otwiera incydent, gdy próg jest
 * przekroczony dłużej niż czas reguły, i zamyka go, gdy wartość wróci do normy.
 * Otwarcie i zamknięcie idą e-mailem do personelu i webhookiem.
 */
class Alerts
{
    /** @return array<string, array{label:string, scope:string, unit:string}> */
    public static function metrics(): array
    {
        return [
            'server_cpu' => ['label' => __('Maszyna: CPU (%)'), 'scope' => 'server', 'unit' => '%'],
            'server_ram' => ['label' => __('Maszyna: RAM (%)'), 'scope' => 'server', 'unit' => '%'],
            'server_disk_io' => ['label' => __('Maszyna: I/O dysku (MB/s)'), 'scope' => 'server', 'unit' => 'MB/s'],
            'server_network' => ['label' => __('Maszyna: ruch sieciowy (Mbit/s)'), 'scope' => 'server', 'unit' => 'Mbit/s'],
            'server_traffic' => ['label' => __('Maszyna: transfer w miesiącu (% limitu)'), 'scope' => 'server', 'unit' => '%'],
            'backup_failed' => ['label' => __('Maszyna: nieudana migawka'), 'scope' => 'server', 'unit' => ''],
            'hypervisor_offline' => ['label' => __('Hypervisor: brak kontaktu (minuty)'), 'scope' => 'hypervisor', 'unit' => 'min'],
            'hypervisor_ram' => ['label' => __('Hypervisor: zajęty RAM (%)'), 'scope' => 'hypervisor', 'unit' => '%'],
            'hypervisor_disk' => ['label' => __('Hypervisor: zajęty dysk (%)'), 'scope' => 'hypervisor', 'unit' => '%'],
            'hypervisor_load' => ['label' => __('Hypervisor: obciążenie CPU (% rdzeni)'), 'scope' => 'hypervisor', 'unit' => '%'],
        ];
    }

    public function __construct(private readonly Traffic $traffic) {}

    /** Jeden przebieg dla wszystkich aktywnych reguł. Zwraca liczbę zmian (otwarte + zamknięte). */
    public function evaluate(): int
    {
        $changes = 0;
        foreach (AlertRule::query()->where('is_active', true)->get() as $rule) {
            try {
                $changes += $this->evaluateRule($rule);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $changes;
    }

    public function evaluateRule(AlertRule $rule): int
    {
        $values = $this->values($rule);
        $open = AlertIncident::query()->where('alert_rule_id', $rule->id)->whereNull('resolved_at')->get()
            ->keyBy(fn (AlertIncident $i) => $i->subject_type.':'.$i->subject_id);
        $changes = 0;

        foreach ($values as $key => [$label, $value]) {
            $breach = $value !== null && $this->breaches($rule, $value);
            $incident = $open->pull($key);
            $pendingKey = 'alert-pending:'.$rule->id.':'.$key;

            if ($breach && $incident === null) {
                // Metryki chwilowe (heartbeat węzła) muszą trwać przez czas reguły.
                if ($this->instant($rule) && $rule->duration_minutes > 0) {
                    $since = Cache::get($pendingKey);
                    if ($since === null) {
                        Cache::put($pendingKey, now()->getTimestamp(), now()->addDay());

                        continue;
                    }
                    if (now()->getTimestamp() - (int) $since < $rule->duration_minutes * 60) {
                        continue;
                    }
                }
                Cache::forget($pendingKey);
                [$type, $id] = explode(':', $key, 2);
                $incident = AlertIncident::create([
                    'alert_rule_id' => $rule->id, 'subject_type' => $type, 'subject_id' => (int) $id,
                    'subject_label' => $label, 'value' => $value, 'peak' => $value, 'started_at' => now(),
                ]);
                $this->notify($rule, $incident, 'triggered');
                $changes++;
            } elseif ($breach) {
                $incident->forceFill(['value' => $value, 'peak' => max((float) $incident->peak, $value)])->save();
            } else {
                Cache::forget($pendingKey);
                if ($incident !== null) {
                    $this->resolve($rule, $incident, $value);
                    $changes++;
                }
            }
        }

        // Obiekt zniknął (usunięta maszyna, minęło okno migawki) — alert zamknięty.
        foreach ($open as $incident) {
            $this->resolve($rule, $incident, null);
            $changes++;
        }

        return $changes;
    }

    /** @return array<string, array{0:string, 1:float|null}> "typ:id" → [etykieta, wartość] */
    public function values(AlertRule $rule): array
    {
        $window = now()->subMinutes(max(1, $rule->duration_minutes));
        $servers = fn () => Server::query()->whereNotNull('agent_uuid')
            ->when($rule->hypervisor_id, fn ($q) => $q->where('hypervisor_id', $rule->hypervisor_id));
        $avg = function (string $expression) use ($servers, $window) {
            $rows = ServerMetric::query()
                ->whereIn('server_id', $servers()->select('id'))
                ->where('sampled_at', '>=', $window)
                ->groupBy('server_id')
                ->selectRaw("server_id, AVG({$expression}) as v")
                ->pluck('v', 'server_id');
            $labels = Server::query()->whereIn('id', $rows->keys())->pluck('hostname', 'id');

            return $rows->mapWithKeys(fn ($v, $id) => ['server:'.$id => [(string) ($labels[$id] ?? '#'.$id), $v === null ? null : round((float) $v, 2)]])->all();
        };

        return match ($rule->metric) {
            'server_cpu' => $avg('cpu_percent'),
            'server_ram' => $avg('CASE WHEN ram_total_mb > 0 THEN ram_used_mb * 100.0 / ram_total_mb END'),
            'server_disk_io' => $avg('(COALESCE(disk_read_bps, 0) + COALESCE(disk_write_bps, 0)) / 1048576.0'),
            'server_network' => $avg('(COALESCE(net_rx_bps, 0) + COALESCE(net_tx_bps, 0)) * 8 / 1000000.0'),
            'server_traffic' => $servers()->where('bandwidth_gb', '>', 0)->get()
                ->mapWithKeys(fn (Server $s) => ['server:'.$s->id => [$s->hostname, (float) ($this->traffic->usage($s)['percent'] ?? 0)]])->all(),
            'backup_failed' => Backup::query()->with('server')->where('status', Backup::STATUS_FAILED)
                ->where('updated_at', '>=', now()->subMinutes(max(15, $rule->duration_minutes)))
                ->when($rule->hypervisor_id, fn ($q) => $q->whereHas('server', fn ($s) => $s->where('hypervisor_id', $rule->hypervisor_id)))
                ->get()
                ->mapWithKeys(fn (Backup $b) => ['backup:'.$b->id => [($b->server?->hostname ?? '#'.$b->server_id).' — '.$b->name, 1.0]])->all(),
            default => $this->hypervisorValues($rule),
        };
    }

    /** @return array<string, array{0:string, 1:float|null}> */
    private function hypervisorValues(AlertRule $rule): array
    {
        $out = [];
        $nodes = Hypervisor::query()->whereNotNull('enrolled_at')->where('status', '!=', Hypervisor::STATUS_MAINTENANCE)
            ->when($rule->hypervisor_id, fn ($q) => $q->whereKey($rule->hypervisor_id))->get();
        foreach ($nodes as $node) {
            $h = (array) ($node->last_health ?? []);
            $pct = fn ($used, $total) => $total > 0 ? round($used * 100 / $total, 1) : null;
            $value = match ($rule->metric) {
                'hypervisor_offline' => $node->status === Hypervisor::STATUS_OFFLINE
                    ? (float) round(($node->last_seen_at ?? $node->updated_at ?? now())->diffInMinutes(now()), 1) : 0.0,
                'hypervisor_ram' => isset($h['ram_mb_total'], $h['ram_mb_free']) ? $pct($h['ram_mb_total'] - $h['ram_mb_free'], $h['ram_mb_total']) : null,
                'hypervisor_disk' => isset($h['disk_gb_total'], $h['disk_gb_free']) ? $pct($h['disk_gb_total'] - $h['disk_gb_free'], $h['disk_gb_total']) : null,
                'hypervisor_load' => isset($h['cpu_load_1m']) ? $pct((float) $h['cpu_load_1m'], (int) ($h['cpu_cores_total'] ?? $node->cpu_cores_total)) : null,
                default => null,
            };
            $out['hypervisor:'.$node->id] = [$node->name, $value];
        }

        return $out;
    }

    private function breaches(AlertRule $rule, float $value): bool
    {
        return match ($rule->metric) {
            'backup_failed' => $value > 0,
            // Czas reguły to tu sam próg: „offline dłużej niż N minut".
            'hypervisor_offline' => $value >= max(1, $rule->duration_minutes),
            default => $value > $rule->threshold,
        };
    }

    /** Metryki bez historii w bazie — czas trwania liczymy sami. */
    private function instant(AlertRule $rule): bool
    {
        return in_array($rule->metric, ['hypervisor_ram', 'hypervisor_disk', 'hypervisor_load', 'server_traffic'], true);
    }

    private function resolve(AlertRule $rule, AlertIncident $incident, ?float $value): void
    {
        $incident->forceFill(['resolved_at' => now(), 'value' => $value ?? $incident->value])->save();
        $this->notify($rule, $incident, 'resolved');
    }

    public static function format(AlertRule $rule, ?float $value): string
    {
        $unit = self::metrics()[$rule->metric]['unit'] ?? '';
        if ($value === null) {
            return '—';
        }
        if ($rule->metric === 'backup_failed') {
            return __('nieudana');
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').($unit === '%' ? '%' : ' '.$unit);
    }

    public static function thresholdLabel(AlertRule $rule): string
    {
        return match ($rule->metric) {
            'backup_failed' => '—',
            'hypervisor_offline' => '≥ '.$rule->duration_minutes.' min',
            default => '> '.self::format($rule, (float) $rule->threshold),
        };
    }

    private function notify(AlertRule $rule, AlertIncident $incident, string $kind): void
    {
        $vars = ['alert' => [
            'rule' => $rule->name,
            'subject' => $incident->subject_label,
            'metric' => self::metrics()[$rule->metric]['label'] ?? $rule->metric,
            'value' => self::format($rule, $incident->value),
            'threshold' => self::thresholdLabel($rule),
            'url' => route('panel.admin.alerts'),
        ]];
        if ($rule->notify_email) {
            app(TemplateMailer::class)->sendToStaff('admin.hypervisors', 'alert.'.$kind, $vars);
        }
        if ($rule->notify_webhook) {
            app(Webhooks::class)->dispatch('alert.'.$kind, [
                'rule' => ['id' => $rule->id, 'name' => $rule->name, 'metric' => $rule->metric, 'threshold' => $rule->threshold],
                'subject' => ['type' => $incident->subject_type, 'id' => $incident->subject_id, 'label' => $incident->subject_label],
                'value' => $incident->value,
                'started_at' => $incident->started_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ]);
        }
    }
}
