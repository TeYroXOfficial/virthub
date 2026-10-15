<?php

namespace App\Domain\Webhooks;

use App\Models\Webhook;

/**
 * Gotowe odbiorniki webhooków: poza własnym formatem VirtHub (JSON z podpisem
 * HMAC) panel umie wysłać czytelną wiadomość prosto do Slacka, Discorda,
 * Google Chat, Telegrama i Pushovera — bez pośredniego skryptu.
 */
final class WebhookFormats
{
    public const FORMATS = ['virthub', 'slack', 'discord', 'google_chat', 'telegram', 'pushover'];

    /** Pola danych zdarzenia pokazywane w wiadomości (w tej kolejności). */
    private const SUMMARY_KEYS = ['hostname', 'name', 'email', 'ip', 'primary_ip', 'from_hypervisor', 'to_hypervisor', 'hypervisor', 'power_action', 'reason', 'error', 'number', 'total', 'subject', 'metric', 'value'];

    public static function label(string $format): string
    {
        return match ($format) {
            'slack' => 'Slack',
            'discord' => 'Discord',
            'google_chat' => 'Google Chat',
            'telegram' => 'Telegram',
            'pushover' => 'Pushover',
            default => __('VirtHub (JSON z podpisem)'),
        };
    }

    /** Czytelny tekst zdarzenia: „VirtHub · Serwer zbudowany i gotowy — vps1.example.com · 203.0.113.10”. */
    public static function text(string $event, array $data): string
    {
        $label = Webhooks::events()[$event] ?? ($event === 'ping' ? __('Test połączenia') : $event);
        $parts = [];
        $flat = $data + (array) ($data['server'] ?? []) + (array) ($data['user'] ?? []);
        foreach (self::SUMMARY_KEYS as $key) {
            if (isset($flat[$key]) && is_scalar($flat[$key]) && (string) $flat[$key] !== '') {
                $parts[] = $key === 'error' || $key === 'reason' ? mb_substr((string) $flat[$key], 0, 300) : (string) $flat[$key];
            }
            if (count($parts) >= 5) {
                break;
            }
        }

        return config('virthub.brand').' · '.$label.($parts ? ' — '.implode(' · ', array_unique($parts)) : '');
    }

    /**
     * Treść i adres dla odbiornika.
     *
     * @param  array<string, mixed>  $payload  {id, event, created_at, data}
     * @return array{url: string, body: array<string, mixed>}
     */
    public static function render(Webhook $webhook, array $payload): array
    {
        $text = self::text((string) $payload['event'], (array) ($payload['data'] ?? []));
        $options = (array) $webhook->options;

        return match ($webhook->format ?: 'virthub') {
            'slack', 'google_chat' => ['url' => $webhook->url, 'body' => ['text' => $text]],
            'discord' => ['url' => $webhook->url, 'body' => ['content' => mb_substr($text, 0, 2000), 'allowed_mentions' => ['parse' => []]]],
            'telegram' => ['url' => $webhook->url, 'body' => ['chat_id' => (string) ($options['chat_id'] ?? ''), 'text' => $text, 'disable_web_page_preview' => true]],
            'pushover' => ['url' => $webhook->url, 'body' => [
                'token' => (string) ($options['app_token'] ?? ''), 'user' => (string) ($options['user_key'] ?? ''),
                'title' => (string) config('virthub.brand'), 'message' => mb_substr($text, 0, 1024),
            ]],
            default => ['url' => $webhook->url, 'body' => $payload],
        };
    }
}
