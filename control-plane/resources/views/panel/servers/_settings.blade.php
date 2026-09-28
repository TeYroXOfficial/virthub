{{-- Zakładka „Ustawienia": hasło roota, reinstalacja, płyta ISO. --}}
@php
    $user = auth()->user();
    $canIso = ! $server->isContainer() && $user->can('iso', $server);
    $lastPasswordJob = $recentJobs->firstWhere('action', 'password');
@endphp

<div class="settings-list">
    @can('resetPassword', $server)
        <div class="card setting-row" id="password">
            <div class="setting-text">
                <h3><x-icon name="key" :size="16"/> {{ __('Hasło roota') }}</h3>
                <p class="muted">
                    {{ __('Ustawia nowe, losowe hasło roota w działającym systemie. Zobaczysz je raz, na górze tej strony.') }}
                    @unless ($server->isContainer())
                        {{ __('W maszynie KVM musi działać') }} <code>qemu-guest-agent</code> {{ __('— nowe maszyny mają go od startu.') }}
                    @endunless
                </p>
                @if ($lastPasswordJob && $lastPasswordJob->status === 'failed' && $lastPasswordJob->created_at->gt(now()->subHour()))
                    <p class="hint" style="color:var(--critical)">{{ __('Ostatnia próba nie powiodła się: :error', ['error' => $lastPasswordJob->error]) }}</p>
                @endif
            </div>
            <form method="POST" action="{{ route('panel.servers.password', $server) }}"
                  data-confirm="{{ __('Ustawić nowe hasło roota? Stare przestanie działać.') }}">
                @csrf
                <button class="btn" type="submit" @disabled(! $server->acceptsCommands() || ! $server->isRunning())
                        title="{{ $server->isRunning() ? '' : __('Uruchom maszynę, żeby zmienić hasło') }}">
                    {{ __('Resetuj hasło') }}
                </button>
            </form>
        </div>
    @endcan

    @can('rebuild', $server)
        <div class="card setting-row">
            <div class="setting-text">
                <h3><x-icon name="refresh" :size="16"/> {{ __('Reinstalacja systemu') }}</h3>
                <p class="muted">
                    {{ __('Obecnie:') }} <strong>{{ $server->template?->name ?? __('nieznany') }}</strong>{{ __('. Postawienie systemu od nowa kasuje dysk; adresy IP, zapora i parametry zostają.') }}
                </p>
            </div>
            <button class="btn btn-danger" type="button" data-open-reinstall @disabled(! $server->acceptsCommands() || $osChoices->isEmpty())>
                {{ __('Reinstaluj…') }}
            </button>
        </div>
    @endcan

    @if ($canIso)
        <div class="card" id="iso">
            <h3 class="card-title"><x-icon name="disc" :size="16"/> {{ __('Obraz ISO') }}</h3>
            <dl class="kv" style="margin-bottom:14px">
                <dt>{{ __('W napędzie') }}</dt>
                <dd>{{ $server->iso?->name ?? __('brak płyty') }}</dd>
                <dt>{{ __('Rozruch') }}</dt>
                <dd>
                    @if ($server->boot_from_iso && $server->iso)
                        <span class="pill warning">{{ __('z płyty') }}</span>
                    @else
                        <span class="pill neutral">{{ __('z dysku') }}</span>
                    @endif
                </dd>
            </dl>
            <form method="POST" action="{{ route('panel.servers.iso', $server) }}">
                @csrf
                <div class="field">
                    <label for="iso-select">{{ __('Płyta') }}</label>
                    <select id="iso-select" name="iso" @disabled(! $server->acceptsCommands())>
                        <option value="">{{ __('— wysuń płytę —') }}</option>
                        @foreach ($isos as $iso)
                            <option value="{{ $iso->id }}" @selected($server->iso_image_id === $iso->id)>
                                {{ $iso->name }}@unless ($iso->is_public) {{ __('(personel)') }}@endunless
                            </option>
                        @endforeach
                    </select>
                    @if ($isos->isEmpty())
                        <div class="hint">{{ __('Na węźle tej maszyny nie ma jeszcze żadnych obrazów.') }}</div>
                    @endif
                </div>
                <div class="field">
                    <label class="check-line">
                        <input type="checkbox" name="boot" value="1" @checked($server->boot_from_iso)>
                        {{ __('Uruchamiaj z płyty (przed dyskiem)') }}
                    </label>
                    <label class="check-line">
                        <input type="checkbox" name="restart" value="1">
                        {{ __('Zastosuj od razu — wyłącz i włącz maszynę') }}
                    </label>
                </div>
                <button class="btn btn-primary" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Zapisz') }}</button>
                <p class="hint" style="margin-top:10px">
                    {{ __('Instalację z płyty prowadzisz w konsoli. Po instalacji wysuń płytę albo wyłącz rozruch z niej. Adresy IP maszyny widzisz w zakładce Przegląd — ustaw je w instalatorze.') }}
                </p>
            </form>
        </div>
    @endif

    <div class="card">
        <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Informacje') }}</h3>
        <dl class="kv">
            <dt>{{ __('Nazwa hosta') }}</dt><dd class="mono">{{ $server->hostname }}</dd>
            <dt>{{ __('Typ') }}</dt><dd>{{ $server->virtualization->label() }}</dd>
            <dt>{{ __('System w maszynie') }}</dt>
            <dd>
                {{ $server->guest_os_name ?? __('jeszcze nie odczytany') }}
                @if ($server->guest_os_checked_at)
                    <span class="hint">{{ __('· sprawdzony :diffforhumans', ['diffforhumans' => $server->guest_os_checked_at->diffForHumans()]) }}</span>
                @endif
                <form method="POST" action="{{ route('panel.servers.detect-os', $server) }}" style="display:inline; margin:0 0 0 6px">
                    @csrf
                    <button class="btn btn-sm" type="submit" @disabled(! $server->isRunning())>{{ __('Odczytaj teraz') }}</button>
                </form>
                @unless ($server->isContainer())
                    <div class="hint">{{ __('Maszyna KVM musi mieć działający') }} <code>qemu-guest-agent</code>.</div>
                @endunless
            </dd>
            @if ($cpu = $server->hypervisor?->cpuModel())
                <dt>{{ __('Procesor') }}</dt><dd>{{ $cpu }}</dd>
            @endif
            <dt>{{ __('Identyfikator') }}</dt><dd class="mono">#{{ $server->id }}</dd>
            <dt>{{ __('Utworzona') }}</dt><dd>{{ $server->created_at->format('d.m.Y H:i') }}</dd>
        </dl>
    </div>

    @can('manageTraffic', $server)
        <div class="card" id="traffic-admin">
            <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Transfer') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
            <p class="muted" style="margin-top:0">
                {{ __('W tym miesiącu:') }} <strong>{{ \App\Domain\Metrics\Traffic::human($traffic['used']) }}</strong>
                {{ __('z :limit.', ['limit' => \App\Domain\Metrics\Traffic::human($traffic['limit'])]) }}
                @if ($traffic['blocked'])
                    <span class="pill critical">{{ __('zablokowana za transfer') }}</span>
                @endif
            </p>
            <div class="grid grid-2">
                <form method="POST" action="{{ route('panel.servers.traffic', $server) }}">
                    @csrf
                    <input type="hidden" name="action" value="limit">
                    <div class="field">
                        <label for="bw">{{ __('Limit miesięczny (GB, 0 = bez limitu)') }}</label>
                        <input id="bw" name="bandwidth_gb" type="text" inputmode="numeric" value="{{ $server->bandwidth_gb }}">
                        <div class="hint">{{ __('Nadpisuje limit z pakietu dla tej maszyny.') }}</div>
                    </div>
                    <button class="btn" type="submit">{{ __('Zapisz limit') }}</button>
                </form>
                <form method="POST" action="{{ route('panel.servers.traffic', $server) }}"
                      data-confirm="{{ __('Wyzerować licznik transferu w tym miesiącu?') }}">
                    @csrf
                    <input type="hidden" name="action" value="reset">
                    <div class="field">
                        <label>{{ __('Licznik bieżącego miesiąca') }}</label>
                        <p class="hint" style="margin-top:0">{{ __('Zeruje zużycie; zablokowana za transfer maszyna zostanie odblokowana.') }}</p>
                    </div>
                    <button class="btn" type="submit">{{ __('Wyzeruj licznik') }}</button>
                </form>
            </div>
        </div>
    @endcan

    @can('manageResources', $server)
        <div class="card" id="cpu-limit">
            <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Limit procesora') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
            <form method="POST" action="{{ route('panel.servers.cpu-limit', $server) }}">
                @csrf
                <div class="field" style="max-width:320px">
                    <label for="cpu-limit-input">{{ __('Limit CPU (% jednego rdzenia)') }}</label>
                    <input id="cpu-limit-input" name="cpu_limit_percent" type="text" inputmode="numeric"
                           value="{{ old('cpu_limit_percent', $server->cpu_limit_percent) }}" placeholder="{{ __('bez limitu') }}">
                    <div class="hint">
                        {{ __('Twardy limit dla całej maszyny, jak cpulimit w Proxmoksie: 100 = jeden rdzeń, 150 = półtora. Maksymalnie :max (:vcpu vCPU). Puste = bez limitu. Działa od razu, bez restartu.', ['max' => $server->vcpu * 100, 'vcpu' => $server->vcpu]) }}
                    </div>
                    @error('cpu_limit_percent') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <button class="btn" type="submit" @disabled($server->state->isTransitioning())>{{ __('Zapisz limit') }}</button>
            </form>
        </div>
    @endcan

    @if ($user->can('destroy', $server) || $user->can('purge', $server))
        <div class="card danger-zone" id="delete">
            <h3 class="card-title" style="color:var(--critical)">{{ __('Usuwanie maszyny') }}</h3>
            @can('destroy', $server)
                <form method="POST" action="{{ route('panel.servers.destroy', $server) }}" class="setting-row"
                      data-confirm="{{ __('Usunąć :hostname razem z dyskiem? Tej operacji nie da się cofnąć.', ['hostname' => $server->hostname]) }}">
                    @csrf @method('DELETE')
                    <div class="setting-text">
                        <strong>{{ __('Usuń maszynę') }}</strong>
                        <p class="muted">{{ __('Kasuje maszynę i jej dysk na węźle, potem zwalnia adresy IP.') }}</p>
                        <label class="check-line" style="margin-top:8px">
                            <input type="checkbox" name="confirm" value="1" required>
                            {{ __('Rozumiem, że dane zostaną bezpowrotnie usunięte') }}
                        </label>
                    </div>
                    <button class="btn btn-danger-solid" type="submit" @disabled($server->state === \App\Enums\ServerState::Deleting)>{{ __('Usuń maszynę') }}</button>
                </form>
            @endcan
            @can('purge', $server)
                <form method="POST" action="{{ route('panel.servers.purge', $server) }}" class="setting-row" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border)"
                      data-confirm="{{ __('Usunąć wpis TYLKO z panelu? Panel nie skontaktuje się z węzłem.') }}">
                    @csrf
                    <div class="setting-text">
                        <strong>{{ __('Usuń tylko z panelu') }}</strong> <span class="pill neutral">{{ __('administrator') }}</span>
                        <p class="muted">
                            {{ __('Bez kontaktu z węzłem — gdy węzeł nie istnieje albo nie odpowiada, maszyny już tam nie ma albo operacja utknęła. Zwalnia adresy IP i zasoby węzła. Jeśli maszyna jednak działa na węźle, trzeba ją skasować tam ręcznie.') }}
                        </p>
                        <label class="check-line" style="margin-top:8px">
                            <input type="checkbox" name="confirm" value="1" required>
                            {{ __('Potwierdzam usunięcie wpisu') }}
                        </label>
                    </div>
                    <button class="btn" type="submit">{{ __('Usuń z panelu') }}</button>
                </form>
            @endcan
        </div>
    @endif
</div>
