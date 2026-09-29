@extends('layouts.panel')

@section('title', $app->name)

@section('content')
    @include('panel.apps._header')

    @if ($app->isInstalling())
        @include('panel.apps._progress')
    @endif

    @include('panel.apps._stats')

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Parametry') }}</h3>
            <dl class="kv">
                <dt>{{ __('Szablon') }}</dt>
                <dd>{{ $app->egg?->displayName() ?? '—' }}
                    @if ($app->egg) <div class="hint">{{ $app->egg->categoryLabel() }}</div> @endif</dd>
                @if ($mc = $app->minecraft)
                    <dt>{{ __('Minecraft') }}</dt>
                    <dd>
                        {{ ucfirst($mc['platform'] ?? '') }} {{ $mc['mc'] ?? '' }}
                        @if (! empty($mc['modpack']['name']))
                            <div class="hint">{{ __('modpack :name :version', ['name' => $mc['modpack']['name'], 'version' => $mc['modpack']['version'] ?? '']) }}</div>
                        @endif
                    </dd>
                @endif
                <dt>{{ __('Procesor') }}</dt>
                <dd class="num">{{ $app->cpu_percent ? __(':percent% rdzenia', ['percent' => $app->cpu_percent]) : __('bez limitu') }}
                    @if ($cpu = $app->hypervisor?->cpuModel()) <div class="hint">{{ $cpu }}</div> @endif</dd>
                <dt>{{ __('Pamięć') }}</dt><dd class="num">{{ __(':mb MB', ['mb' => $app->memory_mb]) }}</dd>
                <dt>{{ __('Dysk') }}</dt><dd class="num">{{ __(':mb MB', ['mb' => $app->disk_mb]) }}</dd>
                <dt>{{ __('Obraz') }}</dt>
                <dd>{{ array_search($app->docker_image, $app->egg?->images() ?? [], true) ?: '—' }}
                    <div class="hint mono">{{ $app->docker_image }}</div></dd>
                <dt>{{ __('Plan') }}</dt><dd>{{ $app->plan?->name ?? '—' }}</dd>
                <dt>{{ __('Utworzona') }}</dt><dd>{{ $app->created_at->format('d.m.Y H:i') }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Połączenie') }}</h3>
            @php
                $host = $app->hypervisor?->publicAddress();
                $sftpUser = $app->sftpUsername(auth()->user());
                $sftpPort = config('virthub.apps_sftp_port');
            @endphp
            <dl class="kv" style="margin-bottom:12px">
                <dt>{{ __('Adres') }}</dt>
                <dd class="mono">
                    @if ($address = $app->address())
                        <span class="copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $address }}">{{ $address }}</span>
                    @else — @endif
                </dd>
                <dt>{{ __('Porty') }}</dt>
                <dd class="mono">
                    @forelse ($app->allocations as $allocation)
                        {{ $allocation->port }}@if ($allocation->is_primary) <span class="pill neutral plain">{{ __('główny') }}</span>@endif<br>
                    @empty — @endforelse
                </dd>
                <dt>{{ __('Identyfikator') }}</dt><dd class="mono">{{ $app->uuid }}</dd>
            </dl>
            <h3 class="card-title" style="margin-top:4px">{{ __('SFTP') }}</h3>
            <dl class="kv">
                <dt>{{ __('Host') }}</dt>
                <dd class="mono copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $host }}">{{ $host ?? '?' }}</dd>
                <dt>{{ __('Port') }}</dt><dd class="mono">{{ $sftpPort }}</dd>
                <dt>{{ __('Użytkownik') }}</dt>
                <dd class="mono copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $sftpUser }}">{{ $sftpUser }}</dd>
                <dt>{{ __('Hasło') }}</dt><dd>{{ __('twoje hasło do panelu') }}</dd>
            </dl>
            <p class="hint">{{ __('Połącz się dowolnym klientem SFTP (FileZilla, WinSCP) — zobaczysz pliki aplikacji jak w zakładce Pliki. Większe paczki wgrywaj tą drogą.') }}</p>
            @if ($host)
                <a class="btn btn-sm" href="{{ 'sftp://'.$sftpUser.'@'.$host.':'.$sftpPort }}">{{ __('Otwórz w kliencie SFTP') }}</a>
            @endif
        </div>
    </div>
@endsection
