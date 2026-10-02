{{-- Tabela adresów: stan, usługa, której adres jest przypisany, porty NAT, akcje dla wolnych. --}}
<div class="table-wrap">
    <table>
        <thead><tr>
            <th>{{ __('Adres') }}</th>
            <th>{{ $showPool ? __('Blok / węzeł') : __('Węzeł') }}</th>
            <th>{{ __('Stan') }}</th>
            <th>{{ __('Usługa') }}</th>
            @if ($nat) <th>{{ __('Porty NAT') }}</th> @else <th>{{ __('rDNS') }}</th> @endif
            <th>{{ __('MAC') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($addresses as $address)
            <tr>
                <td class="mono nowrap"><strong>{{ $address->address }}</strong>
                    @if ($address->is_primary) <span class="pill neutral plain">{{ __('główny') }}</span> @endif</td>
                <td class="nowrap">
                    @if ($showPool && $address->pool)
                        <a href="{{ route('panel.admin.ip-pools.show', $address->pool) }}">{{ $address->pool->name }}</a>
                        <div class="hint">{{ $address->hypervisor?->name ?? __('grupa') }}</div>
                    @else
                        <span class="muted">{{ $address->hypervisor?->name ?? __('grupa') }}</span>
                    @endif
                </td>
                <td>
                    @if ($address->server_id)
                        <span class="pill info">{{ __('przydzielony') }}</span>
                    @elseif ($address->is_reserved)
                        <span class="pill neutral">{{ __('zarezerwowany') }}</span>
                    @else
                        <span class="pill ok">{{ __('wolny') }}</span>
                    @endif
                </td>
                <td>
                    @if ($address->server)
                        <a href="{{ route('panel.servers.show', $address->server) }}">{{ $address->server->hostname }}</a>
                        <div class="hint">{{ $address->server->user?->email }}@if ($address->assigned_at) · {{ $address->assigned_at->format('d.m.Y') }}@endif</div>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                @if ($nat)
                    @php $ports = $address->pool?->natPortsFor($address->address); @endphp
                    <td class="mono nowrap">{{ $ports ? $ports['from'].'–'.$ports['to'] : '—' }}</td>
                @else
                    <td class="mono muted">{{ $address->rdns ?? '—' }}</td>
                @endif
                <td class="mono muted nowrap">{{ $address->mac_address ?? '—' }}</td>
                <td style="text-align:right; white-space:nowrap">
                    <button class="btn btn-sm" type="button" onclick="document.getElementById('ip-edit-{{ $address->id }}').showModal()">{{ __('Edytuj') }}</button>
                    <dialog class="modal" id="ip-edit-{{ $address->id }}" style="text-align:left; white-space:normal; padding:22px 24px; width:min(420px, calc(100vw - 32px))">
                        <form method="POST" action="{{ route('panel.admin.ip-addresses.update', $address) }}">
                            @csrf
                            <input type="hidden" name="action" value="edit">
                            <h3 class="mono" style="margin-top:0">{{ $address->address }}</h3>
                            @unless ($nat)
                                <div class="field">
                                    <label for="rdns-{{ $address->id }}">{{ __('rDNS') }}</label>
                                    <input id="rdns-{{ $address->id }}" name="rdns" type="text" value="{{ $address->rdns }}" placeholder="mail.example.com">
                                </div>
                            @else
                                <input type="hidden" name="rdns" value="{{ $address->rdns }}">
                            @endunless
                            <div class="field">
                                <label for="mac-{{ $address->id }}">{{ __('MAC wymagany przez dostawcę') }}</label>
                                <input id="mac-{{ $address->id }}" name="mac_address" type="text" class="mono" value="{{ $address->mac_address }}" placeholder="02:00:00:aa:bb:cc">
                                <div class="hint">{{ __('Karta maszyny z tym adresem dostanie ten MAC. Puste — MAC nadany przez panel.') }}</div>
                            </div>
                            <div class="btn-row" style="justify-content:flex-end">
                                <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                            </div>
                        </form>
                        @unless ($address->server_id)
                            <div class="btn-row" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border)">
                                <form method="POST" action="{{ route('panel.admin.ip-addresses.update', $address) }}" style="margin:0">
                                    @csrf
                                    <button class="btn btn-sm" type="submit" name="action" value="{{ $address->is_reserved ? 'unreserve' : 'reserve' }}">
                                        {{ $address->is_reserved ? __('Zwolnij rezerwację') : __('Zarezerwuj') }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('panel.admin.ip-addresses.update', $address) }}" style="margin:0"
                                      data-confirm="{{ __('Usunąć adres :address z bloku?', ['address' => $address->address]) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">{{ __('Usuń z bloku') }}</button>
                                </form>
                            </div>
                        @endunless
                    </dialog>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center; padding:24px">{{ __('Brak adresów spełniających kryteria.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
