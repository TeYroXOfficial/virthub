@extends('layouts.panel')

@section('title', $path.' — '.$app->name)

@section('content')
    @include('panel.apps._header')

    @php $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : ''; @endphp

    <div class="breadcrumbs">
        <a href="{{ route('panel.apps.files', [$app, 'path' => $dir]) }}"><x-icon name="arrow-left" :size="15"/> {{ __('Wróć do plików') }}</a>
        <span class="muted">·</span>
        <span class="mono">/home/container/{{ $path }}</span>
        @if ($isNew) <span class="pill info plain">{{ __('nowy plik') }}</span> @endif
    </div>

    @error('files') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($binary)
        <div class="card empty">
            <x-icon name="file" :size="40"/>
            <p>{{ __('To plik binarny albo większy niż 2 MB — nie da się go edytować w przeglądarce.') }}</p>
            <a class="btn" href="{{ route('panel.apps.files.download', [$app, 'path' => $path]) }}"><x-icon name="download" :size="15"/> {{ __('Pobierz') }}</a>
        </div>
    @else
        <form method="POST" action="{{ route('panel.apps.files.save', $app) }}">
            @csrf @method('PUT')
            <input type="hidden" name="path" value="{{ $path }}">
            <textarea class="file-editor" name="content" spellcheck="false" autocapitalize="off" aria-label="{{ __('Zawartość pliku') }}">{{ $content }}</textarea>
            <div style="margin-top:12px; display:flex; gap:8px">
                <button class="btn btn-primary" type="submit" @disabled(! $app->acceptsCommands())>{{ __('Zapisz plik') }}</button>
                <span class="hint">{{ __('Ctrl+S zapisuje. Zmiany w konfiguracji zwykle wymagają restartu aplikacji.') }}</span>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        const editor = document.querySelector('.file-editor');
        if (editor) {
            editor.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const s = editor.selectionStart;
                    editor.setRangeText('    ', s, editor.selectionEnd, 'end');
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    editor.form.submit();
                }
            });
        }
    </script>
@endpush
