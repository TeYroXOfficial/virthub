@extends('layouts.panel')

@section('title', __('Szablony aplikacji'))

@section('content')
    @include('panel.admin.apps._nav')

    @error('egg') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="grid grid-2" style="margin-bottom:16px">
        <div class="card">
            <h3 class="card-title"><x-icon name="upload" :size="16"/> {{ __('Import eggu Pterodactyla') }}</h3>
            <p class="hint" style="margin-top:0">{{ __('Wgraj plik egg-*.json (format PTDL_v1 albo PTDL_v2) — np. z repozytoriów pelican-eggs albo parkervcp/eggs. Działają te same obrazy yolks i skrypty instalacyjne.') }}</p>
            <form method="POST" action="{{ route('panel.admin.apps.eggs.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="field"><input type="file" name="egg_file" accept=".json,application/json" aria-label="{{ __('Plik eggu') }}"></div>
                <div class="field">
                    <textarea name="egg_json" rows="4" placeholder="{{ __('…albo wklej JSON') }}" aria-label="{{ __('JSON eggu') }}">{{ old('egg_json') }}</textarea>
                </div>
                <div class="field">
                    <label for="egg-cat">{{ __('Kategoria') }}</label>
                    <select id="egg-cat" name="category">
                        @foreach (\App\Models\AppEgg::CATEGORIES as $key => $label)
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Importuj') }}</button>
            </form>
        </div>
        <div class="card">
            <h3 class="card-title"><x-icon name="package" :size="16"/> {{ __('Szablony wbudowane') }}</h3>
            <p class="muted" style="margin-top:0">{{ __('Minecraft (Paper, Vanilla) i boty Discord (Node.js, Python). Ponowne wgranie odświeża je do wersji z panelu.') }}</p>
            <form method="POST" action="{{ route('panel.admin.apps.eggs.builtin') }}">
                @csrf
                <button class="btn" type="submit">{{ __('Wgraj wbudowane szablony') }}</button>
            </form>
        </div>
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Szablon') }}</th><th>{{ __('Kategoria') }}</th><th>{{ __('Obrazy') }}</th><th class="num">{{ __('Aplikacje') }}</th><th>{{ __('Status') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($eggs as $egg)
                    <tr>
                        <td>
                            <strong>{{ $egg->name }}</strong>
                            @if ($egg->source === 'builtin') <span class="pill info plain">{{ __('wbudowany') }}</span> @endif
                            <div class="hint">{{ \Illuminate\Support\Str::limit($egg->description, 110) }}</div>
                        </td>
                        <td class="muted">{{ $egg->categoryLabel() }}</td>
                        <td class="hint mono">{{ implode(', ', array_keys($egg->images())) }}</td>
                        <td class="num">{{ $egg->servers_count }}</td>
                        <td><span class="pill {{ $egg->is_active ? 'ok' : 'neutral' }}">{{ $egg->is_active ? __('dostępny') : __('ukryty') }}</span></td>
                        <td style="text-align:right; white-space:nowrap">
                            <form method="POST" action="{{ route('panel.admin.apps.eggs.toggle', $egg) }}" style="display:inline">
                                @csrf
                                <button class="btn btn-sm" type="submit">{{ $egg->is_active ? __('Ukryj') : __('Pokaż') }}</button>
                            </form>
                            @if ($egg->servers_count === 0)
                                <form method="POST" action="{{ route('panel.admin.apps.eggs.destroy', $egg) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('Usunąć szablon :name?', ['name' => $egg->name])))">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">{{ __('Brak szablonów — wgraj wbudowane albo zaimportuj egg.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
