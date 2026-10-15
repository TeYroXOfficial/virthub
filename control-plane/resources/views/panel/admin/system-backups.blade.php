@extends('layouts.panel')

@section('title', __('Kopia panelu'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Kopia panelu') }}</h1>
            <p class="lede">{{ __('Archiwum z bazą danych, plikiem .env (z APP_KEY — bez niego zaszyfrowane hasła i klucze w bazie są nie do odczytania) i plikami panelu (storage/app). Maszyny klientów są na węzłach — ich kopie robią plany kopii.') }}</p>
        </div>
        <form method="POST" action="{{ route('panel.admin.system-backups.store') }}" class="actions">
            @csrf <button class="btn btn-primary" type="submit"><x-icon name="archive" :size="16"/> {{ __('Utwórz kopię teraz') }}</button>
        </form>
    </div>
    @error('backup') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

    <div class="alert alert-warning"><div>{{ __('Archiwum zawiera wszystkie sekrety panelu. Trzymaj kopie poza serwerem panelu (pobierz albo skopiuj z :dir) w zaszyfrowanym miejscu.', ['dir' => $directory]) }}</div></div>

    <div class="card flush">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Archiwum') }}</th><th>{{ __('Rozmiar') }}</th><th>{{ __('Utworzono') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($backups as $b)
                    <tr>
                        <td class="mono">{{ $b['name'] }}</td>
                        <td class="num">{{ \Illuminate\Support\Number::fileSize($b['size'], 1) }}</td>
                        <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($b['created_at'])->format('d.m.Y H:i') }}</td>
                        <td style="text-align:right">
                            <div class="btn-row" style="justify-content:flex-end">
                                <a class="btn btn-sm" href="{{ route('panel.admin.system-backups.download', $b['name']) }}"><x-icon name="download" :size="14"/> {{ __('Pobierz') }}</a>
                                <form method="POST" action="{{ route('panel.admin.system-backups.destroy', $b['name']) }}" style="margin:0" data-confirm="{{ __('Usunąć kopię :name?', ['name' => $b['name']]) }}">
                                    @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('Brak kopii panelu.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('panel.admin.system-backups.settings') }}" class="card">
        @csrf @method('PUT')
        <h3 class="card-title"><x-icon name="clock" :size="16"/> {{ __('Harmonogram') }}</h3>
        <label class="check-line"><input type="checkbox" name="daily" value="1" @checked($daily)> {{ __('Codzienna kopia o 03:17 (czas serwera)') }}</label>
        <div class="field" style="max-width:220px"><label for="keep">{{ __('Ile kopii trzymać') }}</label>
            <input id="keep" name="keep" type="number" min="1" max="365" value="{{ $keep }}" required></div>
        <p class="hint">{{ __('Ręcznie: php artisan virthub:system-backup. Odtworzenie opisuje docs/system-backup.md: rozpakuj archiwum, przywróć .env, bazę i storage/app, potem php artisan migrate.') }}</p>
        <button class="btn" type="submit">{{ __('Zapisz') }}</button>
    </form>
@endsection
