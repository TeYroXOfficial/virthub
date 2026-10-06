@extends('layouts.panel')

@section('title', __('Tłumaczenia — :name', ['name' => $language->name]))

@php
    $enc = fn (string $key) => rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    $pct = $progress['total'] ? (int) floor($progress['done'] * 100 / $progress['total']) : 0;
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.languages') }}" style="font-size:13px">{{ __('← Języki') }}</a>
            <h1>{{ __('Tłumaczenia — :name', ['name' => $language->name]) }} <span class="mono muted">{{ $language->code }}</span></h1>
            <div class="meta-line"><span>{{ __(':done z :total (:pct%)', ['done' => $progress['done'], 'total' => $progress['total'], 'pct' => $pct]) }}</span></div>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.admin.languages.export', $language) }}"><x-icon name="download" :size="15"/> {{ __('Eksport JSON') }}</a>
        </div>
    </div>
    @error('t') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('file') <div class="alert alert-error">{{ $message }}</div> @enderror

    <nav class="tabs" style="margin-bottom:12px">
        <a href="{{ route('panel.admin.languages.translations', [$language, 'section' => 'ui']) }}" @if ($section === 'ui') aria-current="page" @endif>{{ __('Interfejs') }}</a>
        <a href="{{ route('panel.admin.languages.translations', [$language, 'section' => 'system']) }}" @if ($section === 'system') aria-current="page" @endif>{{ __('Komunikaty systemowe') }}</a>
    </nav>

    <form method="GET" class="filter-bar" style="margin-bottom:12px">
        <input type="hidden" name="section" value="{{ $section }}">
        <input name="q" value="{{ $q }}" placeholder="{{ __('Szukaj w tekstach') }}" aria-label="{{ __('Szukaj w tekstach') }}" style="max-width:320px">
        <select name="filter" aria-label="{{ __('Filtr') }}">
            <option value="all" @selected($filter === 'all')>{{ __('wszystkie') }}</option>
            <option value="missing" @selected($filter === 'missing')>{{ __('bez tłumaczenia') }}</option>
            <option value="custom" @selected($filter === 'custom')>{{ __('zmienione w panelu') }}</option>
        </select>
        <button class="btn" type="submit">{{ __('Filtruj') }}</button>
        <span class="hint">{{ trans_choice(':count tekst|:count teksty|:count tekstów', $rows->total()) }}</span>
    </form>

    <form method="POST" action="{{ route('panel.admin.languages.translations.save', $language) }}">
        @csrf @method('PUT')
        <div class="card flush">
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:40%">{{ __('Tekst źródłowy') }}</th><th>{{ __('Tłumaczenie') }}</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <div style="white-space:pre-wrap">{{ $row['source'] }}</div>
                                @if ($section === 'system') <div class="hint mono">{{ $row['key'] }}</div> @endif
                                @if ($row['reference'] && $row['reference'] !== $row['source'] && $language->code !== 'en')
                                    <div class="hint">EN: {{ $row['reference'] }}</div>
                                @endif
                            </td>
                            <td>
                                @php $long = mb_strlen($row['source']) > 70; @endphp
                                @if ($long)
                                    <textarea name="t[{{ $enc($row['key']) }}]" rows="2" placeholder="{{ $row['reference'] ?? $row['source'] }}" aria-label="{{ __('Tłumaczenie') }}">{{ old('t.'.$enc($row['key']), $row['value']) }}</textarea>
                                @else
                                    <input name="t[{{ $enc($row['key']) }}]" value="{{ old('t.'.$enc($row['key']), $row['value']) }}" placeholder="{{ $row['reference'] ?? $row['source'] }}" aria-label="{{ __('Tłumaczenie') }}">
                                @endif
                                @if ($row['custom']) <span class="pill info plain">{{ __('zmienione w panelu') }}</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="muted" style="text-align:center; padding:24px">{{ __('Brak tekstów dla tego filtra.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="btn-row" style="margin-top:12px; justify-content:space-between">
            <button class="btn btn-primary" type="submit">{{ __('Zapisz tę stronę') }}</button>
            {{ $rows->links() }}
        </div>
        <p class="hint">{{ __('Puste pole = tekst z języka bazowego. Zmienne w rodzaju :name muszą zostać w tłumaczeniu. Formy liczby mnogiej oddziel znakiem | (np. „:count plik|:count pliki|:count plików”).') }}</p>
    </form>

    <div class="card" style="margin-top:16px">
        <h3 class="card-title"><x-icon name="upload" :size="16"/> {{ __('Import pliku JSON') }}</h3>
        <form method="POST" action="{{ route('panel.admin.languages.import', $language) }}" enctype="multipart/form-data" class="filter-bar">
            @csrf
            <input type="file" name="file" accept=".json,application/json" required aria-label="{{ __('Plik JSON') }}">
            <button class="btn" type="submit">{{ __('Importuj') }}</button>
        </form>
        <p class="hint">{{ __('Format jak w eksporcie: obiekt „tekst źródłowy → tłumaczenie”. Importowane są tylko znane teksty; puste wartości usuwają tłumaczenie.') }}</p>
    </div>
@endsection
