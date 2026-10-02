@extends('layouts.panel')

@section('title', $node->name)

@php
    $pct = fn ($used, $total) => $total > 0 ? min(100, (int) round($used / $total * 100)) : 0;
    $isAdmin = auth()->user()->isAdmin();
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.hypervisors') }}" style="font-size:13px">{{ __('← Hypervisory') }}</a>
            <h1>{{ $node->name }}</h1>
            <div class="meta-line">
                @if (! $node->enrolled_at)
                    <span class="pill warning">{{ __('czeka na instalację') }}</span>
                @elseif ($node->status === 'maintenance')
                    <span class="pill warning">{{ __('konserwacja') }}</span>
                @else
                    <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}">{{ $node->isOnline() ? __('online') : __('offline') }}</span>
                @endif
                @if ($node->enrolled_at)
                    <span>{{ $node->virtualization->label() }}</span>
                    <span class="sep">·</span> <span class="mono">{{ $node->hostname }}</span>
                @endif
                @if ($node->group)
                    <span class="sep">·</span> <span>{{ __('grupa :name', ['name' => $node->group->name]) }}</span>
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($node->enrolled_at)
                <a class="btn" href="{{ route('panel.admin.monitoring', ['node' => $node->id]) }}"><x-icon name="monitor" :size="15"/> {{ __('Monitorowanie') }}</a>
                <form method="POST" action="{{ route('panel.admin.hypervisors.check', $node) }}" style="margin:0">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="refresh" :size="15"/> {{ __('Sprawdź łączność') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('panel.admin.hypervisors.enrollment', $node) }}" style="margin:0">
                    @csrf
                    <button class="btn btn-primary" type="submit">{{ __('Wygeneruj polecenie instalacyjne') }}</button>
                </form>
            @endif
        </div>
    </div>

    @include('panel.admin._nav')

    {{-- Przydział liczony razem dla maszyn i aplikacji — obie zajmują ten sam host. --}}
    @php
        $appRam = (int) $apps->sum('memory_mb');
        $appDisk = (int) round($apps->sum('disk_mb') / 1024);
        $tiles = [
            [__('Procesor'), $node->cpu_cores_used, $node->cpu_cores_total, 'vCPU', null],
            [__('Pamięć'), $node->ram_mb_used + $appRam, $node->ram_mb_total, 'MB', $appRam ? __('w tym aplikacje: :mb MB', ['mb' => $appRam]) : null],
            [__('Dysk'), $node->disk_gb_used + $appDisk, $node->disk_gb_total, 'GB', $appDisk ? __('w tym aplikacje: :gb GB', ['gb' => $appDisk]) : null],
        ];
    @endphp
    <div class="grid grid-4" style="margin-bottom:16px">
        @foreach ($tiles as [$label, $u, $t, $unit, $extra])
            <div class="stat">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value">{{ $pct($u, $t) }}%</div>
                <div class="stat-sub">{{ $u }} / {{ $t }} {{ $unit }}@if ($extra) · {{ $extra }}@endif</div>
                <div class="meter {{ $pct($u, $t) > 85 ? 'hot' : '' }}"><i style="width: {{ $pct($u, $t) }}%"></i></div>
            </div>
        @endforeach
        <div class="stat">
            <div class="stat-label">{{ __('Usługi') }}</div>
            <div class="stat-value">{{ $node->servers_count + $apps->count() }}</div>
            <div class="stat-sub">{{ __(':servers maszyn · :apps apl.', ['servers' => $node->servers_count, 'apps' => $apps->count()]) }}@if ($node->max_servers !== null) · {{ __('limit maszyn: :max', ['max' => $node->max_servers]) }}@endif</div>
            <div class="stat-sub">{{ __('ostatni kontakt: :nigdy', ['nigdy' => $node->last_seen_at?->diffForHumans() ?? __('nigdy')]) }}</div>
        </div>
    </div>

    <nav class="tabs" role="tablist">
        <button type="button" role="tab" data-tab="servers">{{ __('Maszyny (:count)', ['count' => $servers->count()]) }}</button>
        <button type="button" role="tab" data-tab="apps">{{ __('Aplikacje (:count)', ['count' => $apps->count()]) }}</button>
        <button type="button" role="tab" data-tab="settings">{{ __('Ustawienia') }}</button>
        <button type="button" role="tab" data-tab="info">{{ __('Informacje') }}</button>
    </nav>

    <div data-tab-panel="servers" role="tabpanel">
        @if ($servers->isEmpty())
            <div class="card empty">{{ __('Na tym węźle nie ma maszyn.') }}</div>
        @else
            <form method="POST" action="{{ route('panel.admin.servers.bulk') }}" id="servers-bulk"
                  data-confirm="{{ __('Wykonać operację na zaznaczonych maszynach?') }}">
                @csrf
                <div class="bulk-bar">
                    <span>{{ __('Zaznaczone:') }}</span>
                    <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">{{ __('Usuń') }}</button>
                    @if ($isAdmin)
                        <button class="btn btn-sm" type="submit" name="action" value="purge">{{ __('Usuń tylko z panelu') }}</button>
                    @endif
                </div>
                <div class="card" style="padding:0">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th style="width:32px"></th><th>{{ __('Maszyna') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('System') }}</th><th>{{ __('Adres IP') }}</th><th>{{ __('Zasoby') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($servers as $server)
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="{{ $server->id }}" style="width:auto"></td>
                                    <td><a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a></td>
                                    <td class="muted">{{ $server->user?->email ?? '—' }}</td>
                                    <td><span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span></td>
                                    <td class="muted">{{ $server->template?->name ?? '—' }}</td>
                                    <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                                    <td class="num">{{ __(':vcpu / :ram_mb GB / :disk_gb GB', ['vcpu' => $server->vcpu, 'ram_mb' => round($server->ram_mb / 1024, 1), 'disk_gb' => $server->disk_gb]) }}</td>
                                    <td style="text-align:right; white-space:nowrap">
                                        @can('destroy', $server)
                                            <button class="btn btn-sm btn-danger" type="submit" name="row" value="delete:{{ $server->id }}"
                                                    data-confirm="{{ __('Usunąć :hostname razem z dyskiem?', ['hostname' => $server->hostname]) }}">{{ __('Usuń') }}</button>
                                        @endcan
                                        @if ($isAdmin)
                                            <button class="btn btn-sm" type="submit" name="row" value="purge:{{ $server->id }}"
                                                    data-confirm="{{ __('Usunąć :hostname TYLKO z panelu?', ['hostname' => $server->hostname]) }}">{{ __('Z panelu') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif
    </div>

    <div data-tab-panel="apps" role="tabpanel" hidden>
        @if ($apps->isEmpty())
            <div class="card empty">{{ __('Na tym węźle nie ma aplikacji.') }}</div>
        @else
            <div class="card" style="padding:0">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Aplikacja') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Szablon') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Zasoby') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($apps as $app)
                            <tr>
                                <td><a href="{{ route('panel.apps.show', $app) }}">{{ $app->name }}</a></td>
                                <td class="muted">{{ $app->user?->email ?? '—' }}</td>
                                <td>
                                    <span class="pill {{ $app->statusTone() }}">{{ $app->statusLabel() }}</span>
                                    @if ($app->abuse_detected_at) <span class="pill critical plain">{{ __('nadużycie') }}</span> @endif
                                </td>
                                <td class="muted">{{ $app->egg?->displayName() ?? '—' }}</td>
                                <td class="mono">{{ $app->address() ?? '—' }}</td>
                                <td class="num">{{ __(':memory MB · :disk MB', ['memory' => $app->memory_mb, 'disk' => $app->disk_mb]) }}</td>
                                <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.apps.settings', $app) }}">{{ __('Zarządzaj') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div data-tab-panel="settings" role="tabpanel" hidden>
        <form method="POST" action="{{ route('panel.admin.hypervisors.update', $node) }}" class="card">
            @csrf @method('PUT')
            <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Ustawienia węzła') }}</h3>
            <div class="grid grid-2">
                <div class="field">
                    <label for="n-name">{{ __('Nazwa') }}</label>
                    <input id="n-name" name="name" type="text" value="{{ old('name', $node->name) }}" required>
                </div>
                <div class="field">
                    <label for="n-group">{{ __('Grupa węzłów') }}</label>
                    <select id="n-group" name="hypervisor_group_id">
                        <option value="">{{ __('— bez grupy —') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected($node->hypervisor_group_id === $group->id)>{{ $group->name }}</option>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('Węzeł korzysta z pul swojej grupy i z pul przypisanych do niego.') }}</div>
                </div>
                <div class="field">
                    <label for="n-status">{{ __('Tryb pracy') }}</label>
                    <select id="n-status" name="status">
                        <option value="online" @selected($node->status === 'online')>{{ __('Online') }}</option>
                        <option value="maintenance" @selected($node->status === 'maintenance')>{{ __('Konserwacja') }}</option>
                        <option value="offline" @selected($node->status === 'offline')>{{ __('Offline') }}</option>
                    </select>
                    <div class="hint">{{ __('Tryb konserwacji nie jest nadpisywany przez heartbeat węzła.') }}</div>
                </div>
                <div class="field">
                    <label for="n-max">{{ __('Limit maszyn') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                    <input id="n-max" name="max_servers" type="text" inputmode="numeric" value="{{ old('max_servers', $node->max_servers) }}" placeholder="{{ __('bez limitu') }}">
                    <div class="hint">{{ __('Po osiągnięciu limitu węzeł nie dostaje nowych maszyn.') }}</div>
                </div>
                <div class="field">
                    <label for="n-cpu-model">{{ __('Procesor') }} <span class="muted">{{ __('(widoczny dla klientów)') }}</span></label>
                    <input id="n-cpu-model" name="cpu_model" type="text" maxlength="120" value="{{ old('cpu_model', $node->cpu_model) }}"
                           placeholder="{{ $node->last_health['cpu_model'] ?? __('np. AMD EPYC 7763') }}">
                    <div class="hint">
                        {{ __('Puste = wykryty przez węzeł:agenta.', ['agenta' => isset($node->last_health['cpu_model']) ? ': '.$node->last_health['cpu_model'] : __(' (po aktualizacji agenta)')]) }}
                    </div>
                </div>
                <div class="field">
                    <label for="n-public">{{ __('Adres publiczny') }} <span class="muted">{{ __('(dla portów NAT)') }}</span></label>
                    <input id="n-public" name="public_address" type="text" maxlength="255" value="{{ old('public_address', $node->public_address) }}"
                           placeholder="{{ $node->last_health['public_ipv4'] ?? __('np. 203.0.113.10') }}">
                    <div class="hint">{{ __('Pod tym adresem klienci łączą się z portami maszyn za NAT-em (ssh -p …). Puste = wykryty przez węzeł. Ustaw, gdy węzeł stoi za NAT-em dostawcy albo ma kilka adresów.') }}</div>
                </div>
                <div class="field">
                    <label for="n-cpu">{{ __('Dostępne vCPU') }}</label>
                    <input id="n-cpu" name="cpu_cores_total" type="text" inputmode="numeric" value="{{ $node->cpu_cores_total }}" required>
                    <div class="hint">{{ __('Zajęte: :cpu_cores_used. Więcej niż rdzeni fizycznych = overcommit.', ['cpu_cores_used' => $node->cpu_cores_used]) }}</div>
                </div>
                <div class="field">
                    <label for="n-ram">{{ __('Dostępna pamięć (MB)') }}</label>
                    <input id="n-ram" name="ram_mb_total" type="text" inputmode="numeric" value="{{ $node->ram_mb_total }}" required>
                    <div class="hint">{{ __('Zajęte: :ram_mb_used MB', ['ram_mb_used' => $node->ram_mb_used]) }}</div>
                </div>
                <div class="field">
                    <label for="n-disk">{{ __('Dostępny dysk (GB)') }}</label>
                    <input id="n-disk" name="disk_gb_total" type="text" inputmode="numeric" value="{{ $node->disk_gb_total }}" required>
                    <div class="hint">{{ __('Zajęte: :disk_gb_used GB', ['disk_gb_used' => $node->disk_gb_used]) }}</div>
                </div>
                <div class="field">
                    <label for="n-bridge">{{ __('Mostek sieciowy') }}</label>
                    <input id="n-bridge" name="bridge" type="text" value="{{ $node->bridge }}" required>
                    <div class="hint">{{ __('Interfejs, do którego podpinane są maszyny.') }}</div>
                </div>
            </div>
            <div class="field">
                <label for="n-notes">{{ __('Notatki') }} <span class="muted">{{ __('(dla personelu)') }}</span></label>
                <textarea id="n-notes" name="notes" rows="3" placeholder="Dostawca, numer szafy, kontakt do DC…">{{ old('notes', $node->notes) }}</textarea>
            </div>
            <div class="field">
                <label class="check-line">
                    <input type="checkbox" name="accepts_new_servers" value="1" @checked($node->accepts_new_servers)>
                    {{ __('Przyjmuje nowe maszyny') }}
                </label>
                @if ($node->group && ! $node->group->accepts_new_servers)
                    <div class="hint">{{ __('Grupa :name ma wstrzymane przyjmowanie maszyn — to ustawienie jest nadrzędne.', ['name' => $node->group->name]) }}</div>
                @endif
            </div>
            <fieldset class="field" style="border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px 14px">
                <legend style="padding:0 6px; font-weight:650">{{ __('Aplikacje (serwery gier, boty)') }}</legend>
                <label class="check-line">
                    <input type="checkbox" name="apps_enabled" value="1" @checked(old('apps_enabled', $node->apps_enabled))>
                    {{ __('Uruchamiaj aplikacje na tym węźle') }}
                </label>
                <div class="grid-compact" style="margin-top:10px">
                    <div class="field">
                        <label for="n-app-from">{{ __('Porty od') }}</label>
                        <input id="n-app-from" name="app_port_start" type="text" inputmode="numeric" value="{{ old('app_port_start', $node->app_port_start) }}" placeholder="25565">
                    </div>
                    <div class="field">
                        <label for="n-app-to">{{ __('Porty do') }}</label>
                        <input id="n-app-to" name="app_port_end" type="text" inputmode="numeric" value="{{ old('app_port_end', $node->app_port_end) }}" placeholder="25665">
                    </div>
                    <div class="field">
                        <label for="n-app-oc">{{ __('Overcommit RAM (%)') }}</label>
                        <input id="n-app-oc" name="app_memory_overcommit" type="number" min="100" max="400" step="10" value="{{ old('app_memory_overcommit', $node->app_memory_overcommit ?? 100) }}">
                    </div>
                </div>
                @php
                    $appCap = app(\App\Domain\Apps\AppProvisioner::class);
                    $appPorts = app(\App\Domain\Apps\AppPorts::class);
                @endphp
                <div class="hint">
                    {{ __('Pamięć dla aplikacji: przydzielone :used z :cap MB (RAM węzła po odjęciu maszyn × overcommit). Dysk: wolne :disk MB. Wolne porty: :ports.', [
                        'used' => $appCap->appMemoryAllocated($node), 'cap' => $appCap->appMemoryCapacity($node),
                        'disk' => max(0, $appCap->freeDisk($node)), 'ports' => $appPorts->freeCount($node),
                    ]) }}
                    {{ __('Serwery gier rzadko zużywają cały limit — np. 150% pozwala przydzielić półtora raza więcej pamięci niż fizycznie jest.') }}
                </div>
                @php $appsHealth = $node->last_health['apps'] ?? null; @endphp
                <div class="hint">
                    {{ __('Każda aplikacja dostaje porty z tego zakresu (TCP i UDP) na adresie węzła. Porty bloków NAT maszyn są pomijane.') }}
                    @if ($appsHealth['available'] ?? false)
                        <br><span class="pill ok plain">{{ __('Docker :version', ['version' => $appsHealth['docker_version'] ?? '?']) }}</span>
                    @else
                        <br><span class="pill warning plain">{{ __('Docker niedostępny') }}</span>
                        {{ __('Zainstaluj go na węźle:') }} <code>curl -sSL https://raw.githubusercontent.com/{{ config('virthub.update_repo', 'TeYroXOfficial/virthub') }}/main/infra/update-node.sh | sudo VH_APPS=1 bash</code>
                    @endif
                </div>
            </fieldset>
            <button class="btn btn-primary" type="submit">{{ __('Zapisz ustawienia') }}</button>
        </form>

        <div class="card danger-zone">
            <h3 class="card-title" style="color:var(--critical)">{{ __('Usuwanie węzła') }}</h3>
            @if ($node->servers_count === 0)
                <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                      data-confirm="{{ __('Usunąć węzeł :name?', ['name' => $node->name]) }}" class="setting-row">
                    @csrf @method('DELETE')
                    <p class="muted setting-text">{{ __('Węzeł nie ma maszyn — można go usunąć. Agenta na serwerze wyłącz ręcznie.') }}</p>
                    <button class="btn btn-danger-solid" type="submit">{{ __('Usuń węzeł') }}</button>
                </form>
            @elseif ($isAdmin)
                <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                      data-confirm="{{ __('Usunąć węzeł i WSZYSTKIE jego maszyny z panelu?') }}">
                    @csrf @method('DELETE')
                    <input type="hidden" name="purge_servers" value="1">
                    <p class="muted" style="margin-top:0">
                        {{ __('Na węźle jest :servers_count maszyn. Jeśli węzeł już nie istnieje, możesz usunąć go razem z wpisami maszyn — tylko z panelu, bez kontaktu z węzłem. Adresy IP wrócą do pul.', ['servers_count' => $node->servers_count]) }}
                    </p>
                    <div class="field">
                        <label for="confirm-name">{{ __('Wpisz') }} <code>{{ $node->name }}</code>{{ __(', żeby potwierdzić') }}</label>
                        <input id="confirm-name" name="confirm_name" type="text" autocomplete="off" required>
                    </div>
                    <button class="btn btn-danger-solid" type="submit">{{ __('Usuń węzeł i jego maszyny z panelu') }}</button>
                </form>
            @else
                <p class="muted">{{ __('Na węźle są maszyny — usuń je najpierw albo poproś administratora.') }}</p>
            @endif
        </div>
    </div>

    <div data-tab-panel="info" role="tabpanel" hidden>
        @php $sec = $node->last_health['security'] ?? null; @endphp
        <div class="card" id="security">
            <h3 class="card-title"><x-icon name="shield" :size="16"/> {{ __('Izolacja maszyn od węzła') }}</h3>
            @if ($sec === null)
                <p class="muted">{{ __('Węzeł nie raportuje stanu izolacji — zaktualizuj agenta.') }}</p>
            @else
                <dl class="kv">
                    <dt>{{ __('Dostęp maszyn do usług węzła') }}</dt>
                    <dd>
                        @if ($sec['host_guard'] ?? false)
                            <span class="pill ok">{{ __('zablokowany') }}</span>
                        @else
                            <span class="pill critical">{{ __('otwarty') }}</span> <span class="hint">{{ __('VH_GUARD_HOST=0 w konfiguracji agenta') }}</span>
                        @endif
                    </dd>
                    <dt>{{ __('AppArmor') }}</dt>
                    <dd>
                        @if ($sec['apparmor'] ?? false)
                            <span class="pill ok">{{ __('włączony') }}</span>
                        @else
                            <span class="pill warning">{{ __('wyłączony') }}</span> <span class="hint">{{ __('goście nie są zamknięci profilem AppArmora') }}</span>
                        @endif
                    </dd>
                    @if (array_key_exists('idmap_isolated', $sec))
                        <dt>{{ __('Osobne UID/GID kontenerów') }}</dt>
                        <dd>
                            @if ($sec['idmap_isolated'])
                                <span class="pill ok">{{ __('tak') }}</span>
                            @else
                                <span class="pill warning">{{ __('nie') }}</span> <span class="hint">{{ __('zaktualizuj węzeł — aktualizacja ustawi przydział w /etc/subuid') }}</span>
                            @endif
                        </dd>
                        <dt>{{ __('Limit procesów w kontenerze') }}</dt><dd class="num">{{ $sec['max_processes'] ?? '—' }}</dd>
                        <dt>{{ __('Kontenery z osłabioną izolacją') }}</dt>
                        <dd>
                            @forelse ($sec['instance_issues'] ?? [] as $instance => $issues)
                                <div><span class="pill critical">{{ $instance }}</span> {{ implode(', ', $issues) }}</div>
                            @empty
                                <span class="pill ok">{{ __('brak') }}</span>
                            @endforelse
                        </dd>
                    @endif
                </dl>
            @endif
        </div>

        <div class="grid grid-2">
            <div class="card">
                <h3 class="card-title">{{ __('Połączenie') }}</h3>
                <dl class="kv">
                    <dt>{{ __('Adres agenta') }}</dt><dd class="mono">{{ $node->agent_url ?? '—' }}</dd>
                    <dt>{{ __('Adres publiczny') }}</dt><dd class="mono">{{ $node->publicAddress() ?? '—' }}@if ($node->public_address) <span class="hint">{{ __('(ustawiony ręcznie)') }}</span>@endif</dd>
                    <dt>{{ __('Zarejestrowany') }}</dt><dd>{{ $node->enrolled_at?->format('d.m.Y H:i') ?? __('nie') }}</dd>
                    <dt>{{ __('Ostatni kontakt') }}</dt><dd>{{ $node->last_seen_at?->diffForHumans() ?? __('nigdy') }}</dd>
                    <dt>{{ __('Certyfikat') }}</dt><dd>{{ $node->agent_tls_cert ? __('przypięty (własny węzła)') : __('weryfikacja publicznym urzędem') }}</dd>
                    <dt>{{ __('Procesor') }}</dt><dd>{{ $node->cpuModel() ?? '—' }}@if ($node->cpu_model) <span class="hint">{{ __('(ustawiony ręcznie)') }}</span>@endif</dd>
                    <dt>{{ __('Wersja agenta') }}</dt><dd class="mono">{{ \App\Domain\Updates\Updates::short($node->last_health['build'] ?? null) }}</dd>
                </dl>
                @if ($node->notes)
                    <p class="hint" style="white-space:pre-line; margin-top:14px">{{ $node->notes }}</p>
                @endif
            </div>
            <div class="card">
                <h3 class="card-title">{{ __('Pule adresów węzła') }}</h3>
                @forelse ($pools as $pool)
                    <p style="margin:0 0 8px">
                        <a class="mono" href="{{ route('panel.admin.ip-pools.show', $pool) }}">{{ $pool->name }}</a>
                        <span class="pill neutral">{{ __('IPv:version:nat', ['version' => $pool->version, 'nat' => $pool->isNat() ? __(' · NAT') : '']) }}</span>
                        <span class="muted">{{ $pool->hypervisor_id ? __('węzła') : __('grupy') }}
                            @if ($pool->version === 4) {{ __('· :assigned_count / :addresses_count zajętych', ['assigned_count' => $pool->assigned_count, 'addresses_count' => $pool->addresses_count]) }} @endif</span>
                        @foreach ($pool->hostNetworkConflicts() as $c)
                            <div class="hint" style="color:var(--critical)">{{ __('Nachodzi na sieć węzła :node (:interface: :network) — SSH przez porty NAT nie zadziała. Utwórz pulę z inną podsiecią, np. 10.77.0.0/24.', $c) }}</div>
                        @endforeach
                    </p>
                @empty
                    <p class="muted">{{ __('Brak pul — węzeł nie dostanie maszyn, dopóki nie przypiszesz mu adresów.') }}</p>
                @endforelse
                <a class="btn btn-sm" href="{{ route('panel.admin.ip-pools') }}" style="margin-top:8px">{{ __('Bloki IP') }}</a>
            </div>
        </div>
        @if ($node->last_health)
            <details class="form-block card">
                <summary>{{ __('Ostatni raport węzła') }}</summary>
                <dl class="kv" style="margin-top:12px">
                    @foreach ($node->last_health as $key => $value)
                        <dt class="mono">{{ $key }}</dt>
                        <dd class="mono">{{ is_bool($value) ? ($value ? __('tak') : __('nie')) : (is_array($value) ? json_encode($value) : $value) }}</dd>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>

    @push('scripts')
        <script src="{{ asset('js/server-page.js') }}?v={{ @filemtime(public_path('js/server-page.js')) }}"></script>
    @endpush
@endsection
