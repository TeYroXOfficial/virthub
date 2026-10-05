<?php

namespace App\Domain\Mail;

use App\Models\AppServer;
use App\Models\Server;

/** Gotowe powiadomienia o usługach — zmienne szablonów budowane w jednym miejscu. */
class Notify
{
    public function __construct(private readonly TemplateMailer $mailer) {}

    public function server(Server $server, string $key, array $extra = []): bool
    {
        $owner = $server->user;
        if ($owner === null) {
            return false;
        }
        $ip = $server->primaryIp()?->address;

        return $this->mailer->send($owner, $key, $extra + ['server' => [
            'hostname' => $server->hostname,
            'ip' => $ip ?? '—',
            'os' => $server->osLabel() ?? '—',
            'vcpu' => $server->vcpu,
            'ram' => round($server->ram_mb / 1024, 1).' GB',
            'disk' => $server->disk_gb.' GB',
            'url' => route('panel.servers.show', $server),
        ]]);
    }

    public function app(AppServer $app, string $key, array $extra = []): bool
    {
        $owner = $app->user;
        if ($owner === null) {
            return false;
        }

        return $this->mailer->send($owner, $key, $extra + ['app' => [
            'name' => $app->name,
            'address' => $app->address() ?? '—',
            'template' => $app->egg?->displayName() ?? '—',
            'url' => route('panel.apps.show', $app),
        ]]);
    }
}
