<?php

namespace App\Domain\Provisioning;

use App\Models\AuditLog;
use App\Models\Server;

/**
 * Domknięcie usunięcia maszyny po stronie panelu.
 *
 * Wywoływane dopiero, gdy hypervisor potwierdzi skasowanie domeny i dysku —
 * wcześniejsze zwolnienie adresu oznaczałoby, że kolejny klient dostaje IP
 * wciąż podpięte do cudzej, działającej maszyny.
 */
class ServerCleanup
{
    public function __construct(
        private readonly HypervisorSelector $selector,
        private readonly IpAllocator $ips,
    ) {}

    public function finalise(Server $server): void
    {
        $this->ips->releaseAll($server);
        $this->selector->release($server);

        $server->forceFill([
            'agent_uuid' => null,
            'vnc_port' => null,
            'vnc_password' => null,
            'root_password' => null,
        ])->save();

        AuditLog::record('server.deleted', $server, ['hostname' => $server->hostname]);

        // Soft delete: rekord znika z panelu, ale zostaje w audycie i historii
        // rozliczeń. Twarde usunięcie zabrałoby ślad po maszynie, na którą
        // klient mógł mieć fakturę.
        $server->delete();
    }
}
