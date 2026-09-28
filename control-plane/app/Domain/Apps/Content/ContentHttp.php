<?php

namespace App\Domain\Apps\Content;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Wspólne żądania do serwisów z modpackami i pluginami. Modrinth wymaga
 * rozpoznawalnego User-Agenta; błędy serwisów zamieniamy na czytelny wyjątek.
 */
class ContentHttp
{
    public const USER_AGENT = 'VirtHub-Panel/1.0 (+https://github.com/TeYroXOfficial/virthub)';

    /** Czasy cache [świeże, dopuszczalnie nieświeże] w sekundach. */
    public const LISTS = [900, 86400];        // wyszukiwanie, listy wersji

    public const DETAILS = [21600, 604800];   // opis projektu

    public const IMMUTABLE = [86400, 2592000]; // konkretna wersja/plik — nie zmienia się

    public static function client(array $headers = [], int $timeout = 15): PendingRequest
    {
        return self::prepare(Http::withHeaders(['User-Agent' => self::USER_AGENT, ...$headers])->acceptJson(), $timeout);
    }

    /**
     * Wspólne ustawienia żądania: limity czasu i adres z własnego resolvera
     * (HostResolver) — curl nie czeka wtedy na wolny DNS systemu.
     */
    public static function prepare(PendingRequest $request, int $timeout = 15): PendingRequest
    {
        return $request
            ->timeout($timeout)
            ->connectTimeout(8)
            ->withMiddleware(fn (callable $handler) => function ($request, array $options) use ($handler) {
                $uri = $request->getUri();
                $ips = HostResolver::resolve($uri->getHost());
                if ($ips !== []) {
                    $port = $uri->getPort() ?? ($uri->getScheme() === 'http' ? 80 : 443);
                    $options['curl'][CURLOPT_RESOLVE] = [$uri->getHost().':'.$port.':'.implode(',', $ips)];
                }

                return $handler($request, $options);
            });
    }

    /**
     * Cache „stale-while-revalidate”: po czasie świeżości strona dalej dostaje
     * dane od razu, a odświeżenie idzie w tle po wysłaniu odpowiedzi. Tylko
     * pierwsze wejście (albo po okresie nieświeżości) czeka na serwis.
     * Zapisujemy dane już przetworzone — małe, bez changelogów i opisów.
     *
     * @param  array{0: int, 1: int}  $ttl
     */
    public static function cached(string $key, array $ttl, callable $fn): mixed
    {
        return Cache::flexible('content:'.$key, $ttl, $fn);
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
