@extends('layouts.panel')

@section('title', ($project['name'] ?? __('Dodatek')).' — '.$app->name)

@section('content')
    @include('panel.apps._header')

    <p><a href="{{ route('panel.apps.addons', [$app, 'source' => $source]) }}"><x-icon name="arrow-left" :size="14"/> {{ __('Wróć do listy') }}</a></p>

    @if ($error)
        <div class="alert alert-error">{{ $error }}</div>
    @endif
    @error('content') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($project)
        <div class="card">
            <div class="content-hero">
                @if ($project['icon']) <img src="{{ $project['icon'] }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"> @endif
                <div>
                    <h2 style="margin:0">{{ $project['name'] }}</h2>
                    <span class="muted">{{ $sourceName }}@if ($project['author']) · {{ $project['author'] }}@endif
                        @if ($project['url']) · <a href="{{ $project['url'] }}" target="_blank" rel="noopener">{{ __('strona projektu') }}</a>@endif</span>
                    <p style="margin:6px 0 0">{{ $project['summary'] }}</p>
                    @if ($installed)
                        <p style="margin:6px 0 0"><span class="pill ok">{{ __('zainstalowany: :v', ['v' => $installed->version]) }}</span></p>
                    @endif
                </div>
            </div>

            <h3 class="card-title" style="margin-top:20px">{{ __('Wersje zgodne z Minecraft :mc (:platform)', ['mc' => $profile->gameVersion(false), 'platform' => ucfirst((string) $profile->platform())]) }}</h3>
            @if ($versions === [])
                <p class="muted">{{ __('Żadna wersja nie pasuje do tego serwera — autor nie wydał jej dla tej wersji gry albo platformy.') }}</p>
            @else
                <div class="version-list">
                    @foreach ($versions as $version)
                        <form method="POST" action="{{ route('panel.apps.addons.install', $app) }}">
                            @csrf
                            <input type="hidden" name="source" value="{{ $source }}">
                            <input type="hidden" name="project" value="{{ $project['id'] }}">
                            <input type="hidden" name="version" value="{{ $version['id'] }}">
                            <label>
                                <span><strong>{{ $version['number'] ?: $version['name'] }}</strong>
                                    @if ($version['date']) <span class="muted">· {{ \Illuminate\Support\Carbon::parse($version['date'])->format('d.m.Y') }}</span> @endif</span>
                                <span class="tags">
                                    @if ($version['type'] !== 'release') <span class="pill warning plain">{{ $version['type'] }}</span> @endif
                                    @if ($installed && $installed->version_id === $version['id'])
                                        <span class="pill ok plain">{{ __('zainstalowana') }}</span>
                                    @else
                                        @can('operate', $app)
                                            <button class="btn btn-sm" type="submit" @disabled(! $app->acceptsCommands())><x-icon name="download" :size="14"/> {{ $installed ? __('Zainstaluj tę wersję') : __('Zainstaluj') }}</button>
                                        @endcan
                                    @endif
                                </span>
                            </label>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endsection
