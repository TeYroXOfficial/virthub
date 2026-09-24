@extends('layouts.panel')

@section('title', 'Szablony systemów')

@section('content')
    <h1>Szablony systemów</h1>
    <p class="lede">Obrazy, z których stawiane są maszyny i kontenery klientów.</p>

    @include('panel.admin._nav')

    {{-- Kontenery: katalog gotowych szablonów -------------------------------- --}}
    <div class="card">
        <h3>Szablony kontenerów (LXC)</h3>
        <p>
            Dla węzłów bez sprzętowej wirtualizacji. Dodany szablon panel sam rozsyła na
            wszystkie węzły kontenerów, a każdy węzeł pobiera go z serwera obrazów
            <span class="mono">images.linuxcontainers.org</span>. Nic nie trzeba wgrywać ręcznie.
        </p>
        @if ($containerNodes === 0)
            <p class="hint">
                Nie masz jeszcze węzła kontenerów. Szablony możesz dodać już teraz — pobiorą się
                automatycznie, gdy pierwszy taki węzeł dołączy do floty.
            </p>
        @endif

        <div class="table-wrap">
            <table>
                <thead><tr><th>System</th><th>Alias obrazu</th><th></th></tr></thead>
                <tbody>
                @foreach ($catalog as $entry)
                    <tr>
                        <td>{{ $entry['name'] }}</td>
                        <td class="mono">{{ $entry['alias'] }}</td>
                        <td style="text-align:right">
                            @if ($entry['added'])
                                <span class="pill ok">dodany</span>
                            @else
                                <form method="POST" action="{{ route('panel.admin.templates.catalog', $entry['key']) }}">
                                    @csrf
                                    <button class="btn btn-primary" type="submit">Dodaj</button>
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
        <h3>Szablony maszyn wirtualnych (KVM)</h3>
        <p>
            Obraz qcow2 musi leżeć w katalogu <span class="mono">/var/lib/virthub/templates</span>
            na <strong>każdym</strong> węźle KVM. Instalator węzła pobiera Ubuntu 24.04 sam;
            kolejne obrazy wgrywasz tak:
        </p>
        <p class="secret">cd /var/lib/virthub/templates && curl -LO https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2 && mv debian-12-genericcloud-amd64.qcow2 debian-12.qcow2</p>
        <p class="hint">
            Obrazu bazowego nie wolno usunąć ani nadpisać, dopóki istnieje choć jedna maszyna,
            która go używa — dyski klientów są cienkimi warstwami nad tym plikiem.
        </p>
    </div>

    {{-- Formularz ręczny ------------------------------------------------------ --}}
    <details class="form-block card">
        <summary>Dodaj szablon ręcznie</summary>
        <form method="POST" action="{{ route('panel.admin.templates.store') }}" style="margin-top:12px">
            @csrf
            <div class="field">
                <label for="virtualization">Rodzaj</label>
                <select id="virtualization" name="virtualization" required>
                    <option value="kvm" @selected(old('virtualization', 'kvm') === 'kvm')>
                        Maszyna wirtualna (KVM) — plik qcow2 na węzłach
                    </option>
                    <option value="lxc" @selected(old('virtualization') === 'lxc')>
                        Kontener (LXC) — alias z serwera obrazów
                    </option>
                </select>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="name">Nazwa wyświetlana</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}"
                           placeholder="Ubuntu 24.04 LTS" required>
                </div>
                <div class="field">
                    <label for="family">Rodzina</label>
                    <select id="family" name="family" required>
                        @foreach (['ubuntu', 'debian', 'almalinux', 'rocky', 'fedora', 'windows'] as $family)
                            <option value="{{ $family }}" @selected(old('family') === $family)>{{ $family }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="version">Wersja</label>
                    <input id="version" name="version" type="text" value="{{ old('version') }}"
                           placeholder="24.04" required>
                </div>
                <div class="field">
                    <label for="min_disk_gb">Minimalny dysk (GB)</label>
                    <input id="min_disk_gb" name="min_disk_gb" type="text"
                           value="{{ old('min_disk_gb', 10) }}" required>
                </div>
            </div>
            <div class="field">
                <label for="image_file">Plik obrazu (KVM) albo alias obrazu (LXC)</label>
                <input id="image_file" name="image_file" type="text" value="{{ old('image_file') }}"
                       placeholder="ubuntu-24.04.qcow2 albo ubuntu/24.04/cloud" required>
                <div class="hint">
                    KVM: sama nazwa pliku, bez ścieżki. LXC: alias z
                    <span class="mono">images.linuxcontainers.org</span>, najlepiej wariant
                    <span class="mono">/cloud</span> — tylko on zawiera cloud-init. Kontenery wymagają
                    dystrybucji z systemd i pakietem <span class="mono">openssh-server</span>.
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj szablon</button>
        </form>
    </details>

    {{-- Lista ------------------------------------------------------------------ --}}
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Nazwa</th><th>Rodzaj</th><th>Obraz</th><th>Na węzłach</th>
                    <th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($templates as $template)
                    <tr>
                        <td>
                            {{ $template->name }}
                            <div class="hint">{{ $template->family }} {{ $template->version }}
                                · min. {{ $template->min_disk_gb }} GB</div>
                        </td>
                        <td>
                            <span class="pill neutral">{{ $template->virtualization->shortLabel() }}</span>
                        </td>
                        <td class="mono">{{ $template->image_file }}</td>
                        <td>
                            @if (! $template->isContainer())
                                <span class="muted">wgrywany ręcznie</span>
                            @elseif ($template->downloads->isEmpty())
                                <span class="muted">brak węzłów kontenerów</span>
                            @else
                                @foreach ($template->downloads as $download)
                                    <div style="margin-bottom:4px">
                                        <span class="pill {{ $download->tone() }}"
                                              @if($download->error) title="{{ $download->error }}" @endif>
                                            {{ $download->hypervisor->name }}: {{ $download->label() }}
                                        </span>
                                    </div>
                                    @if ($download->error)
                                        <div class="hint" style="color: var(--critical)">{{ $download->error }}</div>
                                    @endif
                                @endforeach
                            @endif
                        </td>
                        <td>
                            <span class="pill {{ $template->is_active ? 'ok' : 'neutral' }}">
                                {{ $template->is_active ? 'dostępny' : 'wyłączony' }}
                            </span>
                        </td>
                        <td>
                            <div class="btn-row">
                                @if ($template->isContainer() && $template->downloads->contains('status', 'failed'))
                                    <form method="POST" action="{{ route('panel.admin.templates.retry', $template) }}">
                                        @csrf
                                        <button class="btn" type="submit">Ponów pobieranie</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('panel.admin.templates.toggle', $template) }}">
                                    @csrf
                                    <button class="btn" type="submit">
                                        {{ $template->is_active ? 'Wyłącz' : 'Włącz' }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Brak szablonów — nie da się utworzyć maszyny.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="hint">
        Szablony bez cloud-init (m.in. Windows) nie są dostępne w samoobsłudze — cloud-init
        wstrzykuje hasło, klucz SSH i konfigurację sieci przy pierwszym starcie.
    </p>
@endsection
