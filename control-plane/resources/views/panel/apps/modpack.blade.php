@extends('layouts.panel')

@section('title', ($project['name'] ?? __('Modpack')).' — '.$app->name)

@section('content')
    @include('panel.apps._header')

    @include('panel.apps._content_ui')
    <p><a href="{{ route('panel.apps.modpacks', [$app, 'source' => $source]) }}"><x-icon name="arrow-left" :size="14"/> {{ __('Wróć do listy') }}</a></p>

    @if ($error)
        <div class="alert alert-error">{{ $error }}</div>
    @endif
    @error('content') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('eula') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($project)
        <div class="card">
            <div class="content-hero">
                @if ($project['icon']) <img src="{{ $project['icon'] }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"> @endif
                <div>
                    <h2 style="margin:0">{{ $project['name'] }}</h2>
                    <span class="muted">{{ $sourceName }}@if ($project['author']) · {{ $project['author'] }}@endif
                        @if ($project['url']) · <a href="{{ $project['url'] }}" target="_blank" rel="noopener">{{ __('strona paczki') }}</a>@endif</span>
                    <p style="margin:6px 0 0">{{ $project['summary'] }}</p>
                </div>
            </div>

            @if ($versions === [])
                <p class="muted" style="margin-top:16px">{{ __('Brak wersji do zainstalowania.') }}</p>
            @else
                <form class="content-form" method="POST" action="{{ route('panel.apps.modpacks.install', $app) }}"
                      onsubmit="return confirm(@js(__('Zainstalować modpack? Mody, konfiguracja i biblioteki obecnego serwera zostaną zastąpione, aplikacja się zatrzyma.')))">
                    @csrf
                    <input type="hidden" name="source" value="{{ $source }}">
                    <input type="hidden" name="project" value="{{ $project['id'] }}">
                    @php $default = collect($versions)->first(fn ($v) => $v['type'] === 'release')['id'] ?? $versions[0]['id']; @endphp
                    <div class="version-list" role="radiogroup" aria-label="{{ __('Wersja') }}">
                        @foreach ($versions as $version)
                            <label>
                                <input type="radio" name="version" value="{{ $version['id'] }}" @checked($version['id'] === $default) required>
                                <span><strong>{{ $version['number'] ?: $version['name'] }}</strong>
                                    @if ($version['date']) <span class="muted">· {{ \Illuminate\Support\Carbon::parse($version['date'])->format('d.m.Y') }}</span> @endif</span>
                                <span class="tags">
                                    @foreach (array_slice($version['game_versions'], 0, 3) as $gv) <span class="pill neutral plain">{{ $gv }}</span> @endforeach
                                    @foreach ($version['loaders'] as $loader) <span class="pill neutral plain">{{ $loader }}</span> @endforeach
                                    @if ($version['type'] !== 'release') <span class="pill warning plain">{{ $version['type'] }}</span> @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <label class="check-line"><input type="checkbox" name="wipe_world" value="1"> {{ __('Usuń też świat (świeży start)') }}</label>
                    <label class="check-line"><input type="checkbox" name="eula" value="1" required> {!! __('Akceptuję :eula', ['eula' => '<a href="https://aka.ms/MinecraftEULA" target="_blank" rel="noopener">EULA Minecrafta</a>']) !!}</label>
                    <p class="hint">{{ __('Panel sam dobierze loader i wersję Javy. Świat, server.properties i listy graczy zostają.') }}</p>
                    @can('operate', $app)
                        <button class="btn btn-primary" type="submit" @disabled(! $app->acceptsCommands())><x-icon name="download" :size="15"/> {{ __('Zainstaluj modpack') }}</button>
                    @endcan
                </form>
            @endif
        </div>
    @endif
@endsection
