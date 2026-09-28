@extends('layouts.panel')

@section('title', __('Uruchamianie — :name', ['name' => $app->name]))

@section('content')
    @include('panel.apps._header')

    @php
        $staff = auth()->user()->can('manage', $app);
        $values = $app->variableValues();
    @endphp

    <div class="card" style="margin-bottom:16px">
        <h3 class="card-title"><x-icon name="terminal" :size="16"/> {{ __('Polecenie startowe') }}</h3>
        <pre class="mono" style="white-space:pre-wrap; margin:0; font-size:13px">{{ $preview }}</pre>
    </div>

    @error('variables') <div class="alert alert-error">{{ $message }}</div> @enderror

    <form method="POST" action="{{ route('panel.apps.startup.update', $app) }}">
        @csrf @method('PUT')
        <div class="grid grid-2">
            @if (count($app->egg->images()) > 1 || $staff)
                <div class="card">
                    <h3 class="card-title">{{ __('Środowisko (obraz Dockera)') }}</h3>
                    <div class="field">
                        <select name="image" aria-label="{{ __('Obraz Dockera') }}">
                            @foreach ($app->egg->images() as $label => $image)
                                <option value="{{ $image }}" @selected($app->docker_image === $image)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="hint mono">{{ $app->docker_image }}</div>
                    </div>
                </div>
            @endif

            @foreach ($app->egg->variableList() as $var)
                @continue(! ($var['user_viewable'] ?? true) && ! $staff)
                @php
                    $env = $var['env_variable'];
                    $editable = $staff || ($var['user_editable'] ?? true);
                @endphp
                <div class="card">
                    <h3 class="card-title">{{ $app->egg->text($var['name']) }}</h3>
                    <div class="field">
                        <input type="text" name="variables[{{ $env }}]" value="{{ old('variables.'.$env, $values[$env] ?? '') }}"
                               aria-label="{{ $app->egg->text($var['name']) }}" @disabled(! $editable) @readonly(! $editable)>
                        @error('variables.'.$env) <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        @error($env) <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        <div class="hint">{{ $app->egg->text($var['description']) }}</div>
                        <div class="hint mono">{{ $env }}@if (! $editable) · {{ __('tylko do odczytu') }}@endif</div>
                    </div>
                </div>
            @endforeach
        </div>
        {{-- Także po nieudanej instalacji — poprawione zmienne i reinstalacja to droga naprawy. --}}
        @if ((! $app->isInstalling() && ! $app->isSuspended()) || $staff)
            <button class="btn btn-primary" type="submit" style="margin-top:16px">{{ __('Zapisz') }}</button>
            <span class="hint">{{ __('Zmiany obowiązują od następnego startu aplikacji.') }}</span>
        @endif
    </form>
@endsection
