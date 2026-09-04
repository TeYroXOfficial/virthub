<?php

namespace App\Domain\Provisioning;

use RuntimeException;

/**
 * Brak miejsca na flocie pod nową maszynę. Wyjątek biznesowy, nie awaria —
 * panel pokazuje go klientowi jako komunikat, a nie jako błąd 500.
 */
class NoCapacityException extends RuntimeException {}
