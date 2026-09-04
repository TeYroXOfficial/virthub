@extends('layouts.panel')

@section('title', 'Szablony systemów')

@section('content')
    <h1>Szablony systemów</h1>
    <p class="lede">Obrazy, z których stawiane są maszyny klientów.</p>

    @include('panel.admin._nav')

    <div class="card">
        <h3>Zanim dodasz szablon</h3>
        <p>
            Panel nie pobiera obrazów. Plik musi już leżeć w katalogu szablonów
            <span class="mono">/var/lib/virthub/templates</span> na <strong>każdym</strong> węźle,
            inaczej tworzenie maszyny z tego szablonu skończy się błędem.
        </p>
        <p class="secret">cd /var/lib/virthub/templates && curl -LO https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img && mv ubuntu-24.04-server-cloudimg-amd64.img ubuntu-24.04.qcow2</p>
        <p class="hint">
            Obrazu bazowego nie wolno usunąć ani nadpisać, dopóki istnieje choć jedna maszyna,
            która go używa — dyski klientów są cienkimi warstwami nad tym plikiem.
        </p>
    </div>

    <details class="form-block card">
        <summary>Dodaj szablon</summary>
        <form method="POST" action="{{ route('panel.admin.templates.store') }}" style="margin-top:12px">
            @csrf
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
                <label for="image_file">Nazwa pliku obrazu</label>
                <input id="image_file" name="image_file" type="text" value="{{ old('image_file') }}"
                       placeholder="ubuntu-24.04.qcow2" required>
                <div class="hint">Sama nazwa pliku, bez ścieżki. Musi się zgadzać z plikiem na węzłach.</div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj szablon</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Nazwa</th><th>Rodzina</th><th>Plik obrazu</th><th>Min. dysk</th>
                    <th>Instalacja</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($templates as $template)
                    <tr>
                        <td>{{ $template->name }}</td>
                        <td class="muted">{{ $template->family }} {{ $template->version }}</td>
                        <td class="mono">{{ $template->image_file }}</td>
                        <td class="num">{{ $template->min_disk_gb }} GB</td>
                        <td>
                            @if ($template->cloud_init_support)
                                <span class="pill ok">automatyczna</span>
                            @else
                                <span class="pill warning">ręczna</span>
                            @endif
                        </td>
                        <td>
                            <span class="pill {{ $template->is_active ? 'ok' : 'neutral' }}">
                                {{ $template->is_active ? 'dostępny' : 'wyłączony' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('panel.admin.templates.toggle', $template) }}">
                                @csrf
                                <button class="btn" type="submit">
                                    {{ $template->is_active ? 'Wyłącz' : 'Włącz' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Brak szablonów — nie da się utworzyć maszyny.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="hint">
        Szablony oznaczone jako „ręczna instalacja" (m.in. Windows) nie są dostępne
        w samoobsłudze — brakuje im cloud-init, który wstrzykuje hasło, klucz SSH
        i konfigurację sieci przy pierwszym starcie.
    </p>
@endsection
