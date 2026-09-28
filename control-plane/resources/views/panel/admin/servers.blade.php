@extends('layouts.panel')

@section('title', __('Wszystkie maszyny'))

@section('content')
    <h1>{{ __('Maszyny') }}</h1>
    <p class="lede">{{ __('Wszystkie maszyny w systemie, niezależnie od właściciela.') }}</p>

    @include('panel.admin._nav')

    <div class="card">
        <form method="GET" action="{{ route('panel.admin.servers') }}">
            <div class="grid grid-2">
                <div class="field" style="margin-bottom:0">
                    <label for="q">{{ __('Szukaj') }}</label>
                    <input id="q" name="q" type="text" value="{{ request('q') }}"
                           placeholder="{{ __('nazwa hosta albo e-mail klienta') }}">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="state">{{ __('Stan') }}</label>
                    <select id="state" name="state">
                        <option value="">{{ __('wszystkie') }}</option>
                        @foreach ($states as $state)
                            <option value="{{ $state->value }}" @selected(request('state') === $state->value)>
                                {{ $state->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="btn-row" style="margin-top:14px">
                <button class="btn btn-primary" type="submit">{{ __('Filtruj') }}</button>
                @if (request('q') || request('state'))
                    <a class="btn" href="{{ route('panel.admin.servers') }}">{{ __('Wyczyść') }}</a>
                @endif
            </div>
        </form>
    </div>

    @php $isAdmin = auth()->user()->isAdmin(); @endphp
    <form method="POST" action="{{ route('panel.admin.servers.bulk') }}" id="servers-bulk">
        @csrf
        <div class="bulk-bar" data-bulk-bar hidden>
            <span><strong data-bulk-count>0</strong> {{ __('zaznaczonych') }}</span>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">{{ __('Usuń zaznaczone') }}</button>
            @if ($isAdmin)
                <button class="btn btn-sm" type="submit" name="action" value="purge"
                        title="{{ __('Bez kontaktu z węzłem — dla martwych wpisów') }}">{{ __('Usuń zaznaczone tylko z panelu') }}</button>
            @endif
        </div>

        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" data-check-all aria-label="{{ __('Zaznacz wszystkie') }}" style="width:auto"></th>
                        <th>{{ __('Maszyna') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Adres IP') }}</th>
                        <th>{{ __('Węzeł') }}</th><th>{{ __('Zapora') }}</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($servers as $server)
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $server->id }}" data-check style="width:auto"
                                       aria-label="{{ __('Zaznacz :hostname', ['hostname' => $server->hostname]) }}"></td>
                            <td>
                                <span class="os-inline">
                                    @if ($server->osFamily())
                                        @include('panel.servers._os-badge', ['template' => (object) ['family' => $server->osFamily(), 'name' => $server->osLabel()]])
                                    @endif
                                    <a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a>
                                </span>
                                <div class="hint">
                                    {{ $server->osLabel() ?? __('system nieznany') }} ·
                                    @php $tu = app(\App\Domain\Metrics\Traffic::class)->usage($server); @endphp
                                    {{ __(':vcpu vCPU · :ram_mb GB · :disk_gb GB · transfer :used', ['vcpu' => $server->vcpu, 'ram_mb' => round($server->ram_mb / 1024, 1), 'disk_gb' => $server->disk_gb, 'used' => \App\Domain\Metrics\Traffic::human($tu['used'])]) }}@if ($tu['percent'] !== null) <span class="{{ $tu['percent'] >= 100 ? 'text-critical' : ($tu['percent'] >= 80 ? 'text-warn' : '') }}">({{ str_replace('.', ',', $tu['percent']) }}%)</span>@endif
                                    · {{ $server->created_at->format('d.m.Y') }}@if ($server->label) · {{ $server->label }}@endif
                                </div>
                            </td>
                            <td>
                                {{ $server->user?->email ?? '—' }}
                            </td>
                            <td>
                                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                                @if ($server->isSuspended())
                                    <div class="hint">{{ $server->suspension_reason }}</div>
                                @elseif ($server->state === \App\Enums\ServerState::Error && $server->state_message)
                                    <div class="hint" style="max-width:260px">{{ \Illuminate\Support\Str::limit($server->state_message, 90) }}</div>
                                @endif
                            </td>
                            <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                            <td class="muted">
                                @if ($server->hypervisor)
                                    <a href="{{ route('panel.admin.hypervisors.show', $server->hypervisor) }}">{{ $server->hypervisor->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('panel.servers.show', $server) }}#firewall" style="text-decoration:none">
                                    <span class="pill {{ $server->firewall_enabled ? ($server->firewall_inbound === 'drop' ? 'ok' : 'info') : 'neutral' }}">
                                        {{ $server->firewall_enabled ? ($server->firewall_inbound === 'drop' ? __('restrykcyjna') : __('włączona')) : __('wyłączona') }}
                                    </span>
                                </a>
                                @if ($server->firewall_locked)
                                    <div class="hint">{{ __('zablokowana') }}</div>
                                @endif
                            </td>
                            <td class="row-actions">
                                @can('destroy', $server)
                                    <button class="btn btn-sm btn-danger" type="submit" name="row" value="delete:{{ $server->id }}"
                                            @disabled($server->state === \App\Enums\ServerState::Deleting)
                                            data-confirm="{{ __('Usunąć :hostname razem z dyskiem?', ['hostname' => $server->hostname]) }}">{{ __('Usuń') }}</button>
                                @endcan
                                @if ($isAdmin)
                                    <button class="btn btn-sm" type="submit" name="row" value="purge:{{ $server->id }}"
                                            title="{{ __('Usuń wpis bez kontaktu z węzłem (węzeł nie istnieje, maszyna zniknęła, operacja utknęła)') }}"
                                            data-confirm="{{ __('Usunąć :hostname TYLKO z panelu? Panel nie skontaktuje się z węzłem — jeśli maszyna tam działa, trzeba ją usunąć ręcznie.', ['hostname' => $server->hostname]) }}">{{ __('Z panelu') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">{{ __('Brak maszyn spełniających kryteria.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <p class="hint">
        <strong>{{ __('Usuń') }}</strong> {{ __('kasuje maszynę na węźle i dopiero potem zwalnia adresy IP.') }}
        @if ($isAdmin)
            <strong>{{ __('Z panelu') }}</strong> {{ __('usuwa sam wpis — dla maszyn, których węzeł już nie istnieje albo nie odpowiada, i dla operacji, które utknęły.') }}
        @endif
    </p>

    <script>
        (() => {
            const form = document.getElementById('servers-bulk');
            const boxes = [...form.querySelectorAll('[data-check]')];
            const bar = form.querySelector('[data-bulk-bar]');
            const count = form.querySelector('[data-bulk-count]');
            const sync = () => {
                const n = boxes.filter((b) => b.checked).length;
                bar.hidden = n === 0;
                count.textContent = n;
            };
            form.querySelector('[data-check-all]')?.addEventListener('change', (e) => {
                boxes.forEach((b) => { b.checked = e.target.checked; });
                sync();
            });
            boxes.forEach((b) => b.addEventListener('change', sync));

            // Pytanie zależy od przycisku i liczby zaznaczonych — ustawiamy je przed
            // wysłaniem, a modal (vh-dialog.js) pyta o nie zamiast confirm().
            form.querySelectorAll('button[type=submit]').forEach((button) => button.addEventListener('click', () => {
                const n = boxes.filter((b) => b.checked).length;
                form.dataset.confirm = button.dataset.confirm || (button.value === 'purge'
                    ? @js(__('Usunąć :count maszyn TYLKO z panelu, bez kontaktu z węzłami?')).replace(':count', n)
                    : @js(__('Usunąć :count maszyn razem z dyskami?')).replace(':count', n));
            }));
        })();
    </script>

    {{ $servers->links() }}
@endsection
