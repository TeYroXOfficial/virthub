@extends('layouts.panel')

@section('title', 'Obrazy ISO')

@php
    $tone = ['ready' => 'ok', 'queued' => 'warning', 'downloading' => 'warning', 'failed' => 'critical'];
    $label = ['ready' => 'gotowy', 'queued' => 'w kolejce', 'downloading' => 'pobieranie', 'failed' => 'błąd'];
    $human = fn ($b) => $b === null ? '—' : ($b >= 1073741824 ? round($b / 1073741824, 2).' GB' : round($b / 1048576).' MB');
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>Obrazy ISO</h1>
            <p class="lede">
                Płyty instalacyjne i ratunkowe dla maszyn KVM. Węzły pobierają obraz same; klient montuje go
                na stronie maszyny i instaluje system przez konsolę.
            </p>
        </div>
    </div>

    <details class="form-block card" @if($isos->isEmpty() || $errors->any()) open @endif>
        <summary>Dodaj obraz</summary>
        <form method="POST" action="{{ route('panel.admin.isos.store') }}">
            @csrf
            <div class="grid grid-2">
                <div class="field">
                    <label for="iso-name">Nazwa</label>
                    <input id="iso-name" name="name" type="text" value="{{ old('name') }}" placeholder="Debian 13 netinst" required>
                </div>
                <div class="field">
                    <label for="iso-url">Adres URL</label>
                    <input id="iso-url" name="url" type="text" value="{{ old('url') }}"
                           placeholder="https://cdimage.debian.org/…/debian-13-amd64-netinst.iso" required>
                </div>
                <div class="field">
                    <label for="iso-sha">SHA-256 <span class="muted">(zalecane)</span></label>
                    <input id="iso-sha" name="sha256" type="text" value="{{ old('sha256') }}" placeholder="64 znaki z pliku SHA256SUMS">
                    <div class="hint">Węzeł odrzuci plik z inną sumą — ochrona przed uszkodzonym albo podmienionym obrazem.</div>
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="is_public" value="1" style="width:auto" @checked(old('is_public', true))>
                        Widoczny dla klientów
                    </label>
                    <div class="hint">Odznaczony — tylko personel (np. narzędzia serwisowe).</div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj i pobierz na węzły</button>
        </form>
    </details>

    @forelse ($isos as $iso)
        <div class="card">
            <h3 class="card-title">
                <x-icon name="disc" :size="16"/> {{ $iso->name }}
                <span class="pill {{ $iso->is_public ? 'ok' : 'neutral' }} plain" style="margin-left:auto">{{ $iso->is_public ? 'dla klientów' : 'tylko personel' }}</span>
            </h3>
            <dl class="kv" style="margin-bottom:12px">
                <dt>Plik</dt><dd class="mono">{{ $iso->filename }} · {{ $human($iso->size_bytes) }}</dd>
                <dt>Źródło</dt><dd class="mono" style="word-break:break-all">{{ $iso->url }}</dd>
                <dt>SHA-256</dt><dd class="mono">{{ $iso->sha256 ?? 'nie podano' }}</dd>
                <dt>W maszynach</dt><dd>{{ $iso->servers_count }}</dd>
                <dt>Węzły</dt>
                <dd>
                    @forelse ($iso->downloads as $d)
                        @if ($d->isInProgress())
                            @include('panel.admin._download-progress', ['kind' => 'iso', 'download' => $d])
                        @else
                            <span class="pill {{ $tone[$d->status] ?? 'neutral' }}" title="{{ $d->error }}">{{ $d->hypervisor->name }}: {{ $label[$d->status] ?? $d->status }}</span>
                        @endif
                    @empty
                        <span class="muted">brak węzłów KVM</span>
                    @endforelse
                    @foreach ($iso->downloads->where('status', 'failed') as $d)
                        <div class="hint">{{ $d->hypervisor->name }}: {{ $d->error }}</div>
                    @endforeach
                </dd>
            </dl>
            <div class="btn-row">
                <form method="POST" action="{{ route('panel.admin.isos.retry', $iso) }}" style="margin:0">
                    @csrf <button class="btn btn-sm" type="submit">Pobierz na brakujące węzły</button>
                </form>
                <form method="POST" action="{{ route('panel.admin.isos.toggle', $iso) }}" style="margin:0">
                    @csrf <button class="btn btn-sm" type="submit">{{ $iso->is_public ? 'Ukryj przed klientami' : 'Pokaż klientom' }}</button>
                </form>
                <form method="POST" action="{{ route('panel.admin.isos.destroy', $iso) }}" style="margin:0"
                      onsubmit="return confirm('Usunąć {{ $iso->name }} z biblioteki i z węzłów?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger" type="submit" @disabled($iso->servers_count > 0)>Usuń</button>
                </form>
            </div>
        </div>
    @empty
        <div class="card empty">Biblioteka jest pusta. Dodaj pierwszy obraz formularzem powyżej.</div>
    @endforelse
    @push('scripts')
        <script src="{{ asset('js/downloads.js') }}?v={{ @filemtime(public_path('js/downloads.js')) }}"
                data-status-url="{{ route('panel.admin.downloads.status') }}"></script>
    @endpush
@endsection
