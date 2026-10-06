<?php

namespace App\Domain\Settings;

use App\Models\AuditLog;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Support\TranslationLoader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Języki panelu: domyślny, włączone, dodane przez administratora i ich
 * tłumaczenia.
 *
 * Teksty w kodzie są po polsku (klucze), angielski jest w lang/en.json i
 * lang/en/*.php. Tłumaczenia z panelu (nowe języki i poprawki wbudowanych)
 * trafiają do storage/app/lang — loader nakłada je na pliki z repo.
 */
class Languages
{
    public const BUILTIN = ['pl', 'en'];

    public const SOURCE = 'pl';

    /** Grupy komunikatów systemowych (walidacja, logowanie…) — też do przetłumaczenia. */
    public const GROUPS = ['validation', 'auth', 'passwords', 'pagination'];

    private const CACHE = 'virthub.languages';

    public function overridePath(?string $file = null): string
    {
        return storage_path('app/lang').($file ? '/'.$file : '');
    }

    /** @return list<array{code:string, name:string, base:string, enabled:bool}> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE, fn () => Language::query()->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn (Language $l) => ['code' => $l->code, 'name' => $l->name, 'base' => $l->base, 'enabled' => $l->is_enabled])->all());
    }

    /** @return array<string, string> kod → nazwa włączonych */
    public function enabled(): array
    {
        return collect($this->all())->where('enabled', true)->pluck('name', 'code')->all();
    }

    public function default(): string
    {
        $enabled = $this->enabled();
        $default = Setting::get('locale.default') ?? (string) config('virthub.default_locale', self::SOURCE);

        return isset($enabled[$default]) ? $default : (array_key_first($enabled) ?? self::SOURCE);
    }

    /** Przy starcie: języki i domyślny z bazy zamiast z config; język bazowy dla nowych. */
    public function apply(): void
    {
        $all = $this->all();
        if ($all === []) {
            return;
        }
        config(['virthub.locales' => $this->enabled() ?: ['pl' => 'Polski'], 'virthub.default_locale' => $this->default(), 'app.locale' => $this->default()]);
        $loader = app('translation.loader');
        if ($loader instanceof TranslationLoader) {
            $loader->setBases(collect($all)->reject(fn ($l) => in_array($l['code'], self::BUILTIN, true))->pluck('base', 'code')->all());
        }
        app()->setLocale($this->default());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
        app('translator')->setLoaded([]);
    }

    // --- zarządzanie --------------------------------------------------------------------

    public function create(string $code, string $name, string $base, ?User $actor = null): Language
    {
        $language = Language::query()->create([
            'code' => $code, 'name' => $name, 'base' => in_array($base, self::BUILTIN, true) ? $base : 'en',
            'is_enabled' => false, 'sort_order' => (int) Language::query()->max('sort_order') + 1,
        ]);
        $this->forget();
        AuditLog::record('language.created', $language, ['code' => $code, 'base' => $base], $actor);

        return $language;
    }

    public function update(Language $language, array $data, ?User $actor = null): void
    {
        if ($language->code === $this->default() && array_key_exists('is_enabled', $data) && ! $data['is_enabled']) {
            throw new \DomainException(__('Nie można wyłączyć domyślnego języka — najpierw wybierz inny domyślny.'));
        }
        $language->update($data);
        $this->forget();
        AuditLog::record('language.updated', $language, $data, $actor);
    }

    public function setDefault(string $code, ?User $actor = null): void
    {
        $language = Language::query()->where('code', $code)->firstOrFail();
        if (! $language->is_enabled) {
            $language->update(['is_enabled' => true]);
        }
        Setting::put(['locale.default' => $code]);
        $this->forget();
        AuditLog::record('language.default', $language, ['code' => $code], $actor);
    }

    public function delete(Language $language, ?User $actor = null): void
    {
        if ($language->isBuiltin()) {
            throw new \DomainException(__('Wbudowanego języka nie można usunąć — możesz go wyłączyć.'));
        }
        if ($language->code === $this->default()) {
            throw new \DomainException(__('Nie można usunąć domyślnego języka.'));
        }
        File::delete($this->overridePath($language->code.'.json'));
        File::deleteDirectory($this->overridePath($language->code));
        User::query()->where('locale', $language->code)->update(['locale' => null]);
        AuditLog::record('language.deleted', null, ['code' => $language->code], $actor);
        $language->delete();
        $this->forget();
    }

    // --- tłumaczenia --------------------------------------------------------------------

    /**
     * Wszystkie teksty do tłumaczenia: klucz → tekst źródłowy (polski).
     * Teksty interfejsu to klucze lang/en.json (test pilnuje, że jest tam każdy
     * tekst z kodu), komunikaty systemowe — spłaszczone grupy, np. „validation.required”.
     *
     * @return array<string, string>
     */
    public function sourceStrings(): array
    {
        $keys = array_keys($this->builtinJson('en'));
        $strings = array_combine($keys, $keys);
        foreach (self::GROUPS as $group) {
            foreach (Arr::dot($this->builtinGroup(self::SOURCE, $group) ?: $this->builtinGroup('en', $group)) as $key => $value) {
                if (is_string($value)) {
                    $strings[$group.'.'.$key] = $value;
                }
            }
        }

        return $strings;
    }

