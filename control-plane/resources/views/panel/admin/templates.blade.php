@extends('layouts.panel')

@section('title', __('Systemy i szablony'))

@php
    $families = \App\Models\OsTemplateGroup::FAMILIES;
    $byGroup = $templates->groupBy(fn ($t) => $t->os_template_group_id ?? 0);
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Systemy i szablony') }}</h1>
            <p class="lede">
                {{ __('System (np. Ubuntu) grupuje swoje wersje — klient wybiera najpierw system, potem wersję. Każda wersja to szablon: obraz qcow2 dla KVM albo alias obrazu dla kontenerów.') }}
            </p>
        </div>
    </div>

    @include('panel.admin._nav')

    <div class="grid grid-2">
        <details class="form-block card" @if($groups->isEmpty()) open @endif>
            <summary>{{ __('Dodaj system') }}</summary>
            <form method="POST" action="{{ route('panel.admin.template-groups.store') }}" style="margin-top:12px">
                @csrf
                @include('panel.admin._template-group-fields', ['group' => null, 'idp' => 'new'])
                <button class="btn btn-primary" type="submit">{{ __('Dodaj system') }}</button>
            </form>
        </details>

        <details class="form-block card" id="add-version" @if($errors->hasAny(['image_file', 'version'])) open @endif>
            <summary>{{ __('Dodaj wersję') }}</summary>
            <form method="POST" action="{{ route('panel.admin.templates.store') }}" style="margin-top:12px">
                @csrf
                <div class="grid grid-2">
                    <div class="field">
                        <label for="t-group">{{ __('System') }}</label>
                        <select id="t-group" name="os_template_group_id" required>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected((int) old('os_template_group_id', request('group')) === $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="t-virt">{{ __('Rodzaj') }}</label>
                        <select id="t-virt" name="virtualization" required>
                            <option value="kvm" @selected(old('virtualization', 'kvm') === 'kvm')>{{ __('Maszyna wirtualna (KVM)') }}</option>
                            <option value="lxc" @selected(old('virtualization') === 'lxc')>{{ __('Kontener (LXC)') }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="t-name">{{ __('Nazwa wyświetlana') }}</label>
                        <input id="t-name" name="name" type="text" value="{{ old('name') }}" placeholder="{{ __('Ubuntu 24.04 LTS') }}" required>
                    </div>
                    <div class="field">
                        <label for="t-version">{{ __('Wersja') }}</label>
                        <input id="t-version" name="version" type="text" value="{{ old('version') }}" placeholder="24.04" required>
                    </div>
                    <div class="field">
                        <label for="t-disk">{{ __('Minimalny dysk (GB)') }}</label>
                        <input id="t-disk" name="min_disk_gb" type="text" inputmode="numeric" value="{{ old('min_disk_gb', 10) }}" required>
                    </div>
                    <div class="field">
                        <label for="t-sort">{{ __('Kolejność') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                        <input id="t-sort" name="sort_order" type="text" inputmode="numeric" value="{{ old('sort_order', 0) }}">
                        <div class="hint">{{ __('0 = sortuj po wersji, od najnowszej.') }}</div>
                    </div>
                </div>
                <div class="field">
                    <label for="t-image">{{ __('Plik obrazu (KVM) albo alias obrazu (LXC)') }}</label>
                    <input id="t-image" name="image_file" type="text" value="{{ old('image_file') }}"
                           placeholder="{{ __('ubuntu-24.04.qcow2 albo ubuntu/24.04/cloud') }}" required>
                    <div class="hint">
                        {{ __('KVM: sama nazwa pliku z') }} <span class="mono">{{ __('/var/lib/virthub/templates') }}</span> {{ __('na węzłach. LXC: alias z') }} <span class="mono">{{ __('images.linuxcontainers.org') }}</span>{{ __(', wariant') }} <span class="mono">{{ __('/cloud') }}</span>.
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Dodaj wersję') }}</button>
            </form>
        </details>
    </div>

    @foreach ($groups->concat($byGroup->has(0) ? [null] : []) as $group)
        @php $versions = $byGroup->get($group?->id ?? 0, collect()); @endphp
        <div class="card" id="system-{{ $group?->id ?? 'other' }}">
            <h3 class="card-title">
                @include('panel.servers._os-badge', ['template' => (object) ['family' => $group?->family ?? 'linux', 'name' => $group?->name ?? __('Inne')]])
                <span>{{ $group?->name ?? __('Bez systemu') }}</span>
                <span style="margin-left:auto; display:flex; gap:6px">
                    @if ($group && ! $group->is_active)
                        <span class="pill neutral">{{ __('ukryty') }}</span>
                    @endif
                    <span class="pill neutral">{{ $versions->count() }} {{ $versions->count() === 1 ? __('wersja') : __('wersji') }}</span>
                </span>
            </h3>
            @if ($group?->description)
                <p class="muted" style="margin-top:0">{{ $group->description }}</p>
            @endif

            @if ($versions->isEmpty())
                <p class="muted">{{ __('Brak wersji.') }} <a href="{{ route('panel.admin.templates', ['group' => $group?->id]) }}#add-version">{{ __('Dodaj pierwszą') }}</a>.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Wersja') }}</th><th>{{ __('Obraz') }}</th><th>{{ __('Na węzłach') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($versions as $template)
                            <tr>
                                <td style="min-width:170px">
                                    <strong>{{ $template->name }}</strong>
                                    <span class="pill neutral">{{ $template->virtualization->shortLabel() }}</span>
                                    <div class="hint">{{ __('wersja :version · min. :min_disk_gb GB · :servers_count maszyn', ['version' => $template->version, 'min_disk_gb' => $template->min_disk_gb, 'servers_count' => $template->servers_count]) }}</div>
                                </td>
                                <td class="mono" style="font-size:12.5px">{{ $template->image_file }}</td>
                                <td>
                                    @if (! $template->isContainer())
                                        <span class="muted">{{ __('wgrywany ręcznie') }}</span>
                                    @elseif ($template->downloads->isEmpty())
                                        <span class="muted">{{ __('brak węzłów kontenerów') }}</span>
                                    @else
                                        @foreach ($template->downloads as $download)
                                            @if ($download->isInProgress())
                                                @include('panel.admin._download-progress', ['kind' => 'template'])
                                            @else
                                                <span class="pill {{ $download->tone() }}" @if($download->error) title="{{ $download->error }}" @endif>
                                                    {{ $download->hypervisor->name }}: {{ $download->label() }}
                                                </span>
                                                @if ($download->error)
                                                    <div class="hint" style="color:var(--critical)">{{ $download->error }}</div>
                                                @endif
                                            @endif
                                        @endforeach
                                    @endif
                                </td>
                                <td>
                                    <span class="pill {{ $template->is_active ? 'ok' : 'neutral' }}">{{ $template->is_active ? __('dostępny') : __('wyłączony') }}</span>
                                    @unless ($template->cloud_init_support)
                                        <div class="hint">{{ __('bez cloud-init — tylko reinstalacja z ISO') }}</div>
                                    @endunless
                                </td>
                                <td style="text-align:right">
                                    <div class="btn-row" style="justify-content:flex-end; flex-wrap:nowrap">
                                    @if ($template->isContainer() && $template->downloads->contains('status', 'failed'))
                                        <form method="POST" action="{{ route('panel.admin.templates.retry', $template) }}" style="margin:0">
                                            @csrf <button class="btn btn-sm" type="submit">{{ __('Ponów pobieranie') }}</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('panel.admin.templates.toggle', $template) }}" style="margin:0">
                                        @csrf <button class="btn btn-sm" type="submit">{{ $template->is_active ? __('Wyłącz') : __('Włącz') }}</button>
                                    </form>
                                    <button class="btn btn-sm" type="button" onclick="document.getElementById('edit-t{{ $template->id }}').hidden ^= true">{{ __('Edytuj') }}</button>
                                    @if ($template->servers_count === 0)
                                        <form method="POST" action="{{ route('panel.admin.templates.destroy', $template) }}" style="margin:0"
                                              onsubmit="return confirm(@js(__('Usunąć :name?', ['name' => $template->name])))">
                                            @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                        </form>
                                    @endif
                                    </div>
                                </td>
                            </tr>
                            <tr id="edit-t{{ $template->id }}" hidden>
                                <td colspan="5" style="background:var(--surface-2)">
                                    <form method="POST" action="{{ route('panel.admin.templates.update', $template) }}">
                                        @csrf @method('PUT')
                                        <div class="grid grid-4">
                                            <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" type="text" value="{{ $template->name }}" required></div>
                                            <div class="field"><label>{{ __('Wersja') }}</label><input name="version" type="text" value="{{ $template->version }}" required></div>
                                            <div class="field">
                                                <label>{{ __('System') }}</label>
                                                <select name="os_template_group_id">
                                                    <option value="">{{ __('— bez systemu —') }}</option>
                                                    @foreach ($groups as $g)
                                                        <option value="{{ $g->id }}" @selected($template->os_template_group_id === $g->id)>{{ $g->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="field"><label>{{ __('Min. dysk (GB)') }}</label><input name="min_disk_gb" type="text" inputmode="numeric" value="{{ $template->min_disk_gb }}" required></div>
                                            <div class="field"><label>{{ __('Kolejność') }}</label><input name="sort_order" type="text" inputmode="numeric" value="{{ $template->sort_order }}"></div>
                                        </div>
                                        <button class="btn btn-sm btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($group)
                <div class="btn-row" style="margin-top:12px">
                    <a class="btn btn-sm" href="{{ route('panel.admin.templates', ['group' => $group->id]) }}#add-version">{{ __('Dodaj wersję') }}</a>
                    <form method="POST" action="{{ route('panel.admin.template-groups.toggle', $group) }}" style="margin:0">
                        @csrf <button class="btn btn-sm" type="submit">{{ $group->is_active ? __('Ukryj system') : __('Pokaż system') }}</button>
                    </form>
                    @if ($versions->isEmpty())
                        <form method="POST" action="{{ route('panel.admin.template-groups.destroy', $group) }}" style="margin:0"
                              onsubmit="return confirm(@js(__('Usunąć system :name?', ['name' => $group->name])))">
                            @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń system') }}</button>
                        </form>
                    @endif
                </div>
                <details class="form-block" style="margin-top:12px">
                    <summary>{{ __('Ustawienia systemu') }}</summary>
                    <form method="POST" action="{{ route('panel.admin.template-groups.update', $group) }}">
                        @csrf @method('PUT')
                        @include('panel.admin._template-group-fields', ['group' => $group, 'idp' => $group->id])
                        <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                    </form>
                </details>
            @endif
        </div>
    @endforeach

    <h2 class="section-head">{{ __('Źródła obrazów') }}</h2>
    {{-- Kontenery: katalog gotowych szablonów -------------------------------- --}}
    <div class="card">
        <h3>{{ __('Katalog kontenerów (LXC)') }}</h3>
        <p>
            {{ __('Dla węzłów bez sprzętowej wirtualizacji. Dodany szablon panel sam rozsyła na wszystkie węzły kontenerów, a każdy węzeł pobiera go z serwera obrazów') }}
            <span class="mono">{{ __('images.linuxcontainers.org') }}</span>{{ __('. Nic nie trzeba wgrywać ręcznie.') }}
        </p>
        @if ($containerNodes === 0)
            <p class="hint">
                {{ __('Nie masz jeszcze węzła kontenerów. Szablony możesz dodać już teraz — pobiorą się automatycznie, gdy pierwszy taki węzeł dołączy do floty.') }}
            </p>
        @endif

        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('System') }}</th><th>{{ __('Alias obrazu') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($catalog as $entry)
                    <tr>
                        <td>{{ $entry['name'] }}</td>
                        <td class="mono">{{ $entry['alias'] }}</td>
                        <td style="text-align:right">
                            @if ($entry['added'])
                                <span class="pill ok">{{ __('dodany') }}</span>
                            @else
                                <form method="POST" action="{{ route('panel.admin.templates.catalog', $entry['key']) }}">
                                    @csrf
                                    <button class="btn btn-primary" type="submit">{{ __('Dodaj') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- KVM: obrazy qcow2 wgrywane na węzły ---------------------------------- --}}
    <div class="card">
        <h3>{{ __('Szablony maszyn wirtualnych (KVM)') }}</h3>
        <p>
            {{ __('Obraz qcow2 musi leżeć w katalogu') }} <span class="mono">{{ __('/var/lib/virthub/templates') }}</span>
            {{ __('na') }} <strong>{{ __('każdym') }}</strong> {{ __('węźle KVM. Instalator węzła pobiera Ubuntu 24.04 sam; kolejne obrazy wgrywasz tak:') }}
        </p>
        <p class="secret">cd /var/lib/virthub/templates && curl -LO https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2 && mv debian-12-genericcloud-amd64.qcow2 debian-12.qcow2</p>
        <p class="hint">
            {{ __('Obrazu bazowego nie wolno usunąć ani nadpisać, dopóki istnieje choć jedna maszyna, która go używa — dyski klientów są cienkimi warstwami nad tym plikiem.') }}
        </p>
    </div>


    <p class="hint">
        {{ __('Szablony bez cloud-init (m.in. Windows) nie są dostępne w samoobsłudze — cloud-init wstrzykuje hasło, klucz SSH i konfigurację sieci przy pierwszym starcie.') }}
    </p>

    @if (request('group'))
        <script>document.getElementById('add-version')?.setAttribute('open', '');</script>
    @endif
    @push('scripts')
        <script src="{{ asset('js/downloads.js') }}?v={{ @filemtime(public_path('js/downloads.js')) }}"
                data-status-url="{{ route('panel.admin.downloads.status') }}"></script>
    @endpush
@endsection
