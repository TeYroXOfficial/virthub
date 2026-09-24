<?php

namespace App\Http\Controllers\Internal;

use App\Domain\Console\ConsoleSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wymiana sesji konsoli na parametry połączenia z agentem — wyłącznie dla
 * przekaźnika konsoli (console-proxy/), uwierzytelnionego wspólnym sekretem.
 */
class ConsoleRedeemController extends Controller
{
    public function __invoke(Request $request, string $session, ConsoleSessions $sessions): JsonResponse
    {
        $secret = (string) config('virthub.console_secret');

        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->header('X-Console-Secret')),
            403,
        );

        $params = $sessions->redeem($session);

        abort_if($params === null, 410, 'Sesja konsoli wygasła albo została już użyta.');

        return response()->json($params);
    }
}
