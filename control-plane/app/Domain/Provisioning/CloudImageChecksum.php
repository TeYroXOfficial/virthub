<?php

namespace App\Domain\Provisioning;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Suma kontrolna obrazu cloud z pliku sum dystrybucji (SHA256SUMS,
 * SHA512SUMS, CHECKSUM). Obrazy „latest” zmieniają się co kilka tygodni,
 * więc suma jest ustalana przy każdym pobieraniu, nie zapisana w katalogu.
 */
class CloudImageChecksum
{
    /** @return array{sha256?: string, sha512?: string} */
    public function resolve(string $imageUrl, string $checksumUrl): array
    {
        $body = Cache::remember('cloud-image-sums:'.md5($checksumUrl), now()->addMinutes(10), function () use ($checksumUrl) {
            try {
                $response = Http::timeout(30)->withHeaders(['User-Agent' => 'VirtHub-Panel'])->get($checksumUrl);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new \RuntimeException(__('Nie udało się pobrać sum kontrolnych (:url): :error', ['url' => $checksumUrl, 'error' => $e->getMessage()]), 0, $e);
            }
            if (! $response->successful()) {
                throw new \RuntimeException(__('Nie udało się pobrać sum kontrolnych (:url): HTTP :status.', [
                    'url' => $checksumUrl, 'status' => $response->status(),
                ]));
            }

            return mb_substr($response->body(), 0, 1024 * 1024);
        });

        $hash = self::find($body, basename(parse_url($imageUrl, PHP_URL_PATH) ?: ''));
        if ($hash === null) {
            throw new \RuntimeException(__('W pliku sum kontrolnych nie ma obrazu :file.', ['file' => basename($imageUrl)]));
        }

        return strlen($hash) === 128 ? ['sha512' => $hash] : ['sha256' => $hash];
    }

    /** Formaty: „hash  plik”, „hash *plik”, „SHA256 (plik) = hash”. */
    public static function find(string $sums, string $file): ?string
    {
        foreach (preg_split('/\R/', $sums) as $line) {
            $line = trim($line);
            if (preg_match('/^([0-9a-f]{64}|[0-9a-f]{128})\s+\*?(\S+)$/i', $line, $m) && basename($m[2]) === $file) {
                return strtolower($m[1]);
            }
            if (preg_match('/^SHA(?:256|512)\s*\((.+)\)\s*=\s*([0-9a-f]{64}|[0-9a-f]{128})$/i', $line, $m) && basename($m[1]) === $file) {
                return strtolower($m[2]);
            }
        }

        return null;
    }
}
