<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'action',
        'direction',
        'protocol',
        'port_from',
        'port_to',
        'source',
        'comment',
        'position',
    ];

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
        $ports = match (true) {
            $this->port_from && $this->port_to && $this->port_from !== $this->port_to
                => "porty {$this->port_from}-{$this->port_to}",
            (bool) $this->port_from => "port {$this->port_from}",
            default => 'wszystkie porty',
        };

        $source = $this->source ? "z {$this->source}" : 'z dowolnego adresu';
        $verb = $this->action === 'accept' ? 'Zezwól' : 'Zablokuj';

        return "{$verb}: {$this->protocol}, {$ports}, {$source}";
    }
}
