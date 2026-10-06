<?php

namespace App\Domain\External;

/** Stan maszyny u dostawcy w postaci niezależnej od dostawcy. */
final class RemoteServer
{
    public const BUILDING = 'building';

    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    public const BUSY = 'busy';           // reinstalacja, snapshot, migracja…

    public const SUSPENDED = 'suspended'; // zawieszona po stronie dostawcy (np. płatność)

    public const DELETED = 'deleted';

    public const ERROR = 'error';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $ipv4 = null,
        public readonly ?string $ipv6 = null,
        public readonly ?string $password = null,
        public readonly ?int $cpu = null,
        public readonly ?int $ramMb = null,
        public readonly ?int $diskGb = null,
        public readonly ?string $image = null,
    ) {}
}
