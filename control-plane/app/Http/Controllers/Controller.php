<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Autoryzacja przez polityki jest dostępna w każdym kontrolerze — dostęp do
    // cudzej maszyny musi być błędem nie do popełnienia, nie kwestią pamiętania
    // o imporcie.
    use AuthorizesRequests;
}
