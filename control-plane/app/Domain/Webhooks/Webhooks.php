<?php

namespace App\Domain\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhooki: podpisane powiadomienia POST (JSON) o zdarzeniach w panelu.
 *
 * Nagłówki:  X-VirtHub-Event, X-VirtHub-Delivery (uuid),
 *            X-VirtHub-Signature: t=<unix>,v1=<hex HMAC-SHA256(sekret, "<t>.<treść>")>
 * Odbiorca liczy podpis z tej samej treści i odrzuca stare znaczniki czasu.
 * Odpowiedź 2xx = dostarczone; inaczej ponowienia: 1 min, 5 min, 30 min, 2 h, 6 h.
 */
class Webhooks
{
    /** Kolejne odstępy ponowień w sekundach. */
    public const RETRIES = [60, 300, 1800, 7200, 21600];

    /** Po tylu nieudanych dostarczeniach z rzędu webhook jest wyłączany. */
    public const DISABLE_AFTER = 50;

    private const CACHE_KEY = 'webhooks.active';

    /** Zdarzenia z dziennika audytu, które idą też do webhooków. */
    private const FROM_AUDIT = [
        'server.ordered' => 'server.created',
        'server.suspend' => 'server.suspended',
        'server.unsuspend' => 'server.unsuspended',
        'ip.released' => 'server.ip_changed',
        'ip.mac' => 'server.ip_changed',
        'user.created' => 'user.created',
        'user.admin_created' => 'user.created',
        'user.updated' => 'user.updated',
        'account.profile' => 'user.updated',
        'user.suspended' => 'user.suspended',
        'user.unsuspended' => 'user.unsuspended',
        'user.deleted' => 'user.deleted',
        'invoice.paid' => 'invoice.paid',
        'ticket.opened' => 'ticket.opened',
        'hypervisor.created' => 'hypervisor.created',
        'hypervisor.deleted' => 'hypervisor.deleted',
    ];

    /** @return array<string, string> zdarzenie → opis */
    public static function events(): array
    {
        return [
            'server.created' => __('Serwer zamówiony / utworzony'),
            'server.built' => __('Serwer zbudowany i gotowy'),
            'server.build_failed' => __('Budowa serwera nieudana'),
            'server.destroyed' => __('Serwer usunięty'),
            'server.power' => __('Zmiana stanu zasilania'),
            'server.rebuilt' => __('Reinstalacja systemu zakończona'),
            'server.resized' => __('Zmiana zasobów zakończona'),
            'server.suspended' => __('Serwer zawieszony'),
            'server.unsuspended' => __('Serwer odwieszony'),
            'server.ip_changed' => __('Zmiana adresów IP serwera'),
            'server.migrated' => __('Serwer przeniesiony na inny węzeł'),
            'backup.created' => __('Migawka / kopia utworzona'),
            'backup.restored' => __('Migawka / kopia przywrócona'),
            'backup.failed' => __('Migawka / kopia nieudana'),
            'user.created' => __('Użytkownik utworzony'),
            'user.updated' => __('Użytkownik zmieniony'),
            'user.suspended' => __('Użytkownik zablokowany'),
            'user.unsuspended' => __('Użytkownik odblokowany'),
            'user.deleted' => __('Użytkownik usunięty'),
            'invoice.paid' => __('Faktura opłacona'),
            'ticket.opened' => __('Nowe zgłoszenie'),
            'hypervisor.created' => __('Hypervisor dodany'),
            'hypervisor.deleted' => __('Hypervisor usunięty'),
            'hypervisor.offline' => __('Hypervisor niedostępny'),
            'mailout.sent' => __('Mailout wysłany'),
            'alert.triggered' => __('Alert otwarty (przekroczony próg)'),
            'alert.resolved' => __('Alert zamknięty (wartość w normie)'),
        ];
    }

    /** Wywoływane po zapisie wpisu w dzienniku audytu. */
    public function fromAudit(AuditLog $log): void
    {
        $event = self::FROM_AUDIT[$log->action] ?? null;
        if ($event === null || ! $this->anyActive()) {
            return;
        }
        $subject = $log->subject_type && $log->subject_id && class_exists($log->subject_type)
            ? $log->subject_type::find($log->subject_id) : null;
        $data = $subject ? $this->describe($subject) : ['id' => $log->subject_id];
        // Usunięty użytkownik nie istnieje już w bazie — zostaje e-mail z wpisu.
        $data += array_intersect_key((array) $log->meta, array_flip(['email', 'reason', 'ip', 'address']));
        $this->dispatch($event, $data, ['actor' => $log->actor_label]);
    }

    /**
     * Wysyła zdarzenie do wszystkich aktywnych webhooków, które go słuchają.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    public function dispatch(string $event, array $data, array $extra = []): int
    {
        if (! $this->anyActive()) {
            return 0;
        }
        $count = 0;
        foreach (Webhook::query()->where('is_active', true)->get() as $webhook) {
            if (! $webhook->listensTo($event)) {
                continue;
            }
            $this->queue($webhook, $event, $data, $extra);
            $count++;
        }

        return $count;
    }

    /** Zdarzenie „ping” do sprawdzenia adresu z panelu. */
    public function ping(Webhook $webhook): WebhookDelivery
    {
        $delivery = $this->queue($webhook, 'ping', ['message' => __('Test webhooka z panelu :brand', ['brand' => config('virthub.brand')])], [], false);
        $this->deliver($delivery);

        return $delivery->refresh();
    }

    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        $copy = $this->queue($delivery->webhook, $delivery->event, (array) ($delivery->payload['data'] ?? []), [], false);
        $this->deliver($copy);

