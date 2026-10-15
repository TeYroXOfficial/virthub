@php $c = $ds ? (array) $ds->config : []; $attached = $ds ? $ds->hypervisors->pluck('id')->all() : []; @endphp
<div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $ds?->name }}" placeholder="{{ __('np. :type NVMe', ['type' => \App\Models\Datastore::labelFor($type)]) }}"></div>
@php
    $mono = 'font-family:var(--mono); font-size:12px';
    $keep = $ds ? __('bez zmiany') : '';
    $optionsField = fn ($placeholder) => '<div class="field"><label>'.e(__('Opcje montowania')).' <span class="muted">'.e(__('(opcjonalnie)')).'</span></label><input name="config[options]" value="'.e($c['options'] ?? '').'" placeholder="'.e($placeholder).'"></div>';
@endphp
@if ($type === 'ceph' || $type === 'cephfs')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Monitory') }}</label><input name="config[monitors]" required value="{{ implode(', ', (array) ($c['monitors'] ?? [])) }}" placeholder="10.0.0.11, 10.0.0.12, 10.0.0.13">
            <div class="hint">{{ __('Adres albo adres:port (domyślnie 6789), oddzielone przecinkami.') }}</div></div>
        @if ($type === 'ceph')
            <div class="field"><label>{{ __('Pula RBD') }}</label><input name="config[pool]" required value="{{ $c['pool'] ?? '' }}" placeholder="vms"></div>
        @else
            <div class="field"><label>{{ __('Katalog w CephFS') }}</label><input name="config[export]" value="{{ $c['export'] ?? '/' }}" placeholder="/virthub"></div>
        @endif
        <div class="field"><label>{{ __('Użytkownik Cepha') }}</label><input name="config[user]" value="{{ $c['user'] ?? 'libvirt' }}" autocomplete="off">
            <div class="hint">{{ __('Bez przedrostka client. — np. libvirt.') }}</div></div>
    </div>
    <div class="field"><label>{{ __('Klucz') }}</label><input name="config[key]" type="password" autocomplete="new-password" @unless ($ds) required @endunless placeholder="{{ $ds ? __('bez zmiany') : 'AQB…==' }}">
        @if ($type === 'ceph')
            <div class="hint">{{ __('Wynik ceph auth get-key client.UŻYTKOWNIK. Użytkownik potrzebuje uprawnień: mon „profile rbd”, osd „profile rbd pool=PULA”. Klucz jest szyfrowany w bazie i trafia do sekretu libvirt na węźle.') }}</div>
        @else
            <div class="hint">{{ __('Wynik ceph auth get-key client.UŻYTKOWNIK (ceph fs authorize NAZWA_FS client.UŻYTKOWNIK / rw). Agent montuje CephFS jądrem — klucz trafia tylko do pliku tymczasowego na czas montowania.') }}</div>
        @endif
    </div>
    @if ($type === 'cephfs') {!! $optionsField('noatime') !!} @endif
@elseif ($type === 'nfs' || $type === 'cifs')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Serwer') }}</label><input name="config[server]" required value="{{ $c['server'] ?? '' }}" placeholder="10.0.0.20"></div>
        <div class="field"><label>{{ $type === 'nfs' ? __('Eksport') : __('Udział') }}</label><input name="config[export]" required value="{{ $c['export'] ?? '' }}" placeholder="{{ $type === 'nfs' ? '/srv/virthub' : 'virthub' }}"></div>
        {!! $optionsField($type === 'nfs' ? 'vers=4.2,nconnect=4' : 'vers=3.1.1,uid=libvirt-qemu') !!}
        @if ($type === 'cifs')
            <div class="field"><label>{{ __('Użytkownik') }}</label><input name="config[username]" value="{{ $c['username'] ?? '' }}" autocomplete="off"></div>
            <div class="field"><label>{{ __('Hasło') }}</label><input name="config[password]" type="password" autocomplete="new-password" placeholder="{{ $keep }}"></div>
        @endif
    </div>
    <p class="hint">{{ $type === 'nfs'
        ? __('Agent montuje eksport (mount -t nfs, opcje hard,_netdev) w /var/lib/virthub/mnt/ID na każdym przypiętym węźle i trzyma na nim pliki qcow2. Eksport musi pozwalać na zapis z adresów węzłów (no_root_squash albo właściciel libvirt-qemu).')
        : __('Agent montuje udział (mount -t cifs, SMB 3) w /var/lib/virthub/mnt/ID. Hasło trafia tylko do pliku credentials na czas montowania.') }}</p>
