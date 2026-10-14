@extends('layouts.panel')

@section('title', __('Urządzenia PCI — :name', ['name' => $node->name]))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Urządzenia PCI') }}</h1>
            <div class="meta-line"><span>{{ $node->name }}</span></div>
            <p class="lede">{{ __('GPU i inne karty przekazywane maszynom KVM (VFIO). Urządzenie należy do jednej maszyny naraz; zmiana działa po wyłączeniu i włączeniu maszyny albo od razu z restartem.') }}</p></div>
        <div class="btn-row"><a class="btn" href="{{ route('panel.admin.hypervisors.show', $node) }}">{{ __('← :name', ['name' => $node->name]) }}</a></div>
    </div>
    @error('pci') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror
    @error('custom_xml') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Zarejestrowane urządzenia') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Maszyna') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($assets as $asset)
                    <tr>
                        <td><strong>{{ $asset->name }}</strong> @if ($asset->custom_xml) <span class="pill neutral plain">XML</span> @endif</td>
                        <td><code>{{ $asset->pci_address }}</code> @if ($asset->iommu_group !== null) <span class="muted">IOMMU {{ $asset->iommu_group }}</span> @endif</td>
                        <td>{{ $asset->type }}</td>
                        <td>@if ($asset->server) <a href="{{ route('panel.servers.show', $asset->server) }}">{{ $asset->server->hostname }}</a> @else <span class="pill ok">{{ __('wolne') }}</span> @endif</td>
                        <td style="text-align:right">
                            <button class="btn btn-sm" type="button" onclick="document.getElementById('pci-edit-{{ $asset->id }}').showModal()">{{ __('Edytuj') }}</button>
                            <dialog id="pci-edit-{{ $asset->id }}" class="edit-modal" style="text-align:left">
                                <form method="POST" action="{{ route('panel.admin.pci.update', $asset) }}">
                                    @csrf @method('PUT')
                                    <h3 class="card-title">{{ $asset->pci_address }}</h3>
                                    <div class="grid grid-2">
                                        <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="150" value="{{ $asset->name }}"></div>
                                        <div class="field"><label>{{ __('Rodzaj') }}</label><select name="type">
                                            @foreach (\App\Models\PciAsset::TYPES as $t) <option value="{{ $t }}" @selected($asset->type === $t)>{{ $t }}</option> @endforeach
                                        </select></div>
                                    </div>
                                    <div class="field"><label>{{ __('Maszyna') }}</label><select name="server_id">
                                        <option value="">{{ __('— wolne —') }}</option>
                                        @foreach ($servers as $server) <option value="{{ $server->id }}" @selected($asset->server_id === $server->id)>{{ $server->hostname }}</option> @endforeach
                                    </select></div>
                                    <label class="check-line"><input type="checkbox" name="restart" value="1"> {{ __('Zastosuj od razu — wyłącz i włącz maszynę') }}</label>
                                    <div class="field" style="margin-top:10px"><label>{{ __('Własny XML hostdev (opcjonalnie)') }}</label>
                                        <textarea name="custom_xml" rows="5" spellcheck="false" style="font-family:var(--mono); font-size:12px">{{ $asset->custom_xml }}</textarea>
                                        <div class="hint">{{ __('Pusty = standardowy <hostdev managed="yes"> dla tego adresu. Własny XML zastępuje go w całości (np. ROM, multifunction).') }}</div></div>
                                    <div class="btn-row">
                                        <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                        <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                    </div>
                                </form>
                            </dialog>
                            @unless ($asset->server_id)
                                <form method="POST" action="{{ route('panel.admin.pci.destroy', $asset) }}" style="display:inline; margin:0" data-confirm="{{ __('Usunąć urządzenie z panelu?') }}">
                                    @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('Brak zarejestrowanych urządzeń — dodaj je z listy wykrytych poniżej.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Wykryte na węźle') }}
            @if ($scan) <span class="pill {{ $scan['iommu'] ? 'ok' : 'critical' }}">{{ $scan['iommu'] ? __('IOMMU włączone') : __('IOMMU wyłączone') }}</span> @endif</h3></div>
        @if ($scanError)
            <p class="muted" style="padding:16px">{{ __('Węzeł nie odpowiada: :error', ['error' => $scanError]) }}</p>
        @elseif ($scan)
            @unless ($scan['iommu'])
                <p class="hint" style="padding:0 16px">{{ __('Bez IOMMU urządzeń nie da się przekazać. Dodaj intel_iommu=on iommu=pt (Intel) albo amd_iommu=on (AMD) do parametrów jądra w GRUB i zrestartuj węzeł.') }}</p>
            @endunless
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Adres') }}</th><th>{{ __('Urządzenie') }}</th><th>{{ __('Klasa') }}</th><th>{{ __('Sterownik') }}</th><th>IOMMU</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($scan['devices'] as $device)
                        @continue(! in_array($device['type'] ?? 'other', ['vga', 'gpu', 'display', 'ethernet', 'network', 'infiniband', 'nvme', 'accelerator', 'audio'], true) && ! request()->boolean('all'))
                        <tr>
                            <td><code>{{ $device['address'] }}</code></td>
                            <td>{{ $device['name'] ?? ($device['vendor_id'].':'.$device['device_id']) }}</td>
                            <td>{{ $device['type'] }}</td>
                            <td>{{ $device['driver'] ?? '—' }}</td>
                            <td>{{ $device['iommu_group'] ?? '—' }}</td>
                            <td style="text-align:right">
                                @if ($device['asset_id'])
                                    <span class="pill neutral">{{ __('dodane') }}</span>
                                @else
                                    <form method="POST" action="{{ route('panel.admin.pci.store', $node) }}" style="margin:0">
                                        @csrf
                                        <input type="hidden" name="pci_address" value="{{ $device['address'] }}">
                                        <input type="hidden" name="name" value="{{ \Illuminate\Support\Str::limit($device['name'] ?? $device['address'], 150, '') }}">
                                        <input type="hidden" name="type" value="{{ in_array($device['type'], ['vga', 'gpu', 'display', 'accelerator'], true) ? 'gpu' : (in_array($device['type'], ['ethernet', 'network', 'infiniband'], true) ? 'nic' : ($device['type'] === 'nvme' ? 'nvme' : 'other')) }}">
                                        <input type="hidden" name="vendor_id" value="{{ $device['vendor_id'] }}">
                                        <input type="hidden" name="device_id" value="{{ $device['device_id'] }}">
                                        <input type="hidden" name="iommu_group" value="{{ $device['iommu_group'] }}">
                                        <button class="btn btn-sm" type="submit">{{ __('Dodaj') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint" style="padding:0 16px 12px"><a href="{{ request()->fullUrlWithQuery(['all' => 1]) }}">{{ __('Pokaż wszystkie urządzenia (także mostki i kontrolery)') }}</a></p>
        @endif
    </div>
@endsection