        return $copy->refresh();
    }

    /** Jedna próba dostarczenia; przy błędzie planuje kolejną. */
    public function deliver(WebhookDelivery $delivery): void
    {
        $webhook = $delivery->webhook;
        if ($webhook === null || $delivery->status !== WebhookDelivery::PENDING) {
            return;
        }
        // Zajęcie próby: kolejka i harmonogram nie wyślą tego samego dwa razy.
        $attempts = (int) $delivery->attempts + 1;
        $claimed = WebhookDelivery::query()->whereKey($delivery->id)
            ->where('status', WebhookDelivery::PENDING)->where('attempts', (int) $delivery->attempts)
            ->update(['attempts' => $attempts, 'next_attempt_at' => now()->addMinutes(5)]);
        if ($claimed === 0) {
            return;
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $webhook->secret);
        $started = microtime(true);
        $code = null;
        $responseBody = null;
        $error = null;

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'VirtHub-Webhook/1',
                    'X-VirtHub-Event' => $delivery->event,
                    'X-VirtHub-Delivery' => $delivery->uuid,
                    'X-VirtHub-Signature' => 't='.$timestamp.',v1='.$signature,
                ])
                ->withBody((string) $body, 'application/json')
                ->post($webhook->url);
            $code = $response->status();
            $responseBody = mb_substr($response->body(), 0, 2000);
            $ok = $response->successful();
            if (! $ok) {
                $error = 'HTTP '.$code;
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $retry = $ok ? null : (self::RETRIES[$attempts - 1] ?? null);
        $delivery->forceFill([
            'attempts' => $attempts,
            'response_code' => $code,
            'response_body' => $responseBody,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => $ok ? WebhookDelivery::SUCCESS : ($retry !== null && $delivery->event !== 'ping' ? WebhookDelivery::PENDING : WebhookDelivery::FAILED),
            'next_attempt_at' => $ok || $delivery->event === 'ping' || $retry === null ? null : now()->addSeconds($retry),
            'delivered_at' => $ok ? now() : null,
        ])->save();

        $failures = $ok ? 0 : $webhook->consecutive_failures + 1;
        $webhook->forceFill([
            'consecutive_failures' => $failures,
            'last_delivery_at' => now(),
            'is_active' => $webhook->is_active && $failures < self::DISABLE_AFTER,
        ])->save();
        if ($failures === self::DISABLE_AFTER) {
            $this->forget();
            AuditLog::record('webhook.auto_disabled', $webhook, ['failures' => $failures]);
        }
    }

    /** Ponowienia, których czas minął (harmonogram co minutę). */
    public function retryDue(): int
    {
        $due = WebhookDelivery::query()
            ->where('status', WebhookDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(100)
            ->get();
        foreach ($due as $delivery) {
            $this->deliver($delivery);
        }

        // Historia: 30 dni.
        WebhookDelivery::query()->where('created_at', '<', now()->subDays(30))->delete();

        return $due->count();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** @return array<string, mixed> */
    public function describe(Model $model): array
    {
        return match (true) {
            $model instanceof Server => [
                'id' => $model->id,
                'hostname' => $model->hostname,
                'label' => $model->label,
                'state' => $model->state?->value,
                'virtualization' => $model->virtualization?->value,
                'vcpu' => $model->vcpu,
                'ram_mb' => $model->ram_mb,
                'disk_gb' => $model->disk_gb,
                'billing_reference' => $model->billing_reference,
                'hypervisor_id' => $model->hypervisor_id,
                'user' => $model->user ? ['id' => $model->user->id, 'email' => $model->user->email] : null,
                'ip_addresses' => $model->ipAddresses()->pluck('address')->all(),
            ],
            $model instanceof User => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'role' => $model->role,
                'billing_reference' => $model->billing_reference,
                'suspended' => $model->isSuspended(),
            ],
            default => ['type' => class_basename($model), 'id' => $model->getKey()],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    private function queue(Webhook $webhook, string $event, array $data, array $extra, bool $async = true): WebhookDelivery
    {
        $uuid = (string) Str::uuid();
        $delivery = WebhookDelivery::create([
            'uuid' => $uuid,
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => ['id' => $uuid, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data] + $extra,
            'status' => WebhookDelivery::PENDING,
            // Harmonogram wyśle sam, gdyby kolejka nie działała.
            'next_attempt_at' => now()->addMinutes(2),
        ]);
        if ($async) {
            DeliverWebhookJob::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }

    private function anyActive(): bool
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, fn () => Webhook::query()->where('is_active', true)->exists());
        } catch (Throwable) {
            return false;
        }
    }
}
