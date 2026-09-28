@extends('layouts.panel')

@section('title', __('Ustawienia — :name', ['name' => $app->name]))

@section('content')
    @include('panel.apps._header')

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title">{{ __('Informacje') }}</h3>
            <dl class="kv">
                <dt>{{ __('Szablon') }}</dt><dd>{{ $app->egg?->displayName() }}</dd>
                <dt>{{ __('Plan') }}</dt><dd>{{ $app->plan?->name ?? '—' }}</dd>
                <dt>{{ __('Zasoby') }}</dt>
                <dd>{{ __(':memory MB RAM · :disk MB dysku', ['memory' => $app->memory_mb, 'disk' => $app->disk_mb]) }}
                    · {{ $app->cpu_percent ? __('procesor: :percent% rdzenia', ['percent' => $app->cpu_percent]) : __('procesor bez limitu') }}</dd>
                <dt>{{ __('Porty') }}</dt>
                <dd class="mono">
                    @foreach ($app->allocations as $allocation)
                        {{ $app->hypervisor?->publicAddress() }}:{{ $allocation->port }}@if ($allocation->is_primary) <span class="pill neutral plain">{{ __('główny') }}</span>@endif<br>
                    @endforeach
                </dd>
                <dt>{{ __('Identyfikator') }}</dt><dd class="mono">{{ $app->uuid }}</dd>
                <dt>{{ __('Utworzona') }}</dt><dd>{{ $app->created_at->format('d.m.Y H:i') }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3 class="card-title">{{ __('SFTP') }}</h3>
            @php($sftpHost = $app->hypervisor?->publicAddress())
            @php($sftpUser = $app->sftpUsername(auth()->user()))
            @php($sftpPort = config('virthub.apps_sftp_port'))
            @php($sftpUrl = 'sftp://'.$sftpUser.'@'.$sftpHost.':'.$sftpPort)
            <dl class="kv">
                <dt>{{ __('Host') }}</dt>
                <dd class="mono copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $sftpHost }}">{{ $sftpHost ?? '?' }}</dd>
                <dt>{{ __('Port') }}</dt><dd class="mono">{{ $sftpPort }}</dd>
                <dt>{{ __('Użytkownik') }}</dt>
                <dd class="mono copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $sftpUser }}">{{ $sftpUser }}</dd>
                <dt>{{ __('Hasło') }}</dt><dd>{{ __('twoje hasło do panelu') }}</dd>
            </dl>
            <p class="hint">{{ __('Połącz się dowolnym klientem SFTP (FileZilla, WinSCP) — zobaczysz pliki aplikacji jak w zakładce Pliki. Większe paczki wgrywaj tą drogą.') }}</p>
            @if ($sftpHost)
                <a class="btn btn-sm" href="{{ $sftpUrl }}">{{ __('Otwórz w kliencie SFTP') }}</a>
            @endif
        </div>

        <div class="card">
            <h3 class="card-title">{{ __('Nazwa') }}</h3>
            <form method="POST" action="{{ route('panel.apps.rename', $app) }}">
                @csrf @method('PUT')
                <div class="field"><input type="text" name="name" maxlength="60" required value="{{ old('name', $app->name) }}" aria-label="{{ __('Nazwa') }}"></div>
                <button class="btn" type="submit">{{ __('Zmień nazwę') }}</button>
            </form>
        </div>
    </div>

    @can('manage', $app)
        <div class="card" style="margin-top:16px">
            <h3 class="card-title">{{ __('Zasoby i zawieszenie') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
            <form method="POST" action="{{ route('panel.admin.apps.resources', $app) }}">
                @csrf @method('PUT')
                <div class="grid-compact">
                    <div class="field"><label for="r-mem">{{ __('RAM (MB)') }}</label><input id="r-mem" name="memory_mb" type="text" inputmode="numeric" value="{{ $app->memory_mb }}"></div>
                    <div class="field"><label for="r-cpu">{{ __('Procesor (%)') }}</label><input id="r-cpu" name="cpu_percent" type="text" inputmode="numeric" value="{{ $app->cpu_percent }}" placeholder="0"></div>
                    <div class="field"><label for="r-disk">{{ __('Dysk (MB)') }}</label><input id="r-disk" name="disk_mb" type="text" inputmode="numeric" value="{{ $app->disk_mb }}"></div>
                </div>
                @error('memory_mb') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                <button class="btn" type="submit">{{ __('Zapisz zasoby') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.apps.suspend', $app) }}" class="setting-row" style="margin-top:16px">
                @csrf
                @if ($app->isSuspended())
                    <p class="setting-text muted">{{ __('Aplikacja jest zawieszona: :reason', ['reason' => $app->suspension_reason]) }}</p>
                    <button class="btn" type="submit">{{ __('Odwieś') }}</button>
                @else
                    <div class="setting-text field" style="margin:0"><input type="text" name="reason" maxlength="255" placeholder="{{ __('Powód zawieszenia (opcjonalnie)') }}" aria-label="{{ __('Powód zawieszenia') }}"></div>
                    <button class="btn btn-danger" type="submit">{{ __('Zawieś i zatrzymaj') }}</button>
                @endif
            </form>

            <div class="setting-row" style="margin-top:16px">
                <div class="setting-text">
                    <h3>{{ __('Ochrona przed nadużyciami') }}
                        @if ($app->abuse_exempt) <span class="pill warning">{{ __('wyłączona') }}</span> @endif
                    </h3>
                    <p class="muted">{{ __('Węzeł co minutę sprawdza procesy i pliki aplikacji: PteroVM i inne systemy w kontenerze (proot, QEMU), koparki kryptowalut i zdalne powłoki są zabijane, a aplikacja zawieszana.') }}</p>
                    @if ($app->abuse_detected_at)
                        <p><strong>{{ __('Ostatnie wykrycie: :date', ['date' => $app->abuse_detected_at->format('d.m.Y H:i')]) }}</strong></p>
                        <ul class="abuse-findings">
                            @foreach ($app->abuse_findings['findings'] ?? [] as $finding)
                                <li><span class="pill {{ $finding['level'] === 'block' ? 'critical' : 'warning' }} plain">{{ \App\Http\Controllers\Internal\AppAbuseController::label($finding['category']) }}</span>
                                    <span class="muted">{{ $finding['source'] === 'process' ? __('proces') : __('plik') }}:</span> <code>{{ $finding['detail'] }}</code></li>
                            @endforeach
                        </ul>
                    @endif
                    @error('abuse') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                @if (auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('panel.admin.apps.abuse-exempt', $app) }}"
                          @unless ($app->abuse_exempt) data-confirm="{{ __('Wyłączyć ochronę dla tej aplikacji? Rób to tylko przy fałszywym alarmie.') }}" @endunless>
                        @csrf
                        <button class="btn {{ $app->abuse_exempt ? '' : 'btn-danger' }}" type="submit">
                            {{ $app->abuse_exempt ? __('Włącz ochronę') : __('Fałszywy alarm — wyłącz ochronę') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endcan

    <div class="card danger-zone" style="margin-top:16px">
        <h3 class="card-title" style="color:var(--critical)">{{ __('Strefa niebezpieczna') }}</h3>
        @error('confirm') <div class="alert alert-error">{{ $message }}</div> @enderror

        <form method="POST" action="{{ route('panel.apps.reinstall', $app) }}" class="setting-row"
              data-confirm="{{ __('Uruchomić instalację ponownie? Skrypt eggu może nadpisać pliki.') }}">
            @csrf
            <div class="setting-text">
                <h3>{{ __('Reinstalacja') }}</h3>
                <p class="muted">{{ __('Ponownie uruchamia skrypt instalacyjny szablonu (np. pobiera serwer w wersji ze zmiennych). Bez zaznaczonych opcji Twoje pliki zostają, ale skrypt może nadpisać część z nich.') }}</p>
                @if ($app->isMinecraft())
                    <label class="check-line"><input type="checkbox" name="wipe[]" value="world"> {{ __('Usuń świat (świeży start)') }}</label>
                    <label class="check-line"><input type="checkbox" name="wipe[]" value="plugins"> {{ __('Usuń pluginy i mody (plugins/, mods/)') }}</label>
                @endif
                <label class="check-line"><input type="checkbox" name="wipe[]" value="all"> {{ __('Wyczyść wszystkie pliki (instalacja od zera)') }}</label>
                <label class="check-line"><input type="checkbox" name="confirm" value="1" required> {{ __('Rozumiem') }}</label>
            </div>
            <button class="btn" type="submit" @disabled($app->isInstalling() || $app->isSuspended())><x-icon name="refresh" :size="15"/> {{ __('Reinstaluj') }}</button>
        </form>

        @can('destroy', $app)
            <form method="POST" action="{{ route('panel.apps.destroy', $app) }}" class="setting-row" style="margin-top:16px"
                  data-confirm="{{ __('Usunąć :name razem ze wszystkimi plikami? Tej operacji nie da się cofnąć.', ['name' => $app->name]) }}">
                @csrf @method('DELETE')
                <div class="setting-text">
                    <h3>{{ __('Usunięcie aplikacji') }}</h3>
                    <p class="muted">{{ __('Kasuje kontener i wszystkie pliki na węźle, zwalnia porty.') }}</p>
                    <label class="check-line"><input type="checkbox" name="confirm" value="1" required> {{ __('Rozumiem, że pliki zostaną bezpowrotnie usunięte') }}</label>
                </div>
                <button class="btn btn-danger-solid" type="submit"><x-icon name="trash" :size="15"/> {{ __('Usuń aplikację') }}</button>
            </form>
        @endcan

        @if (auth()->user()->isAdmin() && auth()->user()->can('manage', $app))
            <form method="POST" action="{{ route('panel.admin.apps.purge', $app) }}" class="setting-row" style="margin-top:16px"
                  data-confirm="{{ __('Usunąć wpis TYLKO z panelu? Panel nie skontaktuje się z węzłem.') }}">
                @csrf
                <div class="setting-text">
                    <h3>{{ __('Usuń tylko z panelu') }}</h3>
                    <p class="muted">{{ __('Dla aplikacji, których węzeł już nie istnieje albo nie odpowiada. Pliki na węźle zostają.') }}</p>
                </div>
                <button class="btn btn-danger" type="submit">{{ __('Usuń z panelu') }}</button>
            </form>
        @endif
    </div>
@endsection
