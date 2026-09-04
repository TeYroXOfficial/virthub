<?php

namespace App\Domain\Provisioning;

use RuntimeException;

/** Pula adresów hypervisora wyczerpana — administrator musi zaimportować kolejną. */
class NoAddressesException extends RuntimeException {}
