<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    use HasFactory;

    public const MANAGED_BY_CUSTOMER = 'customer';

    public const MANAGED_BY_ADMIN = 'admin';

    protected $fillable = [
        'server_id',
        'managed_by',
        'enabled',
        'action',
        'direction',
        'protocol',
        'port_from',
        'port_to',
        'source',
        'comment',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port_from' => 'integer',
            'port_to' => 'integer',
            'position' => 'integer',
        ];
    }

    public function isAdminRule(): bool
    {
        return $this->managed_by === self::MANAGED_BY_ADMIN;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Postać wysyłana do agenta — pola dokładnie jak w jego schemacie. */
    public function toAgentPayload(): array
    {
        return [
            'action' => $this->action,
            'direction' => $this->direction,
            'protocol' => $this->protocol,
            'port_from' => $this->port_from,
            'port_to' => $this->port_to,
            'source' => $this->source,
        ];
    }

    public function describe(): string
    {
        $protocol = match ($this->protocol) {
            'any' => __('cały ruch'),
            'icmp' => 'ICMP (ping)',
            default => strtoupper($this->protocol),
        };

        $ports = match (true) {
            ! in_array($this->protocol, ['tcp', 'udp'], true) => null,
            $this->port_from && $this->port_to && $this->port_from !== $this->port_to
                => __('porty :from–:to', ['from' => $this->port_from, 'to' => $this->port_to]),
            (bool) $this->port_from => __('port :port', ['port' => $this->port_from]),
            default => __('wszystkie porty'),
        };

        $peer = $this->direction === 'in'
            ? ($this->source ? __('z :source', ['source' => $this->source]) : __('z dowolnego adresu'))
            : ($this->source ? __('do :source', ['source' => $this->source]) : __('do dowolnego adresu'));

        $verb = $this->action === 'accept' ? __('Zezwól') : __('Zablokuj');
        $direction = $this->direction === 'in' ? __('przychodzący') : __('wychodzący');

        return "{$verb} ({$direction}): ".implode(', ', array_filter([$protocol, $ports, $peer]));
    }
}
