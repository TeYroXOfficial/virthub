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
                <dt>{{ __('Hasło') }}</dt>
                <dd>{{ $app->sftp_password ? __('osobne hasło SFTP') : __('twoje hasło do panelu') }}</dd>
            </dl>
            @if (session('sftp_password'))
                <div class="alert alert-info" style="margin:10px 0">
                    <strong>{{ __('Nowe hasło SFTP — zapisz je teraz:') }}</strong>
                    <p class="secret" style="margin-top:8px" data-copy="{{ session('sftp_password') }}">{{ session('sftp_password') }}</p>
                </div>
            @endif
            <p class="hint">{{ __('Połącz się dowolnym klientem SFTP (FileZilla, WinSCP) — zobaczysz pliki aplikacji jak w zakładce Pliki. Większe paczki wgrywaj tą drogą.') }}</p>
            @if ($host)
                <a class="btn btn-sm" href="{{ 'sftp://'.$sftpUser.'@'.$host.':'.$sftpPort }}">{{ __('Otwórz w kliencie SFTP') }}</a>
            @endif

            @can('operate', $app)
                <details class="form-block" style="margin-top:14px">
                    <summary>{{ __('Zmień hasło SFTP') }}</summary>
                    <div class="btn-row" style="margin-top:10px">
                        <form method="POST" action="{{ route('panel.apps.sftp-password', $app) }}" style="margin:0"
                              data-confirm="{{ __('Wygenerować nowe hasło SFTP? Poprzednie przestanie działać.') }}">
                            @csrf @method('PUT') <input type="hidden" name="mode" value="generate">
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Wygeneruj nowe hasło') }}</button>
                        </form>
                        @if ($app->sftp_password)
                            <form method="POST" action="{{ route('panel.apps.sftp-password', $app) }}" style="margin:0">
                                @csrf @method('PUT') <input type="hidden" name="mode" value="panel">
                                <button class="btn btn-sm" type="submit">{{ __('Używaj hasła do panelu') }}</button>
                            </form>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('panel.apps.sftp-password', $app) }}" style="margin-top:12px">
                        @csrf @method('PUT') <input type="hidden" name="mode" value="set">
                        <div class="grid-compact">
                            <div class="field">
                                <label for="s-pass">{{ __('Własne hasło SFTP') }}</label>
                                <input id="s-pass" name="password" type="password" minlength="10" required autocomplete="new-password">
                            </div>
                            <div class="field">
                                <label for="s-pass2">{{ __('Powtórz hasło') }}</label>
                                <input id="s-pass2" name="password_confirmation" type="password" required autocomplete="new-password">
                            </div>
                        </div>
                        @error('password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        <button class="btn btn-sm" type="submit">{{ __('Ustaw hasło') }}</button>
                    </form>
                    <p class="hint">{{ __('Osobne hasło działa tylko dla SFTP tej aplikacji — możesz je dać np. osobie, która wgrywa pliki, bez hasła do całego panelu.') }}</p>
                </details>
            @endcan
        </div>
    </div>
@endsection
