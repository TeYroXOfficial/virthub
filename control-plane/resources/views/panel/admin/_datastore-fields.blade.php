@php $c = $ds ? (array) $ds->config : []; $attached = $ds ? $ds->hypervisors->pluck('id')->all() : []; @endphp
<div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $ds?->name }}" placeholder="{{ $type === 'ceph' ? __('np. Ceph NVMe') : __('np. Dysk NVMe') }}"></div>
@if ($type === 'ceph')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Monitory') }}</label><input name="config[monitors]" required value="{{ implode(', ', (array) ($c['monitors'] ?? [])) }}" placeholder="10.0.0.11, 10.0.0.12, 10.0.0.13">
            <div class="hint">{{ __('Adres albo adres:port (domyślnie 6789), oddzielone przecinkami.') }}</div></div>
        <div class="field"><label>{{ __('Pula RBD') }}</label><input name="config[pool]" required value="{{ $c['pool'] ?? '' }}" placeholder="vms"></div>
        <div class="field"><label>{{ __('Użytkownik Cepha') }}</label><input name="config[user]" value="{{ $c['user'] ?? 'libvirt' }}" autocomplete="off">
            <div class="hint">{{ __('Bez przedrostka client. — np. libvirt.') }}</div></div>
    </div>
    <div class="field"><label>{{ __('Klucz') }}</label><input name="config[key]" type="password" autocomplete="new-password" @unless ($ds) required @endunless placeholder="{{ $ds ? __('bez zmiany') : 'AQB…==' }}">
        <div class="hint">{{ __('Wynik ceph auth get-key client.UŻYTKOWNIK. Użytkownik potrzebuje uprawnień: mon „profile rbd”, osd „profile rbd pool=PULA”. Klucz jest szyfrowany w bazie i trafia do sekretu libvirt na węźle.') }}</div></div>
@else
    <div class="field"><label>{{ __('Katalog na węzłach') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/var/lib/virthub/datastore' }}">
        <div class="hint">{{ __('Na każdym przypiętym węźle ta sama ścieżka — np. drugi dysk, macierz albo NFS. Agent i QEMU (libvirt-qemu) muszą mieć do niego dostęp.') }}</div></div>
    <label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="thin" value="1" @checked($ds?->thin ?? true)> {{ __('Cienkie przydzielanie') }}
        <span class="muted">— {{ __('dysk zajmuje tyle, ile zapisano; bez tego pełny rozmiar jest rezerwowany od razu') }}</span></label>
@endif
<fieldset class="field" style="border:0; padding:0; margin:0 0 10px">
    <legend style="font-weight:600; margin-bottom:6px">{{ __('Węzły z dostępem do magazynu') }}</legend>
    @forelse ($nodes as $node)
        <label class="check-line"><input type="checkbox" name="hypervisors[]" value="{{ $node->id }}" @checked(in_array($node->id, $attached, true))> {{ $node->name }}</label>
    @empty
        <p class="muted">{{ __('Brak hypervisorów KVM.') }}</p>
    @endforelse
</fieldset>
<label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="is_active" value="1" @checked($ds?->is_active ?? true)> {{ __('Aktywny (nowe maszyny mogą w nim powstawać)') }}</label>
