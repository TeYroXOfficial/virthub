<?php

namespace App\Http\Controllers\Internal;

use Illuminate\Http\Request;

/** Podpis żądania z węzła: HMAC-SHA256 sekretem węzła (callback_secret). */
trait VerifiesNodeSignature
{
    private function hasValidNodeSignature(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-VH-Signature', '');
        $timestamp = (string) $request->header('X-VH-Timestamp', '');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $canonical = implode("\n", [$timestamp, 'POST', $request->getPathInfo(), hash('sha256', $request->getContent())]);

        return hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
    }
}
