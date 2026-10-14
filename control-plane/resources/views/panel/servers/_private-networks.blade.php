{{-- Sieci prywatne: izolowany mostek na węźle, dodatkowe karty maszyny. --}}
@php
    $canEditNics = auth()->user()->can('firewall', $server);
    $nics = $server->isContainer() ? collect() : $server->nics()->with('network')->get();
    $ownNetworks = $server->isContainer() || ! $server->hypervisor_id ? collect()
        : \App\Models\PrivateNetwork::query()->where('user_id', $server->user_id)->where('hypervisor_id', $server->hypervisor_id)->withCount('nics')->orderBy('name')->get();
    $joinable = $ownNetworks->whereNotIn('id', $nics->pluck('private_network_id'));
    $suggested = $canEditNics && $server->user ? app(\App\Domain\Network\PrivateNetworks::class)->suggestCidr($server->user) : null;
@endphp

@if (! $server->isContainer() && $server->hypervisor_id && ($canEditNics || $nics->isNotEmpty()))
    <div class="card" id="private-networks" style="margin-top:16px">
        <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Sieci prywatne') }}</h3>
        <p class="hint" style="margin-top:0">{{ __('Izolowana sieć między Twoimi maszynami na tym samym węźle — ruch nie wychodzi do internetu i nie jest liczony do transferu. Każda sieć to osobna karta sieciowa maszyny.') }}</p>
        @error('private_network') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror

        @if ($nics->isNotEmpty())
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Sieć') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Karta') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($nics as $nic)
                        <tr>
                            <td>{{ $nic->network->name }} <span class="muted">{{ $nic->network->cidr }}</span></td>
                            <td><code>{{ $nic->address ? $nic->address.'/'.$nic->network->prefix() : '—' }}</code></td>
                            <td><span class="muted">#{{ $nic->slot + 1 }}</span> <code>{{ $nic->effectiveMac() }}</code></td>
                            <td style="text-align:right">
                                @if ($canEditNics)
                                    <form method="POST" action="{{ route('panel.servers.nics.detach', [$server, $nic]) }}" style="margin:0"
                                          data-confirm="{{ __('Odłączyć maszynę od sieci :name?', ['name' => $nic->network->name]) }}">
                                        @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Odłącz') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint">{{ __('Adres karty ustawia system przy tworzeniu i reinstalacji. Kartę podłączoną do działającej maszyny skonfiguruj w systemie (adres jak wyżej, bez bramy) albo zreinstaluj system.') }}</p>
        @endif

        @if ($canEditNics)
            <form method="POST" action="{{ route('panel.servers.private-networks.attach', $server) }}" class="grid grid-3" style="margin-top:12px;align-items:end">
                @csrf
                <div class="field"><label>{{ __('Sieć') }}</label>
                    <select name="network_id">
                        @foreach ($joinable as $network)
                            <option value="{{ $network->id }}">{{ $network->name }} ({{ $network->cidr }}, {{ __('maszyn: :count', ['count' => $network->nics_count]) }})</option>
                        @endforeach
                        <option value="" @selected($joinable->isEmpty())>{{ __('+ nowa sieć') }}</option>
                    </select></div>
                <div class="field"><label>{{ __('Nazwa nowej sieci') }}</label><input name="name" maxlength="64" placeholder="{{ __('np. backend') }}" value="{{ old('name') }}"></div>
                <div class="field"><label>{{ __('Adresacja nowej sieci') }}</label><input name="cidr" maxlength="18" placeholder="{{ $suggested }}" value="{{ old('cidr') }}"></div>
                <div class="field"><label>{{ __('Adres maszyny (opcjonalnie)') }}</label><input name="address" maxlength="15" placeholder="{{ __('pierwszy wolny') }}" value="{{ old('address') }}"></div>
                <div class="field"><button class="btn btn-primary" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Podłącz') }}</button></div>
            </form>

            @php $empty = $ownNetworks->where('nics_count', 0); @endphp
            @if ($empty->isNotEmpty())
                <div class="hint" style="margin-top:8px">{{ __('Puste sieci:') }}
                    @foreach ($empty as $network)
                        <form method="POST" action="{{ route('panel.servers.private-networks.destroy', [$server, $network]) }}" style="display:inline;margin:0"
                              data-confirm="{{ __('Usunąć sieć :name?', ['name' => $network->name]) }}">
                            @csrf @method('DELETE') <code>{{ $network->name }}</code> <button class="btn btn-sm" type="submit">{{ __('Usuń') }}</button>
                        </form>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
@endif
