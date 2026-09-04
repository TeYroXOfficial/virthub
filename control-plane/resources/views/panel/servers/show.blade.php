@extends('layouts.panel')

@section('title', $server->hostname)

@section('content')
    <h1>{{ $server->hostname }}</h1>
    <p class="lede">
        <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
        @if ($server->label) · {{ $server->label }} @endif
        @if ($server->hypervisor && auth()->user()->isStaff())
            · węzeł {{ $server->hypervisor->name }}
        @endif
    </p>

    @if ($server->state_message)
        <div class="alert {{ $server->state->tone() === 'critical' ? 'alert-error' : 'alert-info' }}">
            {{ $server->state_message }}
        </div>
    @endif

    @if ($rootPassword)
        <div class="alert alert-info">
            <strong>Hasło początkowe roota — zapisz je teraz.</strong>
            <p style="margin:8px 0 0">Nie zobaczysz go ponownie. Po zalogowaniu zmień je na własne.</p>
            <p class="secret" style="margin-top:10px">{{ $rootPassword }}</p>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h3>Parametry</h3>
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
            <h3>Adresy IP</h3>
            @forelse ($server->ipAddresses as $ip)
                <dl class="kv" style="margin-bottom:12px">
                    <dt>{{ $ip->is_primary ? 'Główny' : 'Dodatkowy' }}</dt>
                    <dd class="mono">{{ $ip->address }}/{{ $ip->pool->prefix }}</dd>
                    <dt>Brama</dt><dd class="mono">{{ $ip->pool->gateway }}</dd>
                    @if ($ip->rdns)
                        <dt>rDNS</dt><dd class="mono">{{ $ip->rdns }}</dd>
                    @endif
                </dl>
            @empty
                <p class="muted">Maszyna nie ma jeszcze przypisanego adresu.</p>
            @endforelse

            @if ($server->primaryIp())
                <p class="hint">Połączenie: <code>ssh root@{{ $server->primaryIp()->address }}</code></p>
            @endif
        </div>
    </div>

    <h2>Sterowanie</h2>
    <div class="card">
        @if (! $server->acceptsCommands())
            <p class="muted">
                @if ($server->isSuspended())
                    Maszyna jest zawieszona ({{ $server->suspension_reason }}). Sterowanie jest niedostępne.
                @else
                    Trwa operacja: {{ $server->state->label() }}. Poczekaj na jej zakończenie.
                @endif
            </p>
        @else
            <div class="btn-row" id="power-controls">
                <button class="btn" data-action="start" @disabled($server->isRunning())>Uruchom</button>
                <button class="btn" data-action="reboot" @disabled(! $server->isRunning())>Restartuj</button>
                <button class="btn" data-action="stop" @disabled(! $server->isRunning())>Zatrzymaj</button>
                <button class="btn btn-danger" data-action="force-off" @disabled(! $server->isRunning())>
                    Odetnij zasilanie
                </button>
            </div>
            <p class="hint" style="margin-top:10px">
                „Zatrzymaj" prosi system o zamknięcie się. „Odetnij zasilanie" działa natychmiast,
                ale niezapisane dane przepadną.
            </p>
        @endif
    </div>

    <h2>Zapora sieciowa</h2>
    <div class="card">
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

    <h2>Kopie</h2>
    <div class="card">
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
            const original = button.textContent;
            button.textContent = 'Wysyłam…';

            try {
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
                    button.textContent = original;
                    return;
                }

                location.reload();
            } catch (error) {
                alert('Brak połączenia z panelem. Sprawdź sieć i spróbuj ponownie.');
                button.disabled = false;
                button.textContent = original;
            }
        });
    </script>
@endsection
