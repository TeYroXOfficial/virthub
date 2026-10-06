@extends('layouts.panel')

@section('title', __('Baza :name', ['name' => $database->database]))

@section('content')
    @include('panel.apps._header')
    @error('database') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('import') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="db-layout">
        @include('panel.apps._db-sidebar')

        <div>
            <div class="card">
                <div class="dash-head" style="padding:0 0 10px">
                    <h3 class="card-title" style="margin:0"><x-icon name="terminal" :size="16"/> {{ __('Konsola SQL') }}</h3>
                    <a class="btn btn-sm btn-ghost" href="{{ route('panel.apps.databases', $app) }}"><x-icon name="arrow-left" :size="14"/> {{ __('Bazy danych') }}</a>
                </div>
                <form method="POST" action="{{ route('panel.apps.databases.query', [$app, $database]) }}">
                    @csrf
                    <textarea class="sql-editor" name="sql" spellcheck="false" aria-label="{{ __('Zapytanie SQL') }}" placeholder="SELECT * FROM players LIMIT 10;">{{ old('sql', $sql) }}</textarea>
                    @error('sql') <div class="alert alert-error" style="margin-top:8px; white-space:pre-line">{{ $message }}</div> @enderror
                    <div class="btn-row" style="margin-top:8px">
                        <button class="btn btn-primary" type="submit"><x-icon name="play" :size="14"/> {{ __('Wykonaj') }}</button>
                        <span class="hint">{{ __('Ctrl+Enter. Kilka zapytań oddziel średnikiem. Limit czasu: 15 s, wynik do 500 wierszy.') }}</span>
                    </div>
                </form>
            </div>

            @if ($result)
                <div class="card flush" style="margin-top:16px">
                    <div class="dash-head">
                        <h3 class="card-title" style="margin:0">{{ __('Wynik') }}</h3>
                        <span class="hint">
                            {{ trans_choice(':count zapytanie|:count zapytania|:count zapytań', $result['statements']) }} · {{ $result['ms'] }} ms
                            @if ($result['columns']) · {{ trans_choice(':count wiersz|:count wiersze|:count wierszy', count($result['rows'])) }}@endif
                            @if ($result['affected']) · {{ __('zmienione wiersze: :n', ['n' => $result['affected']]) }}@endif
                        </span>
                    </div>
                    @if ($result['truncated'])
                        <div class="alert alert-info" style="margin:0 16px 12px">{{ __('Pokazano pierwsze 500 wierszy — dodaj LIMIT, żeby zawęzić wynik.') }}</div>
                    @endif
                    @if ($result['columns'])
                        @include('panel.apps._db-grid', ['columns' => $result['columns'], 'rows' => $result['rows'], 'head' => fn ($c) => e($c)])
                    @else
                        <p class="muted" style="padding:0 20px 16px; margin:0">{{ __('Wykonano. Zmienione wiersze: :n.', ['n' => $result['affected']]) }}</p>
                    @endif
                </div>
            @endif

            <div class="grid grid-2" style="margin-top:16px">
                <div class="card">
                    <h3 class="card-title"><x-icon name="download" :size="16"/> {{ __('Eksport') }}</h3>
                    <p class="muted">{{ __('Plik .sql ze strukturą i danymi wszystkich tabel.') }}</p>
                    <a class="btn" href="{{ route('panel.apps.databases.export', [$app, $database]) }}">{{ __('Pobierz zrzut') }}</a>
                </div>
                <div class="card">
                    <h3 class="card-title"><x-icon name="upload" :size="16"/> {{ __('Import') }}</h3>
                    <form method="POST" action="{{ route('panel.apps.databases.import', [$app, $database]) }}" enctype="multipart/form-data"
                          data-confirm="{{ __('Zaimportować plik? Zapytania z pliku (np. DROP TABLE) mogą nadpisać istniejące dane.') }}">
                        @csrf
                        <div class="field"><input type="file" name="file" accept=".sql,.txt" required aria-label="{{ __('Plik .sql') }}"></div>
                        @error('file') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        <button class="btn" type="submit">{{ __('Importuj') }}</button>
                        <p class="hint">{{ __('Do 50 MB. Polecenia USE i DELIMITER są pomijane — dane trafiają zawsze do tej bazy.') }}</p>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelector('.sql-editor')?.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); e.target.form.requestSubmit(); }
        });
    </script>
@endpush
