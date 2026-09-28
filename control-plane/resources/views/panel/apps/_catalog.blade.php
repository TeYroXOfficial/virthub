{{-- Katalog modpacków/pluginów/modów: źródła, wyszukiwarka, wyniki i strony.
     Parametry: $route (lista), $showRoute (szczegóły), $sources, $source, $query, $page, $results, $error, $placeholder. --}}
@php
    $fmt = fn (int $n) => $n >= 1000000 ? round($n / 1000000, 1).' mln' : ($n >= 1000 ? round($n / 1000).' tys.' : (string) $n);
    $perPage = 20;
    $pages = $results ? (int) min(50, ceil(($results['total'] ?? 0) / $perPage)) : 0;
@endphp

<form method="GET" action="{{ route($route, $app) }}" class="catalog-search">
    <div class="segmented" role="tablist" aria-label="{{ __('Źródło') }}">
        @foreach ($sources as $key => $name)
            <a href="{{ route($route, [$app, 'source' => $key, 'q' => $query]) }}" @if ($key === $source) aria-current="page" @endif>{{ $name }}</a>
        @endforeach
    </div>
    <input type="hidden" name="source" value="{{ $source }}">
    <input type="search" name="q" value="{{ $query }}" maxlength="100" placeholder="{{ $placeholder }}" aria-label="{{ __('Szukaj') }}">
    <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Szukaj') }}</button>
</form>

@if ($error)
    <div class="alert alert-error">{{ $error }}</div>
@elseif ($results && $results['items'] === [])
    <div class="card empty">{{ __('Nic nie znaleziono.') }}</div>
@elseif ($results)
    <div class="catalog-grid">
        @foreach ($results['items'] as $item)
            <a class="catalog-card" href="{{ route($showRoute, [$app, $item['source'], $item['id']]) }}">
                @if ($item['icon'])
                    <img src="{{ $item['icon'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                @else
                    <span class="catalog-icon"><x-icon name="package" :size="22"/></span>
                @endif
                <span class="catalog-body">
                    <strong>{{ $item['name'] }}</strong>
                    <span class="muted catalog-meta">
                        @if ($item['author']) {{ $item['author'] }} · @endif
                        <x-icon name="download" :size="12"/> {{ $fmt($item['downloads']) }}
                    </span>
                    <span class="catalog-summary">{{ \Illuminate\Support\Str::limit($item['summary'], 140) }}</span>
                </span>
            </a>
        @endforeach
    </div>
    @if ($pages > 1)
        <nav class="pager" aria-label="{{ __('Strony') }}">
            @if ($page > 1)
                <a class="btn btn-sm" href="{{ route($route, [$app, 'source' => $source, 'q' => $query, 'page' => $page - 1]) }}">← {{ __('Poprzednia') }}</a>
            @endif
            <span class="muted">{{ __('Strona :page z :pages', ['page' => $page, 'pages' => $pages]) }}</span>
            @if ($page < $pages)
                <a class="btn btn-sm" href="{{ route($route, [$app, 'source' => $source, 'q' => $query, 'page' => $page + 1]) }}">{{ __('Następna') }} →</a>
            @endif
        </nav>
    @endif
@endif