@elseif ($type === 'iscsi')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Portal') }}</label><input name="config[portal]" required value="{{ $c['portal'] ?? '' }}" placeholder="10.0.0.30:3260"></div>
        <div class="field"><label>{{ __('IQN celu') }}</label><input name="config[iqn]" required value="{{ $c['iqn'] ?? '' }}" placeholder="iqn.2026-01.pl.example:virthub" style="{{ $mono }}"></div>
        <div class="field"><label>LUN</label><input name="config[lun]" type="number" min="0" max="16383" value="{{ $c['lun'] ?? 0 }}"></div>
        <div class="field"><label>{{ __('Użytkownik CHAP') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label><input name="config[username]" value="{{ $c['username'] ?? '' }}" autocomplete="off"></div>
        <div class="field"><label>{{ __('Hasło CHAP') }}</label><input name="config[password]" type="password" autocomplete="new-password" placeholder="{{ $keep }}"></div>
        {!! $optionsField('noatime') !!}
    </div>
    <p class="hint">{{ __('Agent loguje się do celu (iscsiadm) i montuje LUN w /var/lib/virthub/mnt/ID. LUN musi mieć gotowy system plików — klastrowy (OCFS2, GFS2), jeśli przypinasz magazyn do kilku węzłów; zwykły ext4/XFS tylko dla jednego węzła.') }}</p>
@elseif ($type === 'storpool')
    <div class="field"><label>{{ __('Szablon StorPool') }}</label><input name="config[template]" required value="{{ $c['template'] ?? '' }}" placeholder="nvme">
        <div class="hint">{{ __('Szablon określa replikację i rozmieszczenie (storpool template list). Węzły muszą być klientami klastra StorPool (storpool_block); dysk maszyny to wolumin podpięty jako /dev/storpool/NAZWA.') }}</div></div>
@elseif ($type === 'lightbits')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Punkt API') }}</label><input name="config[endpoint]" required value="{{ $c['endpoint'] ?? '' }}" placeholder="10.0.0.40:443"></div>
        <div class="field"><label>{{ __('NQN podsystemu') }}</label><input name="config[nqn]" required value="{{ $c['nqn'] ?? '' }}" placeholder="nqn.2016-01.com.lightbitslabs:uuid:…" style="{{ $mono }}"></div>
        <div class="field"><label>{{ __('Adresy danych NVMe/TCP') }}</label><input name="config[targets]" required value="{{ implode(', ', (array) ($c['targets'] ?? [])) }}" placeholder="10.0.0.41:4420, 10.0.0.42:4420"></div>
        <div class="field"><label>{{ __('Projekt') }}</label><input name="config[project]" value="{{ $c['project'] ?? 'default' }}"></div>
        <div class="field"><label>{{ __('Repliki') }}</label><input name="config[replicas]" type="number" min="1" max="3" value="{{ $c['replicas'] ?? 2 }}"></div>
    </div>
    <div class="field"><label>{{ __('Token JWT') }}</label><input name="config[jwt]" type="password" autocomplete="new-password" @unless ($ds) required @endunless placeholder="{{ $keep }}">
        <div class="hint">{{ __('Token z uprawnieniami do projektu (lbcli). Węzły potrzebują lbcli i nvme-cli; wolumin dostaje ACL z NQN hosta, który go utworzył. NQN podsystemu i adresy danych: lbcli get cluster, lbcli list nodes.') }}</div></div>
@else
    <div class="field"><label>{{ __('Katalog na węzłach') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/var/lib/virthub/datastore' }}">
        <div class="hint">{{ __('Na każdym przypiętym węźle ta sama ścieżka — np. drugi dysk, macierz albo NFS. Agent i QEMU (libvirt-qemu) muszą mieć do niego dostęp.') }}</div></div>
@endif
@if (! in_array($type, \App\Models\Datastore::BLOCK, true))
    <label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="thin" value="1" @checked($ds?->thin ?? true)> {{ __('Cienkie przydzielanie') }}
        <span class="muted">— {{ __('dysk zajmuje tyle, ile zapisano; bez tego pełny rozmiar jest rezerwowany od razu') }}</span></label>
@endif
@if (in_array($type, \App\Models\Datastore::EXPERIMENTAL, true))
    <p class="hint"><span class="pill warning">{{ __('eksperymentalny') }}</span> {{ __('Sterownik zgodny z dokumentacją producenta, ale niesprawdzony na prawdziwym klastrze. Migawki: StorPool tak, Lightbits nie. Kopie zewnętrzne są pełne.') }}</p>
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
