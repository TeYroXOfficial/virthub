<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Język panelu: wybór zapisany na koncie, potem w sesji (przed zalogowaniem),
 * potem nagłówek Accept-Language przeglądarki (jeśli włączone w Administracji →
 * Języki), na końcu domyślny instalacji.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('virthub.locales'));
        $valid = fn ($l) => is_string($l) && in_array($l, $available, true) ? $l : null;

        $locale = $valid($request->user()?->locale)
            ?? $valid($request->hasSession() ? $request->session()->get('locale') : null)
            ?? (Setting::get('locale.detect_browser', '1') === '1' ? $this->fromBrowser($request, $available) : null)
            ?? config('virthub.default_locale');

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /** Pierwszy język z Accept-Language, który panel obsługuje („en-GB" → en). */
    private function fromBrowser(Request $request, array $available): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(substr($language, 0, 2));
            if (in_array($code, $available, true)) {
                return $code;
            }
        }

        return null;
    }
}
