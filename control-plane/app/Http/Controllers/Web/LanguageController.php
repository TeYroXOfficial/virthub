<?php

namespace App\Http\Controllers\Web;

use App\Domain\Settings\Languages;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Administracja → System → Języki (tylko administrator). */
class LanguageController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly Languages $languages) {}

    public function index(): View
    {
        $list = Language::query()->orderBy('sort_order')->orderBy('code')->get();

        return view('panel.admin.languages.index', [
            'languages' => $list,
            'default' => $this->languages->default(),
            'detectBrowser' => Setting::get('locale.detect_browser', '1') === '1',
            'progress' => $list->mapWithKeys(fn (Language $l) => [$l->code => $this->languages->progress($l->code)]),
            'users' => User::query()->selectRaw('locale, count(*) as c')->groupBy('locale')->pluck('c', 'locale'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', Rule::unique('languages', 'code')],
            'name' => ['required', 'string', 'max:60'],
            'base' => ['required', Rule::in(Languages::BUILTIN)],
        ], ['code.regex' => __('Kod języka w formacie ISO, np. de, uk, pt-BR.')]);
        $language = $this->languages->create($data['code'], $data['name'], $data['base'], $request->user());

        return redirect()->route('panel.admin.languages.translations', $language)
            ->with('status', __('Język :name dodany (wyłączony). Przetłumacz teksty i włącz go na liście języków.', ['name' => $language->name]));
    }

    public function update(Request $request, Language $language): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'base' => ['nullable', Rule::in(Languages::BUILTIN)],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ]);
        try {
            $this->languages->update($language, array_filter([
                'name' => $data['name'],
                'base' => $language->isBuiltin() ? null : ($data['base'] ?? null),
                'sort_order' => $data['sort_order'] ?? null,
                'is_enabled' => $request->boolean('is_enabled'),
            ], fn ($v) => $v !== null), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['language' => $e->getMessage()]);
        }

        return back()->with('status', __('Język :name zapisany.', ['name' => $language->name]));
    }

    public function setDefault(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', Rule::exists('languages', 'code')]]);
        $this->languages->setDefault($data['code'], $request->user());
        if ($request->has('detect_browser')) {
            Setting::put(['locale.detect_browser' => $request->boolean('detect_browser') ? '1' : '0']);
        }

        return back()->with('status', __('Domyślny język zmieniony. Obowiązuje dla gości i kont bez wybranego języka.'));
    }

    public function destroy(Request $request, Language $language): RedirectResponse
    {
        try {
            $this->languages->delete($language, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['language' => $e->getMessage()]);
        }

        return redirect()->route('panel.admin.languages')->with('status', __('Język usunięty.'));
    }

    public function translations(Request $request, Language $language): View
    {
        $source = $this->languages->sourceStrings();
        $overrides = $this->languages->overrides($language->code);
        $builtin = $language->isBuiltin();
        $q = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'all');
        $section = (string) $request->query('section', 'ui');

        $rows = [];
        foreach ($source as $key => $text) {
            $isSystem = $this->languages->groupOf($key) !== null;
            if (($section === 'system') !== $isSystem) {
                continue;
            }
            $value = $overrides[$key] ?? ($builtin ? $this->languages->builtinValue($language->code, $key) : null);
            $reference = $this->languages->builtinValue($language->isBuiltin() ? 'en' : $language->base, $key);
            if ($filter === 'missing' && $value !== null && $value !== '') {
                continue;
            }
            if ($filter === 'custom' && ! isset($overrides[$key])) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($key.' '.$text.' '.$value.' '.$reference), mb_strtolower($q))) {
                continue;
            }
            $rows[] = ['key' => $key, 'source' => $text, 'reference' => $reference, 'value' => $value, 'custom' => isset($overrides[$key])];
        }
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('panel.admin.languages.translations', [
            'language' => $language,
            'rows' => $paginator,
            'q' => $q, 'filter' => $filter, 'section' => $section,
            'progress' => $this->languages->progress($language->code),
        ]);
    }

    public function saveTranslations(Request $request, Language $language): RedirectResponse
    {
        $request->validate(['t' => ['array'], 't.*' => ['nullable', 'string', 'max:5000']]);
        $values = [];
        foreach ((array) $request->input('t', []) as $encoded => $value) {
            $key = base64_decode(strtr((string) $encoded, '-_', '+/'), true);
            if ($key !== false) {
                $values[$key] = $value;
            }
        }
        $errors = $this->placeholderErrors($values);
        if ($errors !== []) {
            return back()->withInput()->withErrors(['t' => __('Tłumaczenie musi zawierać te same zmienne co tekst źródłowy (np. :name). Popraw: :keys', ['keys' => implode('; ', array_slice($errors, 0, 5))])]);
        }
        $changed = $this->languages->save($language->code, $values, $request->user());

        return back()->with('status', trans_choice('Zapisano :count zmianę.|Zapisano :count zmiany.|Zapisano :count zmian.', $changed));
    }

    public function export(Language $language): StreamedResponse
    {
        $data = $this->languages->export($language->code);

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'virthub-'.$language->code.'.json', ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function import(Request $request, Language $language): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:4096']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data) || array_filter($data, fn ($v, $k) => ! is_string($k) || ! (is_string($v) || $v === null), ARRAY_FILTER_USE_BOTH) !== []) {
            return back()->withErrors(['file' => __('Plik musi być obiektem JSON: tekst źródłowy → tłumaczenie.')]);
        }
        $errors = $this->placeholderErrors($data);
        if ($errors !== []) {
            return back()->withErrors(['file' => __('Tłumaczenie musi zawierać te same zmienne co tekst źródłowy (np. :name). Popraw: :keys', ['keys' => implode('; ', array_slice($errors, 0, 5))])]);
        }
        $changed = $this->languages->save($language->code, $data, $request->user());

        return back()->with('status', trans_choice('Zapisano :count zmianę.|Zapisano :count zmiany.|Zapisano :count zmian.', $changed));
    }

    /** Zmienne (:name) w tłumaczeniu muszą być te same co w źródle — inaczej tekst się rozjedzie. */
    private function placeholderErrors(array $values): array
    {
        $source = $this->languages->sourceStrings();
        $errors = [];
        foreach ($values as $key => $value) {
            if (! is_string($value) || trim($value) === '' || ! isset($source[$key])) {
                continue;
            }
            preg_match_all('/:[a-z_]+/', $source[$key], $a);
            preg_match_all('/:[a-z_]+/', $value, $b);
            if (array_diff(array_unique($a[0]), $b[0]) !== [] || array_diff(array_unique($b[0]), $a[0]) !== []) {
                $errors[] = mb_strimwidth($source[$key], 0, 60, '…');
            }
        }

        return $errors;
    }
}
