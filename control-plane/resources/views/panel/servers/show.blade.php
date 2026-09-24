@extends('layouts.panel')

@section('title', $server->hostname)

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ $server->hostname }}</h1>
            <div class="meta-line">
                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                <span>{{ $server->virtualization->label() }}</span>
                @if ($server->label) <span class="sep">·</span> <span>{{ $server->label }}</span> @endif
                @if ($server->primaryIp())
                    <span class="sep">·</span> <span class="mono">{{ $server->primaryIp()->address }}</span>
                @endif
                @if ($server->hypervisor && auth()->user()->isStaff())
                    <span class="sep">·</span> <span>węzeł {{ $server->hypervisor->name }}</span>
                @endif
            </div>
        </div>

        <div class="actions">
            @if ($server->acceptsCommands())
                <form method="POST" action="{{ route('panel.servers.console', $server) }}" target="_blank" style="margin:0">
                    @csrf
                    <button class="btn btn-primary" type="submit" @disabled(! $server->isRunning())
                            title="{{ $server->isRunning() ? 'Otwiera się w nowej karcie' : 'Uruchom maszynę, żeby otworzyć konsolę' }}">
                        <x-icon :name="$server->isContainer() ? 'terminal' : 'monitor'" :size="16"/>
                        Konsola
                    </button>
                </form>
                <div class="btn-group" id="power-controls">
                    <button class="btn" data-action="start" @disabled($server->isRunning()) title="Uruchom">
                        <x-icon name="play" :size="15"/> Start
                    </button>
                    <button class="btn" data-action="reboot" @disabled(! $server->isRunning()) title="Restartuj">
                        <x-icon name="refresh" :size="15"/> Restart
                    </button>
                    <button class="btn" data-action="stop" @disabled(! $server->isRunning()) title="Zamknij system">
                        <x-icon name="stop" :size="15"/> Stop
                    </button>
                    <button class="btn btn-danger" data-action="force-off" @disabled(! $server->isRunning())
                            title="Odetnij zasilanie — niezapisane dane przepadną">
                        <x-icon name="power" :size="15"/>
                    </button>
                </div>
            @endif
        </div>
    </div>

    @if ($server->state_message)
        <div class="alert {{ $server->state->tone() === 'critical' ? 'alert-error' : 'alert-info' }}">
            {{ $server->state_message }}
        </div>
    @endif

    @if ($rootPassword)
        <div class="alert alert-info">
            <strong>Hasło początkowe roota — zapisz je teraz.</strong>
            <p style="margin:8px 0 0">
                @if ($server->state === \App\Enums\ServerState::Building)
                    Hasło jest widoczne, dopóki maszyna się tworzy — potem zniknie z panelu.
                @else
                    Nie zobaczysz go ponownie.
                @endif
                Po zalogowaniu zmień je na własne (<code>passwd</code>).
            </p>
            <p class="secret" style="margin-top:10px">{{ $rootPassword }}</p>
            <div class="btn-row">
                <button class="btn" type="button" onclick="
                    navigator.clipboard.writeText(@js($rootPassword));
                    this.textContent = 'Skopiowane';
                ">Kopiuj hasło</button>
            </div>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="node" :size="16"/> Parametry</h3>
            <dl class="kv">
                <dt>Procesor</dt><dd class="num">{{ $server->vcpu }} vCPU</dd>
                <dt>Pamięć</dt><dd class="num">{{ round($server->ram_mb / 1024, 1) }} GB</dd>
                <dt>Dysk</dt><dd class="num">{{ $server->disk_gb }} GB</dd>
                <dt>Transfer</dt><dd class="num">{{ $server->bandwidth_gb }} GB / mies.</dd>
                <dt>System</dt><dd>{{ $server->template?->name ?? '—' }}</dd>
                <dt>Pakiet</dt><dd>{{ $server->package?->name ?? '—' }}</dd>
                <dt>Utworzona</dt><dd>{{ $server->created_at->format('d.m.Y H:i') }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="network" :size="16"/> Adresy IP</h3>
            @forelse ($server->ipAddresses as $ip)
                <dl class="kv" style="margin-bottom:12px">
                    <dt>{{ $ip->is_primary ? 'Główny' : 'Dodatkowy' }}</dt>
                    <dd class="mono">
                        {{ $ip->address }}/{{ $ip->pool->prefix }}
                        @if ($ip->isNat()) <span class="pill warning">NAT</span> @endif
                    </dd>
                    <dt>Brama</dt><dd class="mono">{{ $ip->pool->gateway }}</dd>
                    @if ($ports = $ip->natPorts())
                        <dt>Porty</dt>
                        <dd class="mono">
                            {{ $ports['ssh'] }} → 22 (SSH)@if($ports['to'] > $ports['from']),
                            {{ $ports['from'] + 1 }}–{{ $ports['to'] }} (1:1)@endif
                        </dd>
                    @endif
                    @if ($ip->rdns)
                        <dt>rDNS</dt><dd class="mono">{{ $ip->rdns }}</dd>
                    @endif
                </dl>
            @empty
                <p class="muted">Maszyna nie ma jeszcze przypisanego adresu.</p>
            @endforelse

            @if ($primary = $server->primaryIp())
                @if ($primary->isNat())
                    <p class="hint">
                        Maszyna stoi za NAT-em: wychodzi w świat adresem węzła, a z zewnątrz jest
                        osiągalna wyłącznie przez porty powyżej.
                        @if ($ports = $primary->natPorts())
                            Połączenie: <code>ssh -p {{ $ports['ssh'] }} root@{{ $primary->pool->nat_public_address ?: ($server->hypervisor?->hostname ?? 'adres-węzła') }}</code>.
                            Usługę uruchomioną w maszynie na porcie z zakresu {{ $ports['from'] + 1 }}–{{ $ports['to'] }}
                            widać z zewnątrz pod tym samym numerem.
                        @endif
                    </p>
                @else
                    <p class="hint">Połączenie: <code>ssh root@{{ $primary->address }}</code></p>
                @endif
            @endif
        </div>
    </div>

    @unless ($server->acceptsCommands())
        <div class="alert alert-warning">
            <div>
                @if ($server->isSuspended())
                    Maszyna jest zawieszona ({{ $server->suspension_reason }}). Sterowanie jest niedostępne.
                @else
                    Trwa operacja: {{ $server->state->label() }}. Poczekaj na jej zakończenie.
                @endif
            </div>
        </div>
    @endunless

    <div class="grid grid-2" style="margin-top:16px">
    <div class="card">
        <h3 class="card-title"><x-icon name="shield" :size="16"/> Zapora sieciowa</h3>
        @forelse ($server->firewallRules as $rule)
            <p style="margin:0 0 6px">
                <span class="mono">#{{ $loop->iteration }}</span> {{ $rule->describe() }}
                @if ($rule->comment) <span class="muted">— {{ $rule->comment }}</span> @endif
            </p>
        @empty
            <p class="muted">Brak własnych reguł — cały ruch przychodzący jest przepuszczany.</p>
        @endforelse
        <p class="hint" style="margin-top:12px">
            Niezależnie od reguł, ruch wychodzący z adresu spoza puli tej maszyny jest zawsze blokowany.
        </p>
    </div>

    <div class="card">
        <h3 class="card-title"><x-icon name="archive" :size="16"/> Kopie</h3>
        @forelse ($server->backups as $backup)
            <p style="margin:0 0 6px">
                <span class="mono">{{ $backup->name }}</span>
                <span class="pill {{ $backup->isRestorable() ? 'ok' : 'warning' }}">{{ $backup->status }}</span>
                <span class="muted">{{ $backup->created_at->diffForHumans() }}</span>
            </p>
        @empty
            <p class="muted">Brak kopii. Snapshot wymaga zatrzymanej maszyny.</p>
        @endforelse
    </div>

    </div>

    <h2>Historia operacji</h2>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Operacja</th><th>Status</th><th>Kiedy</th><th>Szczegóły</th></tr>
                </thead>
                <tbody>
                @forelse ($recentJobs as $job)
                    <tr>
                        <td class="mono">{{ $job->action }}</td>
                        <td>
                            <span class="pill {{ match($job->status) {
                                'done' => 'ok', 'failed' => 'critical', default => 'warning' } }}">
                                {{ $job->status }}
                            </span>
                        </td>
                        <td>{{ $job->created_at->diffForHumans() }}</td>
                        <td class="muted">{{ $job->error ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Brak operacji.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Sterowanie idzie przez API — ten sam endpoint, którego używa integracja
        // billingowa, więc nie ma dwóch ścieżek robiących to samo inaczej.
        document.getElementById('power-controls')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            button.disabled = true;
            const original = button.innerHTML;
            button.textContent = 'Wysyłam…';

            try {
                if (button.dataset.action === 'force-off'
                    && !confirm('Odciąć zasilanie? Niezapisane dane w maszynie przepadną.')) {
                    button.disabled = false;
                    button.innerHTML = original;
                    return;
                }

                const response = await fetch('{{ url("/api/v1/servers/{$server->id}/power") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: button.dataset.action }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    alert(payload.message ?? 'Nie udało się wykonać operacji.');
                    button.disabled = false;
                    button.innerHTML = original;
                    return;
                }

                location.reload();
            } catch (error) {
                alert('Brak połączenia z panelem. Sprawdź sieć i spróbuj ponownie.');
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    </script>
@endsection
