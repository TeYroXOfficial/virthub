<?php

namespace App\Domain\Billing;

/** Saldo portfela nie wystarcza na operację. */
class InsufficientFunds extends \DomainException {}
