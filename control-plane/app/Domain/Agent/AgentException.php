<?php

namespace App\Domain\Agent;

use RuntimeException;
use Throwable;

class AgentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Czy warto ponowić. Odmowa agenta (4xx) to zwykle stan maszyny, którego
     * ponowienie nie zmieni; brak łączności albo błąd hosta (5xx) — przeciwnie.
     */
    public function isRetryable(): bool
    {
        return $this->status === null || $this->status >= 500;
    }
}