    /** Tekst w języku wbudowanym (z repo, bez nadpisań). */
    public function builtinValue(string $code, string $key): ?string
    {
        if ($group = $this->groupOf($key)) {
            $value = Arr::get($this->builtinGroup($code, $group), substr($key, strlen($group) + 1));

            return is_string($value) ? $value : null;
        }
        if ($code === self::SOURCE) {
            return $key;
        }

        return $this->builtinJson($code)[$key] ?? null;
    }

    /** @return array<string, string> nadpisania administratora dla języka (klucz → tekst) */
    public function overrides(string $code): array
    {
        $json = json_decode((string) @file_get_contents($this->overridePath($code.'.json')), true);
        $out = is_array($json) ? array_filter($json, 'is_string') : [];
        foreach (self::GROUPS as $group) {
            $file = $this->overridePath($code.'/'.$group.'.php');
            if (is_file($file)) {
                try {
                    $data = require $file;
                } catch (Throwable) {
                    $data = [];
                }
                foreach (Arr::dot(is_array($data) ? $data : []) as $key => $value) {
                    if (is_string($value)) {
                        $out[$group.'.'.$key] = $value;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Zapisuje tłumaczenia (tylko podane klucze). Pusty tekst albo równy
     * wbudowanemu = brak nadpisania.
     *
     * @param  array<string, ?string>  $values
     */
    public function save(string $code, array $values, ?User $actor = null): int
    {
        $known = $this->sourceStrings();
        $current = $this->overrides($code);
        $changed = 0;
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $known)) {
                continue;
            }
            $value = $value === null ? '' : trim(str_replace("\r\n", "\n", (string) $value));
            $builtin = in_array($code, self::BUILTIN, true) ? $this->builtinValue($code, $key) : null;
            if ($value === '' || $value === $builtin) {
                if (isset($current[$key])) {
                    unset($current[$key]);
                    $changed++;
                }
            } elseif (($current[$key] ?? null) !== $value) {
                $current[$key] = $value;
                $changed++;
            }
        }
        $this->write($code, $current);
        AuditLog::record('language.translations', null, ['code' => $code, 'changed' => $changed], $actor);

        return $changed;
    }

    /** Pełny słownik języka do pobrania (klucz → tekst, z nadpisaniami). @return array<string, string> */
    public function export(string $code): array
    {
        $overrides = $this->overrides($code);
        $out = [];
        foreach (array_keys($this->sourceStrings()) as $key) {
            $value = $overrides[$key] ?? (in_array($code, self::BUILTIN, true) ? $this->builtinValue($code, $key) : null);
            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Ile tekstów ma tłumaczenie w tym języku. @return array{done:int, total:int} */
    public function progress(string $code): array
    {
        $source = $this->sourceStrings();
        if (in_array($code, self::BUILTIN, true)) {
            $done = count(array_filter(array_keys($source), fn ($k) => $this->builtinValue($code, $k) !== null));
        } else {
            $done = count(array_intersect_key($this->overrides($code), $source));
        }

        return ['done' => $done, 'total' => count($source)];
    }

    public function groupOf(string $key): ?string
    {
        foreach (self::GROUPS as $group) {
            if (str_starts_with($key, $group.'.')) {
                return $group;
            }
        }

        return null;
    }

    /** @param  array<string, string>  $values */
    private function write(string $code, array $values): void
    {
        File::ensureDirectoryExists($this->overridePath());
        $json = [];
        $groups = [];
        foreach ($values as $key => $value) {
            if ($group = $this->groupOf($key)) {
                Arr::set($groups[$group], substr($key, strlen($group) + 1), $value);
            } else {
                $json[$key] = $value;
            }
        }
        ksort($json);
        file_put_contents($this->overridePath($code.'.json'), json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (self::GROUPS as $group) {
            $file = $this->overridePath($code.'/'.$group.'.php');
            if (! empty($groups[$group])) {
                File::ensureDirectoryExists(dirname($file));
                file_put_contents($file, "<?php\n\n// Tłumaczenia z panelu (Administracja → Języki).\nreturn ".var_export($groups[$group], true).";\n");
            } else {
                File::delete($file);
            }
        }
        if (function_exists('opcache_invalidate')) {
            foreach (self::GROUPS as $group) {
                @opcache_invalidate($this->overridePath($code.'/'.$group.'.php'), true);
            }
        }
        app('translator')->setLoaded([]);
    }

    /** @return array<string, string> */
    private function builtinJson(string $code): array
    {
        static $cache = [];

        return $cache[$code] ??= (function () use ($code) {
            $data = json_decode((string) @file_get_contents(lang_path($code.'.json')), true);

            return is_array($data) ? $data : [];
        })();
    }

    /** @return array<string, mixed> */
    private function builtinGroup(string $code, string $group): array
    {
        $file = lang_path($code.'/'.$group.'.php');
        if (! is_file($file)) {
            $file = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/'.$code.'/'.$group.'.php');
        }

        return is_file($file) ? (array) require $file : [];
    }
}
