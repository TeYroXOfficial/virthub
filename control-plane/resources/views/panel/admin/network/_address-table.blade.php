{{-- Tabela adresów: stan, usługa, której adres jest przypisany, porty NAT, akcje dla wolnych. --}}
<div class="table-wrap">
    <table>
        <thead><tr>
            <th>{{ __('Adres') }}</th>
            @if ($showPool) <th>{{ __('Blok') }}</th> @endif
            <th>{{ __('Węzeł') }}</th>
            <th>{{ __('Stan') }}</th>
            <th>{{ __('Usługa') }}</th>
            @if ($nat) <th>{{ __('Porty NAT') }}</th> @else <th>{{ __('rDNS') }}</th> @endif
            <th>{{ __('Od') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($addresses as $address)
            <tr>
                <td class="mono nowrap"><strong>{{ $address->address }}</strong>
                    @if ($address->is_primary) <span class="pill neutral plain">{{ __('główny') }}</span> @endif</td>
                @if ($showPool)
                    <td class="nowrap">@if ($address->pool)<a href="{{ route('panel.admin.ip-pools.show', $address->pool) }}">{{ $address->pool->name }}</a>@else — @endif</td>
                @endif
                <td class="muted nowrap">{{ $address->hypervisor?->name ?? __('grupa') }}</td>
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
                        <div class="hint">{{ $address->server->user?->email }}</div>
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
                <td class="muted nowrap">{{ $address->assigned_at?->format('d.m.Y') ?? '—' }}</td>
                <td style="text-align:right; white-space:nowrap">
                    @unless ($address->server_id)
                        <form method="POST" action="{{ route('panel.admin.ip-addresses.update', $address) }}" style="display:inline">
                            @csrf
                            <button class="btn btn-sm" type="submit" name="action" value="{{ $address->is_reserved ? 'unreserve' : 'reserve' }}">
                                {{ $address->is_reserved ? __('Zwolnij rezerwację') : __('Zarezerwuj') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('panel.admin.ip-addresses.update', $address) }}" style="display:inline"
                              data-confirm="{{ __('Usunąć adres :address z bloku?', ['address' => $address->address]) }}">
                            @csrf
                            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">{{ __('Usuń') }}</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Brak adresów spełniających kryteria.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
