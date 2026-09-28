@extends('layouts.panel')

@section('title', __('Pliki — :name', ['name' => $app->name]))

@section('content')
    @include('panel.apps._header')

    @php
        $parts = $path === '' ? [] : explode('/', $path);
        $join = fn (string $name) => ltrim($path.'/'.$name, '/');
        $human = fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
        $archive = fn (string $n) => (bool) preg_match('/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tar\.xz|txz)$/i', $n);
    @endphp

    <div class="breadcrumbs">
        <a href="{{ route('panel.apps.files', $app) }}"><x-icon name="folder" :size="15"/> /home/container</a>
        @foreach ($parts as $i => $part)
            <span class="muted">/</span>
            <a href="{{ route('panel.apps.files', [$app, 'path' => implode('/', array_slice($parts, 0, $i + 1))]) }}">{{ $part }}</a>
        @endforeach
    </div>

    @if ($error)
        <div class="alert alert-error">{{ $error }}</div>
    @endif
    @error('files') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($app->acceptsCommands())
        <div class="card" style="margin-bottom:16px">
            <div class="grid grid-2" style="gap:16px">
                <form method="POST" action="{{ route('panel.apps.files.upload', $app) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="path" value="{{ $path }}">
                    <div class="field">
                        <label for="f-upload">{{ __('Wgraj pliki') }} <span class="muted">{{ __('(do 50 MB każdy)') }}</span></label>
                        <input id="f-upload" type="file" name="files[]" multiple required>
                    </div>
                    <button class="btn" type="submit"><x-icon name="upload" :size="15"/> {{ __('Wgraj') }}</button>
                </form>
                <div>
                    <form method="POST" action="{{ route('panel.apps.files.mkdir', $app) }}" class="console-input" style="margin-top:0">
                        @csrf
                        <input type="hidden" name="path" value="{{ $path }}">
                        <input type="text" name="name" required maxlength="255" placeholder="{{ __('nazwa-katalogu') }}" aria-label="{{ __('Nowy katalog') }}">
                        <button class="btn" type="submit"><x-icon name="folder" :size="15"/> {{ __('Nowy katalog') }}</button>
                    </form>
                    <form method="GET" action="{{ route('panel.apps.files.edit', $app) }}" class="console-input">
                        <input type="hidden" name="new" value="1">
                        <input type="text" name="path" required maxlength="1024" value="{{ $path ? $path.'/' : '' }}" aria-label="{{ __('Nowy plik') }}" placeholder="{{ __('config.yml') }}">
                        <button class="btn" type="submit"><x-icon name="file" :size="15"/> {{ __('Nowy plik') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('panel.apps.files.delete', $app) }}" id="files-form"
          data-confirm="{{ __('Usunąć zaznaczone pliki i katalogi?') }}">
        @csrf
        <input type="hidden" name="path" value="{{ $path }}">
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table class="file-table">
                    <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" aria-label="{{ __('Zaznacz wszystkie') }}" onclick="document.querySelectorAll('[name=&quot;names[]&quot;]').forEach(c => c.checked = this.checked)"></th>
                        <th>{{ __('Nazwa') }}</th><th class="num">{{ __('Rozmiar') }}</th><th>{{ __('Zmieniony') }}</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @if ($path !== '')
                        <tr><td></td><td class="name" colspan="4"><a href="{{ route('panel.apps.files', [$app, 'path' => implode('/', array_slice($parts, 0, -1))]) }}"><x-icon name="arrow-left" :size="15"/> ..</a></td></tr>
                    @endif
                    @forelse ($entries as $entry)
                        @php $full = $join($entry['name']); @endphp
                        <tr>
                            <td><input type="checkbox" name="names[]" value="{{ $entry['name'] }}" aria-label="{{ __('Zaznacz :name', ['name' => $entry['name']]) }}"></td>
                            <td class="name">
                                @if ($entry['directory'])
                                    <a href="{{ route('panel.apps.files', [$app, 'path' => $full]) }}"><x-icon name="folder" :size="16"/> {{ $entry['name'] }}</a>
                                @elseif ($entry['symlink'])
                                    <span class="muted"><x-icon name="file" :size="16"/> {{ $entry['name'] }} →</span>
                                @else
                                    <a href="{{ route('panel.apps.files.edit', [$app, 'path' => $full]) }}"><x-icon name="file" :size="16"/> {{ $entry['name'] }}</a>
                                @endif
                            </td>
                            <td class="num muted">{{ $entry['directory'] ? '—' : $human($entry['size']) }}</td>
                            <td class="muted">{{ \Carbon\Carbon::createFromTimestamp($entry['modified'])->diffForHumans() }}</td>
                            <td style="text-align:right; white-space:nowrap">
                                @if (! $entry['directory'] && ! $entry['symlink'])
                                    <a class="btn btn-sm" href="{{ route('panel.apps.files.download', [$app, 'path' => $full]) }}" title="{{ __('Pobierz') }}"><x-icon name="download" :size="14"/></a>
                                @endif
                                @if ($archive($entry['name']))
                                    <button class="btn btn-sm" type="submit" form="unpack-{{ $loop->index }}">{{ __('Rozpakuj') }}</button>
                                @endif
                                <button class="btn btn-sm" type="button" data-rename="{{ $entry['name'] }}">{{ __('Zmień nazwę') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="text-align:center; padding:24px">{{ __('Katalog jest pusty.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($entries)
            <button class="btn btn-danger" type="submit" style="margin-top:12px"><x-icon name="trash" :size="15"/> {{ __('Usuń zaznaczone') }}</button>
        @endif
    </form>

    @foreach ($entries as $entry)
        @if ($archive($entry['name']))
            <form method="POST" action="{{ route('panel.apps.files.decompress', $app) }}" id="unpack-{{ $loop->index }}" hidden>
                @csrf
                <input type="hidden" name="path" value="{{ $path }}">
                <input type="hidden" name="name" value="{{ $entry['name'] }}">
            </form>
        @endif
    @endforeach

    <form method="POST" action="{{ route('panel.apps.files.rename', $app) }}" id="rename-form" hidden>
        @csrf
        <input type="hidden" name="path" value="{{ $path }}">
        <input type="hidden" name="from">
        <input type="hidden" name="to">
    </form>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-rename]').forEach((btn) => btn.addEventListener('click', async () => {
            const to = await vhPrompt(@js(__('Nowa nazwa (albo ścieżka od /home/container, zaczynająca się od /):')), btn.dataset.rename, { title: @js(__('Zmień nazwę')), okLabel: @js(__('Zmień nazwę')) });
            if (!to || to === btn.dataset.rename) return;
            const form = document.getElementById('rename-form');
            form.elements.from.value = btn.dataset.rename;
            form.elements.to.value = to;
            form.submit();
        }));
    </script>
@endpush
