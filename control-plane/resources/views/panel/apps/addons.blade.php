@extends('layouts.panel')

@section('title', ($kind === 'mod' ? __('Mody') : __('Pluginy')).' — '.$app->name)

@section('content')
    @include('panel.apps._header')
    @include('panel.apps._content_job')
    @error('content') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($kind === null)
        <div class="card empty">
            <x-icon name="puzzle" :size="28"/>
            <p>{{ __('Ten serwer nie przyjmuje pluginów ani modów. Pluginy działają na Paperze, mody — na Forge, NeoForge, Fabric i Quilt (zakładka Modpacki).') }}</p>
        </div>
    @else
        @php $label = $kind === 'mod' ? __('mody') : __('pluginy'); @endphp
        <p class="muted">
            {{ __('Serwer: :platform, Minecraft :mc. Lista pokazuje tylko :what zgodne z tą wersją — wymagane zależności instalują się same.', [
                'platform' => ucfirst((string) $profile->platform()), 'mc' => $mc ?? '?', 'what' => $label,
            ]) }}
        </p>

        @if ($mc === null)
            <div class="alert alert-warning">{{ __('Nie znam wersji Minecrafta na tym serwerze — uruchom go raz, żeby panel ją odczytał.') }}</div>
        @endif

        <div class="section-head" style="margin-top:8px">
            <h2>{{ __('Zainstalowane') }} <span class="muted">({{ $installed->count() }})</span></h2>
            @if ($installed->isNotEmpty() && $updates === null)
                <a class="btn btn-sm" href="{{ route('panel.apps.addons', [$app, 'updates' => 1]) }}"><x-icon name="refresh" :size="14"/> {{ __('Sprawdź aktualizacje') }}</a>
            @endif
        </div>
        @if ($installed->isEmpty())
            <p class="muted">{{ __('Brak — wybierz coś z listy poniżej.') }}</p>
        @else
            <div class="card" style="padding:0"><div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Wersja') }}</th><th>{{ __('Plik') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($installed as $addon)
                            <tr>
                                <td>{{ $addon->name }} @if ($addon->dependency) <span class="pill neutral plain">{{ __('zależność') }}</span> @endif</td>
                                <td>{{ $addon->version }}
                                    @if ($update = $updates[$addon->id] ?? null)
                                        <form method="POST" action="{{ route('panel.apps.addons.install', $app) }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="source" value="{{ $addon->source }}">
                                            <input type="hidden" name="project" value="{{ $addon->project_id }}">
                                            <input type="hidden" name="version" value="{{ $update['id'] }}">
                                            <button class="btn btn-sm" type="submit">{{ __('Aktualizuj do :v', ['v' => $update['number'] ?: $update['name']]) }}</button>
                                        </form>
                                    @endif
                                </td>
                                <td class="mono muted">{{ $addon->directory() }}/{{ $addon->filename }}</td>
                                <td style="text-align:right">
                                    @can('operate', $app)
                                        <form method="POST" action="{{ route('panel.apps.addons.destroy', [$app, $addon]) }}"
                                              data-confirm="{{ __('Usunąć :name z serwera?', ['name' => $addon->name]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-danger" type="submit" title="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div></div>
        @endif

        <div class="section-head"><h2>{{ $kind === 'mod' ? __('Dodaj mody') : __('Dodaj pluginy') }}</h2></div>
        @if ($mc)
            @include('panel.apps._catalog', [
                'route' => 'panel.apps.addons', 'showRoute' => 'panel.apps.addons.show',
                'placeholder' => $kind === 'mod' ? __('np. JourneyMap, Create, Sodium…') : __('np. LuckPerms, EssentialsX, WorldEdit…'),
            ])
        @endif
    @endif
@endsection
