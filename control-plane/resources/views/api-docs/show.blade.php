@extends('layouts.panel')

@section('title', __('Dokumentacja API'))
@section('wide', '1')

@php
    $languages = config('virthub.locales', []);
    $lang = app()->getLocale();
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Dokumentacja API') }}</h1>
            <p class="lede">{{ __('Opis wszystkich endpointów z przykładami w kilku językach programowania i konsolą do wysyłania zapytań prosto z tej strony.') }}</p>
        </div>
        <div class="btn-row">
            <a class="btn" href="{{ route('api-docs.openapi', ['lang' => $lang]) }}" download>{{ __('OpenAPI (JSON)') }}</a>
            <a class="btn" href="{{ route('api-docs.postman', ['lang' => $lang]) }}">{{ __('Kolekcja Postman') }}</a>
        </div>
    </div>

    <div class="api-layout">
        <nav class="api-nav card" aria-label="{{ __('Endpointy') }}">
            <input type="search" placeholder="{{ __('Szukaj endpointu…') }}" data-api-filter aria-label="{{ __('Szukaj endpointu') }}">
            <a href="#intro"><strong>{{ __('Wprowadzenie') }}</strong></a>
            <a href="#console"><strong>{{ __('Konsola') }}</strong></a>
            @foreach ($endpoints as $group => $items)
                <h4>{{ $groups[$group]['name'] }}</h4>
                @foreach ($items as $e)
                    <a href="#{{ $e['id'] }}" data-api-link="{{ strtolower($e['method'].' '.$e['path'].' '.$e['summary']) }}">
                        <span class="method {{ strtolower($e['method']) }}">{{ $e['method'] }}</span> <span>{{ $e['summary'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div>
            <section class="card" id="intro">
                <h2 class="card-title"><x-icon name="key" :size="16"/> {{ __('Wprowadzenie') }}</h2>
                <p>{{ $introduction }}</p>
                <dl class="kv">
                    <dt>{{ __('Adres bazowy') }}</dt><dd><code>{{ $baseUrl }}</code></dd>
                    <dt>{{ __('Uwierzytelnienie') }}</dt><dd><code>Authorization: Bearer &lt;token&gt;</code>
                        @auth <a href="{{ route('panel.account.security') }}">{{ __('Utwórz token') }}</a> @endauth</dd>
                    <dt>{{ __('Język dokumentacji') }}</dt>
                    <dd>@foreach ($languages as $code => $name)
                            <a href="{{ route('api-docs', ['lang' => $code]) }}" @if ($code === $lang) style="font-weight:700" @endif>{{ $name }}</a>@if (! $loop->last) · @endif
                        @endforeach</dd>
                </dl>
                <h3>{{ __('Kody odpowiedzi') }}</h3>
                <table class="api-params">
                    <tbody>
                    <tr><td><code>200 / 201</code></td><td>{{ __('Sukces (201 — obiekt utworzony).') }}</td></tr>
                    <tr><td><code>202</code></td><td>{{ __('Zlecenie przyjęte i wykonywane w tle — job_id w odpowiedzi.') }}</td></tr>
                    @foreach ($httpErrors as $code => $text)
                        <tr><td><code>{{ $code }}</code></td><td>{{ $text }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                <h3>{{ __('Import do Postmana i innych narzędzi') }}</h3>
                <p class="muted">{{ __('Postman: Import → Link i wklej adres kolekcji albo pliku OpenAPI. Insomnia, Bruno, Hoppscotch i generatory klientów (openapi-generator) czytają plik OpenAPI. Zmienne kolekcji: baseUrl i token.') }}</p>
                <pre class="secret" style="white-space:pre-wrap; font-size:12px">{{ route('api-docs.postman', ['lang' => $lang]) }}
{{ route('api-docs.openapi', ['lang' => $lang]) }}</pre>
            </section>

            <section class="card api-console" id="console">
                <h2 class="card-title"><x-icon name="terminal" :size="16"/> {{ __('Konsola') }}</h2>
                <div class="grid grid-2">
                    <div class="field"><label for="api-token">{{ __('Token API') }}</label>
                        <input id="api-token" type="password" autocomplete="off" placeholder="1|abc…" data-api-token>
                        <label class="check-line"><input type="checkbox" data-api-remember> {{ __('Zapamiętaj token w tej przeglądarce') }}</label></div>
                    <div class="field"><label for="api-base">{{ __('Adres bazowy') }}</label>
                        <input id="api-base" type="url" value="{{ $baseUrl }}" data-api-base></div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end">
                    <div class="field" style="margin:0"><label for="api-method">{{ __('Metoda') }}</label>
                        <select id="api-method" data-api-method>@foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) <option>{{ $m }}</option> @endforeach</select></div>
                    <div class="field" style="margin:0; flex:1; min-width:240px"><label for="api-path">{{ __('Ścieżka') }}</label>
                        <input id="api-path" value="/me" data-api-path></div>
                    <button class="btn btn-primary" type="button" data-api-send>{{ __('Wyślij') }}</button>
                </div>
                <div class="field" style="margin-top:10px"><label for="api-body">{{ __('Treść (JSON)') }}</label>
                    <textarea id="api-body" rows="6" spellcheck="false" data-api-body></textarea></div>
                <div class="api-response-meta" data-api-meta hidden></div>
                <div class="api-tabs" role="tablist">
                    <button type="button" role="tab" aria-selected="true" data-api-rtab="body">{{ __('Treść odpowiedzi') }}</button>
                    <button type="button" role="tab" aria-selected="false" data-api-rtab="headers">{{ __('Nagłówki') }}</button>
                    <button type="button" role="tab" aria-selected="false" data-api-rtab="curl">curl</button>
                    <button type="button" role="tab" aria-selected="false" data-api-rtab="history">{{ __('Historia') }}</button>
                </div>
                <div class="api-code"><pre data-api-output>{{ __('Wybierz endpoint („Wypróbuj”) albo wpisz ścieżkę i kliknij Wyślij.') }}</pre></div>
                <p class="hint">{{ __('Zapytania idą prosto z Twojej przeglądarki do API — na Twoim koncie. Operacje zmieniające (POST, PUT, DELETE) naprawdę wykonują się na maszynach.') }}</p>
            </section>

            @foreach ($endpoints as $group => $items)
                <h2 style="margin-top:28px">{{ $groups[$group]['name'] }}</h2>
                <p class="muted">{{ $groups[$group]['description'] }}</p>
                @foreach ($items as $e)
                    <section class="card api-endpoint" id="{{ $e['id'] }}" data-api-endpoint="{{ $e['id'] }}">
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                            <span class="method {{ strtolower($e['method']) }}">{{ $e['method'] }}</span>
                            <span class="path">{{ $e['path'] }}</span>
                            @if ($e['scope']) <span class="pill neutral plain" title="{{ __('Wymagany zakres tokenu') }}">{{ $e['scope'] }}</span> @endif
                            <button class="btn btn-sm" type="button" style="margin-left:auto" data-api-try="{{ $e['id'] }}">{{ __('Wypróbuj') }}</button>
                        </div>
                        <h3 style="margin:10px 0 4px">{{ $e['summary'] }}</h3>
                        <p>{{ $e['description'] }}</p>

                        @php $params = collect($e['path_params'])->map(fn ($p) => $p + ['in' => 'path'])->concat(collect($e['query'])->map(fn ($p) => $p + ['in' => 'query']))->concat(collect($e['body'])->map(fn ($p) => $p + ['in' => 'body'])); @endphp
                        @if ($params->isNotEmpty())
                            <div class="table-wrap">
                                <table class="api-params">
                                    <thead><tr><th>{{ __('Parametr') }}</th><th>{{ __('Gdzie') }}</th><th>{{ __('Typ') }}</th><th>{{ __('Opis') }}</th></tr></thead>
                                    <tbody>
                                    @foreach ($params as $p)
                                        <tr>
                                            <td><code>{{ $p['name'] }}</code> @if ($p['required']) <span class="pill warning plain">{{ __('wymagany') }}</span> @endif</td>
                                            <td>{{ ['path' => __('ścieżka'), 'query' => __('zapytanie'), 'body' => __('treść')][$p['in']] }}</td>
                                            <td><code>{{ $p['type'] }}</code></td>
                                            <td>{{ $p['description'] }}
                                                @if (! empty($p['enum'])) <div class="hint">{{ implode(' | ', $p['enum']) }}</div> @endif</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="grid grid-2" style="margin-top:8px">
                            <div>
                                <div class="api-tabs" role="tablist" data-api-langs="{{ $e['id'] }}">
                                    @foreach (['curl' => 'curl', 'php' => 'PHP', 'python' => 'Python', 'js' => 'JavaScript', 'go' => 'Go'] as $key => $label)
                                        <button type="button" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-lang="{{ $key }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                                <div class="api-code"><button class="btn btn-sm copy" type="button" data-api-copy>{{ __('Kopiuj') }}</button><pre data-api-sample="{{ $e['id'] }}"></pre></div>
                            </div>
                            <div>
                                <div class="api-tabs"><button type="button" aria-selected="true">{{ __('Odpowiedź :status', ['status' => $e['status']]) }}</button></div>
                                <div class="api-code"><pre>{{ json_encode($e['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
                            </div>
                        </div>
                    </section>
                @endforeach
            @endforeach
        </div>
    </div>

    <script>
        window.VH_API = {
            catalog: @json($catalog),
            baseUrl: @json($baseUrl),
            t: {
                sending: @js(__('Wysyłam…')),
                networkError: @js(__('Błąd sieci: :error')),
                noToken: @js(__('Wpisz token API — bez niego API odpowie 401.')),
                badJson: @js(__('Treść nie jest poprawnym JSON-em.')),
                copied: @js(__('Skopiowano')),
                emptyHistory: @js(__('Brak zapytań w historii.')),
                time: @js(__('czas')),
                size: @js(__('rozmiar')),
            },
        };
    </script>
    @push('scripts')
        <script src="{{ asset('js/api-docs.js') }}?v={{ @filemtime(public_path('js/api-docs.js')) }}"></script>
    @endpush
@endsection
