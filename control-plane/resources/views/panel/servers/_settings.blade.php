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

    @if (! $server->isContainer() && $user->can('snapshots', $server))
        <div class="card" id="elevate">
            <h3 class="card-title"><x-icon name="archive" :size="16"/> {{ __('Elevate — próbne zmiany dysku') }}
                @if ($server->isElevated()) <span class="pill warning">{{ __('aktywny od :time', ['time' => $server->elevated_at->diffForHumans()]) }}</span> @endif</h3>
            @if ($server->isElevated())
                <p class="muted">{{ __('Wszystkie zapisy na dysku trafiają do nakładki. Zatwierdź, żeby zachować zmiany, albo odrzuć, żeby wrócić do stanu sprzed włączenia (maszyna uruchomi się ponownie). Do tego czasu migawki, reinstalacja i zmiana pakietu są zablokowane.') }}</p>
                <div class="btn-row">
                    <form method="POST" action="{{ route('panel.servers.elevate', $server) }}" style="margin:0">
                        @csrf <input type="hidden" name="action" value="commit">
                        <button class="btn btn-primary" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Zatwierdź zmiany') }}</button>
                    </form>
                    <form method="POST" action="{{ route('panel.servers.elevate', $server) }}" style="margin:0"
                          data-confirm="{{ __('Odrzucić wszystkie zmiany na dysku od włączenia Elevate? Maszyna uruchomi się ponownie.') }}">
                        @csrf <input type="hidden" name="action" value="discard">
                        <button class="btn btn-danger" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Odrzuć zmiany') }}</button>
                    </form>
                </div>
            @else
                <p class="muted">{{ __('Przed ryzykowną zmianą (aktualizacja systemu, nowy kernel, konfiguracja) włącz Elevate: dysk zostaje zamrożony, a zmiany idą do nakładki. Jeśli coś pójdzie nie tak — jednym kliknięciem wracasz do stanu sprzed zmian. Działa także na włączonej maszynie.') }}</p>
                <form method="POST" action="{{ route('panel.servers.elevate', $server) }}" style="margin:0">
                    @csrf <input type="hidden" name="action" value="start">
                    <button class="btn" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Włącz Elevate') }}</button>
                </form>
            @endif
            @error('elevate') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
        </div>
    @endif

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

            @php
                $customIsos = \App\Models\IsoImage::query()->where('server_id', $server->id)->with('downloads')->orderBy('id')->get();
                $customLimit = \App\Domain\Provisioning\IsoLibrary::customLimit();
            @endphp
            @if ($customLimit > 0 || $customIsos->isNotEmpty())
                <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border)">
                    <h4 style="margin:0 0 8px">{{ __('Własny obraz ISO') }}</h4>
                    @foreach ($customIsos as $custom)
                        @php $dl = $custom->downloads->first(); @endphp
                        <div class="setting-row" style="display:flex; gap:10px; align-items:center; margin-bottom:6px">
                            <span style="flex:1">{{ $custom->name }}
                                @if ($dl?->status === 'ready') <span class="pill ok">{{ __('gotowy') }}</span>
                                @elseif ($dl?->status === 'failed') <span class="pill critical" title="{{ $dl->error }}">{{ __('błąd') }}</span>
                                @else <span class="pill warning">{{ __('pobieranie') }}@if ($dl?->progress) {{ $dl->progress }}%@endif</span> @endif
                                @if ($dl?->status === 'failed') <div class="hint" style="color:var(--critical)">{{ \Illuminate\Support\Str::limit($dl->error, 160) }}</div> @endif
                            </span>
                            <form method="POST" action="{{ route('panel.servers.iso.custom.destroy', [$server, $custom]) }}" style="margin:0" data-confirm="{{ __('Usunąć obraz :name?', ['name' => $custom->name]) }}">
                                @csrf @method('DELETE') <button class="btn btn-sm" type="submit" @disabled($server->iso_image_id === $custom->id)>{{ __('Usuń') }}</button>
                            </form>
                        </div>
                    @endforeach
                    @if ($customIsos->count() < $customLimit)
                        <form method="POST" action="{{ route('panel.servers.iso.custom', $server) }}">
                            @csrf
                            <div class="grid grid-2">
                                <div class="field"><label for="ci-name">{{ __('Nazwa') }}</label>
                                    <input id="ci-name" name="custom_name" maxlength="100" required value="{{ old('custom_name') }}" placeholder="{{ __('np. Alpine 3.20') }}"></div>
                                <div class="field"><label for="ci-url">{{ __('Adres URL obrazu') }}</label>
                                    <input id="ci-url" name="custom_url" type="url" maxlength="2000" required value="{{ old('custom_url') }}" placeholder="https://…/install.iso"></div>
                            </div>
                            @error('custom_url') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                            <button class="btn" type="submit">{{ __('Pobierz obraz') }}</button>
                            <span class="hint">{{ __('Do :gb GB. Obraz widzi tylko ta maszyna i znika razem z nią.', ['gb' => \App\Domain\Provisioning\IsoLibrary::customMaxGb()]) }}</span>
                        </form>
                    @endif
                </div>
            @endif
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

    @if (! $server->isContainer() && $server->pciAssets->isNotEmpty())
        <div class="card" id="pci">
            <h3 class="card-title"><x-icon name="monitor" :size="16"/> {{ __('Urządzenia PCI') }}</h3>
            <ul class="list-plain">
                @foreach ($server->pciAssets as $asset)
                    <li><strong>{{ $asset->name }}</strong> <code class="muted">{{ $asset->pci_address }}</code> <span class="pill neutral plain">{{ $asset->type }}</span></li>
                @endforeach
            </ul>
            @can('manageResources', $server)
                <p class="hint" style="margin-top:8px"><a href="{{ route('panel.admin.pci', $server->hypervisor_id) }}">{{ __('Zarządzaj urządzeniami węzła') }}</a></p>
            @endcan
        </div>
    @endif

    @can('manageResources', $server)
        @php $filterProfiles = \App\Models\NetworkFilterProfile::query()->orderBy('name')->get(); @endphp
        @if ($filterProfiles->isNotEmpty())
            <div class="card" id="network-filter">
                <h3 class="card-title"><x-icon name="shield" :size="16"/> {{ __('Profil filtrowania sieci') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
                <form method="POST" action="{{ route('panel.servers.network-filter', $server) }}">
                    @csrf
                    <div class="field" style="max-width:420px">
                        <label for="nf-profile">{{ __('Profil') }}</label>
                        <select id="nf-profile" name="network_filter_profile_id">
                            <option value="">{{ __('— domyślny —') }}</option>
                            @foreach ($filterProfiles as $profile)
                                <option value="{{ $profile->id }}" @selected($server->network_filter_profile_id === $profile->id)>{{ $profile->name }}@if ($profile->is_default) ({{ __('domyślny') }})@endif</option>
                            @endforeach
                        </select>
                        <div class="hint">{{ __('Anty-spoofing, blokada RA IPv6 i serwera DHCP oraz reguły administratora — przed zaporą klienta.') }}</div>
                    </div>
                    <button class="btn" type="submit" @disabled($server->state->isTransitioning())>{{ __('Zapisz') }}</button>
                </form>
            </div>
        @endif
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
