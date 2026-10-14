<?php

namespace App\Http\Controllers\Web;

use App\Domain\Api\ApiCatalog;
use App\Domain\Api\ApiExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dokumentacja API: strona z konsolą do wysyłania zapytań (jak Postman),
 * plik OpenAPI i kolekcja Postman. Publiczne — integratorzy czytają je bez
 * konta; zapytania i tak wymagają tokenu.
 */
class ApiDocsController extends Controller
{
    public function show(Request $request): View
    {
        $this->applyLanguage($request);
        $user = $request->user();
        $groups = ApiCatalog::groups();
        $endpoints = collect(ApiCatalog::endpoints());
        // Klient nie potrzebuje opisu administracji i integracji billingowej.
        if (! $user?->isStaff()) {
            $endpoints = $endpoints->reject(fn ($e) => in_array($e['group'], ['admin', 'billing'], true) && ! $request->boolean('all'));
        }

        return view('api-docs.show', [
            'groups' => $groups,
            'endpoints' => $endpoints->groupBy('group'),
            'httpErrors' => ApiCatalog::errors(),
            'baseUrl' => ApiExport::baseUrl(),
            'introduction' => ApiExport::introduction(),
            'catalog' => $endpoints->values()->map(fn ($e) => $e + ['example_body' => ApiExport::exampleBody($e)])->all(),
        ]);
    }

    public function openApi(Request $request): JsonResponse
    {
        $this->applyLanguage($request);

        return response()->json(ApiExport::openApi(), 200, ['Access-Control-Allow-Origin' => '*'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function postman(Request $request): JsonResponse
    {
        $this->applyLanguage($request);

        return response()->json(ApiExport::postman(), 200, [
            'Content-Disposition' => 'attachment; filename="'.str(config('virthub.brand'))->slug().'-api.postman_collection.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** ?lang=en — dokumentacja w wybranym języku, niezależnie od ustawień przeglądarki. */
    private function applyLanguage(Request $request): void
    {
        $lang = $request->string('lang')->value();
        if ($lang !== '' && array_key_exists($lang, config('virthub.locales', []))) {
            app()->setLocale($lang);
        }
    }
}
