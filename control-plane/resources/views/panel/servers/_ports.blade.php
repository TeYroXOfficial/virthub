{{-- Przekierowania portów NAT: blok portów węzła → usługi w maszynie. --}}
@php
    use App\Domain\Network\PortForwarding as PF;
    $natIps = $server->ipAddresses->filter(fn ($ip) => $ip->natPorts() !== null);
    $canEdit = auth()->user()->can('firewall', $server);
    $host = fn ($ip) => $ip->natEndpoint();
@endphp

@if ($natIps->isNotEmpty())
    <div class="card" id="ports" style="margin-top:16px">
        <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Przekierowania portów') }}</h3>
        <p class="hint" style="margin-top:0">
            {{ __('Maszyna za NAT-em jest osiągalna z zewnątrz tylko przez swój blok portów węzła. Pierwsze porty bloku są na stałe przypisane do dostępu do maszyny (SSH/SFTP, a w Windows także RDP). Pozostałe możesz skierować na dowolny port w maszynie — nieustawione przechodzą na ten sam numer.') }}
        </p>

        @foreach ($natIps as $ip)
            @php
                $mapping = PF::mapping($ip, $server);
                // Porty 1:1 zwijamy w zakresy — blok może mieć setki portów.
                $rows = [];
                foreach ($mapping as $entry) {
                    $last = end($rows);
                    if ($entry['kind'] === PF::KIND_DIRECT && $last && $last['kind'] === PF::KIND_DIRECT && $last['to'] === $entry['external'] - 1) {
                        $rows[array_key_last($rows)]['to'] = $entry['external'];
                    } else {
                        $rows[] = [...$entry, 'to' => $entry['external']];
                    }
                }
                $free = collect($mapping)->where('kind', '!=', PF::KIND_FIXED)->pluck('external');
            @endphp

            <div class="table-wrap" style="margin-bottom:12px">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('Port zewnętrzny') }}</th>
                        <th></th>
                        <th>{{ __('Port w maszynie') }}</th>
                        <th>{{ __('Usługa') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="mono">{{ $host($ip) }}:{{ $row['external'] }}@if ($row['to'] > $row['external'])–{{ $row['to'] }}@endif</td>
                            <td class="muted">→</td>
                            <td class="mono">
                                @if ($row['kind'] === PF::KIND_DIRECT)
                                    {{ __('ten sam numer') }}
                                @else
                                    {{ $ip->address }}:{{ $row['internal'] }}
                                @endif
                            </td>
                            <td>
                                @if ($row['kind'] === PF::KIND_FIXED)
                                    {{ $row['label'] }} <span class="pill neutral plain">{{ __('stały') }}</span>
                                @elseif ($row['kind'] === PF::KIND_CUSTOM)
                                    {{ $row['label'] ?? '—' }}
                                @else
                                    <span class="muted">{{ __('1:1') }}</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                @if ($row['kind'] === PF::KIND_CUSTOM && $canEdit)
                                    <form method="POST" action="{{ route('panel.servers.ports.destroy', [$server, $row['id']]) }}" style="margin:0">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm" type="submit">{{ __('Przywróć 1:1') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canEdit && $free->isNotEmpty())
                <form method="POST" action="{{ route('panel.servers.ports.store', $server) }}">
                    @csrf
                    <input type="hidden" name="ip_address_id" value="{{ $ip->id }}">
                    <div class="grid-compact">
                        <div class="field">
                            <label for="pf-ext-{{ $ip->id }}">{{ __('Port zewnętrzny') }}</label>
                            <select id="pf-ext-{{ $ip->id }}" name="external_port">
                                @foreach ($free as $port)
                                    <option value="{{ $port }}" @selected((int) old('external_port') === $port)>{{ $port }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="pf-int-{{ $ip->id }}">{{ __('Port w maszynie') }}</label>
                            <input id="pf-int-{{ $ip->id }}" name="internal_port" type="text" inputmode="numeric" required
                                   value="{{ old('internal_port') }}" placeholder="80">
                        </div>
                        <div class="field">
                            <label for="pf-label-{{ $ip->id }}">{{ __('Usługa') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                            <input id="pf-label-{{ $ip->id }}" name="label" type="text" maxlength="40"
                                   value="{{ old('label') }}" placeholder="{{ __('np. HTTP') }}">
                        </div>
                    </div>
                    @error('external_port') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    @error('internal_port') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Zapisz przekierowanie') }}</button>
                </form>
            @endif
        @endforeach
    </div>
@endif
