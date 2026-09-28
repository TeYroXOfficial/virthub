<?php

namespace App\Domain\Apps\Content;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Wspólne żądania do serwisów z modpackami i pluginami. Modrinth wymaga
 * rozpoznawalnego User-Agenta; błędy serwisów zamieniamy na czytelny wyjątek.
 */
class ContentHttp
{
    public const USER_AGENT = 'VirtHub-Panel/1.0 (+https://github.com/TeYroXOfficial/virthub)';

    public static function client(array $headers = [], int $timeout = 20): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT, ...$headers])
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(10);
    }

    /** @return array<mixed> */
    public static function json(Response $response, string $service): array
    {
        if ($response->status() === 404) {
            throw new ContentException(__(':service: nie znaleziono.', ['service' => $service]));
        }
        if (! $response->successful()) {
            throw new ContentException(__(':service odpowiedział błędem HTTP :code. Spróbuj za chwilę.', ['service' => $service, 'code' => $response->status()]));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ContentException(__(':service zwrócił nieczytelną odpowiedź.', ['service' => $service]));
        }

        return $data;
    }
}
