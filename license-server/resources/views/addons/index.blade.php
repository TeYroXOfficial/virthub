@extends('layout')
@section('title', 'Addony')
@section('content')
    <h1>Addony</h1>
    <details class="card">
        <summary><strong>Nowy addon</strong></summary>
        <form method="POST" action="{{ route('addons.store') }}" style="margin-top:12px">
            @csrf
            <div class="grid">
                <div class="field"><label for="slug">Identyfikator</label><input id="slug" name="slug" required pattern="[a-z][a-z0-9-]{1,39}" placeholder="onidel"></div>
                <div class="field"><label for="name">Nazwa</label><input id="name" name="name" required placeholder="Reselling Onidel"></div>
                <div class="field"><label for="price">Cena (opis)</label><input id="price" name="price" placeholder="49 zł / mies."></div>
            </div>
            <div class="field"><label for="description">Opis</label><textarea id="description" name="description" rows="2"></textarea></div>
            <label style="font-weight:400"><input type="checkbox" name="is_public" value="1" checked> Widoczny w katalogu wszystkich paneli</label>
            <button class="btn btn-primary" type="submit" style="margin-top:8px">Dodaj</button>
        </form>
    </details>

    @foreach ($addons as $addon)
        <div class="card">
            <div class="row" style="justify-content:space-between">
                <h2 style="margin:0">{{ $addon->name }} <span class="mono muted">{{ $addon->slug }}</span></h2>
                <span class="muted">licencji: {{ $addon->licenses_count }}</span>
            </div>
            <form method="POST" action="{{ route('addons.update', $addon) }}" style="margin-top:12px">
                @csrf @method('PUT')
                <div class="grid">
                    <div class="field"><label>Nazwa</label><input name="name" required value="{{ $addon->name }}"></div>
                    <div class="field"><label>Cena (opis)</label><input name="price" value="{{ $addon->price }}"></div>
                </div>
                <div class="field"><label>Opis</label><textarea name="description" rows="2">{{ $addon->description }}</textarea></div>
                <label style="font-weight:400"><input type="checkbox" name="is_public" value="1" @checked($addon->is_public)> Widoczny w katalogu</label>
                <button class="btn btn-sm" type="submit">Zapisz</button>
            </form>
            <h2 style="margin-top:16px">Wersje</h2>
            <table>
                <thead><tr><th>Wersja</th><th>SHA-256</th><th>Rozmiar</th><th>Data</th><th></th></tr></thead>
                @forelse ($addon->versions->sortByDesc('id') as $v)
                    <tr>
                        <td class="mono">{{ $v->version }} @unless ($v->is_published) <span class="pill warn">wycofana</span> @endunless</td>
                        <td class="mono muted">{{ substr($v->sha256, 0, 16) }}…</td>
                        <td>{{ number_format($v->size / 1024, 1) }} KB</td>
                        <td class="muted">{{ $v->created_at->format('Y-m-d H:i') }}</td>
                        <td><form method="POST" action="{{ route('versions.toggle', $v) }}" style="margin:0">@csrf <button class="btn btn-sm" type="submit">{{ $v->is_published ? 'Wycofaj' : 'Opublikuj' }}</button></form></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Brak wersji.</td></tr>
                @endforelse
            </table>
            <form method="POST" action="{{ route('addons.upload', $addon) }}" enctype="multipart/form-data" class="row" style="margin-top:12px">
                @csrf
                <input type="file" name="package" accept=".zip" required style="max-width:320px">
                <input name="changelog" placeholder="Zmiany w wersji (opcjonalnie)" style="max-width:320px">
                <button class="btn btn-primary" type="submit">Wgraj wersję</button>
                <span class="muted">Wersja i identyfikator z addon.json; paczka jest podpisywana przy wgraniu.</span>
            </form>
        </div>
    @endforeach
@endsection
