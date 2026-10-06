@extends('layouts.panel')

@section('title', __('Tabela :table', ['table' => $table]))

@php
    $sortLink = function ($col) use ($app, $database, $table, $sort, $dir) {
        $next = $sort === $col && $dir === 'asc' ? 'desc' : 'asc';
        $url = route('panel.apps.databases.table', [$app, $database, $table, 'sort' => $col, 'dir' => $next]);
        $mark = $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';

        return '<a href="'.e($url).'" style="color:inherit">'.e($col).$mark.'</a>';
    };
    $pageUrl = fn ($p) => route('panel.apps.databases.table', array_filter([$app, $database, $table, 'page' => $p, 'sort' => $sort, 'dir' => $sort ? $dir : null]));
@endphp

@section('content')
    @include('panel.apps._header')

    <div class="db-layout">
        @include('panel.apps._db-sidebar')

        <div>
            <div class="card flush">
                <div class="dash-head">
                    <h3 class="card-title" style="margin:0"><x-icon name="table" :size="16"/> <span class="mono">{{ $table }}</span>
                        <span class="pill neutral plain">{{ trans_choice(':count wiersz|:count wiersze|:count wierszy', $data['total']) }}</span></h3>
                    <a class="btn btn-sm btn-ghost" href="{{ route('panel.apps.databases.browse', [$app, $database]) }}"><x-icon name="terminal" :size="14"/> {{ __('Konsola SQL') }}</a>
                </div>
                @include('panel.apps._db-grid', ['columns' => $data['columns'], 'rows' => $data['rows'], 'head' => $sortLink])
                @if ($pages > 1)
                    <div class="btn-row" style="padding:12px 16px; margin:0; justify-content:space-between">
                        <span class="hint">{{ __('Strona :page z :pages', ['page' => $pageNo, 'pages' => $pages]) }}</span>
                        <span class="btn-row" style="margin:0">
                            @if ($pageNo > 1)<a class="btn btn-sm" href="{{ $pageUrl($pageNo - 1) }}">{{ __('Poprzednia') }}</a>@endif
                            @if ($pageNo < $pages)<a class="btn btn-sm" href="{{ $pageUrl($pageNo + 1) }}">{{ __('Następna') }}</a>@endif
                        </span>
                    </div>
                @endif
            </div>

            <div class="card flush" style="margin-top:16px">
                <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Struktura') }}</h3></div>
                <div class="table-wrap">
                    <table class="db-grid">
                        <thead><tr><th>{{ __('Kolumna') }}</th><th>{{ __('Typ') }}</th><th>NULL</th><th>{{ __('Klucz') }}</th><th>{{ __('Domyślnie') }}</th><th>{{ __('Dodatkowe') }}</th></tr></thead>
                        <tbody>
                        @foreach ($columns as $c)
                            <tr><td><strong>{{ $c['Field'] }}</strong></td><td>{{ $c['Type'] }}</td><td>{{ $c['Null'] }}</td><td>{{ $c['Key'] }}</td>
                                <td @class(['null' => $c['Default'] === null])>{{ $c['Default'] ?? 'NULL' }}</td><td>{{ $c['Extra'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
