<?php

namespace App\Domain\External;

/** Parametry nowej maszyny u dostawcy. */
final class CreateRequest
{
    /** @param  list<string>  $sshKeys  klucze publiczne OpenSSH */
    public function __construct(
        public readonly string $name,
        public readonly string $location,
        public readonly string $plan,
        public readonly string $image,
        public readonly ?int $cpu = null,
        public readonly ?int $ramMb = null,
        public readonly ?int $diskGb = null,
        public readonly array $sshKeys = [],
        public readonly ?string $reference = null,
    ) {}
}
