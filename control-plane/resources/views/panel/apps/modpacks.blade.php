@extends('layouts.panel')

@section('title', __('Modpacki — :name', ['name' => $app->name]))

@section('content')
    @include('panel.apps._header')
    @include('panel.apps._content_job')

    @php
        $platform = $profile->platform();
        $modpack = $profile->modpack();
        $mc = $profile->gameVersion(false);
        $platformNames = ['paper' => 'Paper', 'purpur' => 'Purpur', 'vanilla' => 'Vanilla', 'fabric' => 'Fabric', 'quilt' => 'Quilt', 'forge' => 'Forge', 'neoforge' => 'NeoForge'];
    @endphp

    <div class="grid grid-2" style="margin-bottom:16px">
        <div class="card">
            <h3 class="card-title">{{ __('Na serwerze') }}</h3>
            @if ($modpack)
                <div class="content-hero">
                    @if ($modpack['icon']) <img src="{{ $modpack['icon'] }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"> @endif
                    <div>
                        <strong>{{ $modpack['name'] }}</strong> <span class="muted">{{ $modpack['version'] }}</span><br>
                        <span class="muted">{{ $platformNames[$platform] ?? $platform }} · Minecraft {{ $mc }}
                            @if ($app->minecraft['loader_version'] ?? null) · {{ $app->minecraft['loader_version'] }} @endif</span>
                    </div>
                </div>
            @elseif ($platform)
                <p><strong>{{ $platformNames[$platform] ?? $platform }}</strong> @if ($mc) · Minecraft {{ $mc }} @endif</p>
                <p class="muted">{{ __('Bez modpacka. Zainstaluj paczkę z listy poniżej albo czysty serwer z loaderem.') }}</p>
            @else
                <p class="muted">{{ __('Serwer nie ma jeszcze zainstalowanego loadera — wybierz modpack albo czysty serwer.') }}</p>
            @endif
            @if (($app->minecraft['memory_recommended'] ?? 0) > $app->memory_mb)
                <div class="alert alert-warning" style="margin-top:12px">{{ __('Autor paczki zaleca :rec MB RAM, a plan ma :mb MB — serwer może działać wolno albo się nie uruchomić.', ['rec' => $app->minecraft['memory_recommended'], 'mb' => $app->memory_mb]) }}</div>
            @endif
        </div>

        @can('operate', $app)
            <div class="card">
                <h3 class="card-title">{{ __('Czysty serwer z loaderem') }}</h3>
                <form class="content-form" method="POST" action="{{ route('panel.apps.loader.install', $app) }}"
                      data-confirm="{{ __('Zainstalować nowy serwer? Mody, konfiguracja i biblioteki poprzedniego serwera zostaną usunięte.') }}">
                    @csrf
                    <div class="grid-compact">
                        <div class="field">
                            <label for="l-loader">{{ __('Loader') }}</label>
                            <select id="l-loader" name="loader">
                                @foreach (['neoforge' => 'NeoForge', 'forge' => 'Forge', 'fabric' => 'Fabric', 'quilt' => 'Quilt', 'vanilla' => 'Vanilla'] as $key => $name)
                                    <option value="{{ $key }}" @selected(old('loader', $platform) === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="l-mc">{{ __('Minecraft') }}</label>
                            <select id="l-mc" name="mc">
                                @foreach (array_slice($mcVersions, 0, 60) as $version)
                                    <option value="{{ $version }}" @selected(old('mc', $mc) === $version)>{{ $version }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="l-version">{{ __('Wersja loadera') }}</label>
                            <input id="l-version" name="loader_version" type="text" maxlength="40" placeholder="{{ __('najnowsza stabilna') }}" value="{{ old('loader_version') }}">
                        </div>
                    </div>
                    <label class="check-line"><input type="checkbox" name="wipe_world" value="1"> {{ __('Usuń też świat (świeży start)') }}</label>
                    <label class="check-line"><input type="checkbox" name="eula" value="1" required> {!! __('Akceptuję :eula', ['eula' => '<a href="https://aka.ms/MinecraftEULA" target="_blank" rel="noopener">EULA Minecrafta</a>']) !!}</label>
                    <button class="btn" type="submit" style="margin-top:8px" @disabled(! $app->acceptsCommands())>{{ __('Zainstaluj serwer') }}</button>
                </form>
            </div>
        @endcan
    </div>

    @error('content') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="section-head" style="margin-top:8px"><h2>{{ __('Modpacki') }}</h2></div>
    @include('panel.apps._catalog', [
        'route' => 'panel.apps.modpacks', 'showRoute' => 'panel.apps.modpacks.show',
        'placeholder' => __('np. All the Mods, Stoneblock, Create…'),
    ])
@endsection
