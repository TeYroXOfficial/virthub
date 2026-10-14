@extends('layouts.panel')

@section('title', $provisioner->exists ? $provisioner->name : __('Nowy provisioner'))

@php
    // Składane, bo dosłowne podwójne klamry Blade wziąłby za własne wyrażenie.
    $vars = collect(['server.id', 'server.hostname', 'server.label', 'server.ipv4', 'server.ipv6', 'server.vcpu', 'server.ram_mb', 'server.disk_gb', 'server.template', 'user.id', 'user.email'])
        ->map(fn ($v) => '{'.'{ '.$v.' }'.'}')->implode(', ');
    $selected = array_map('intval', old('templates', $attached));
@endphp

@section('content')
    <div class="page-header">
        <div><h1>{{ $provisioner->exists ? $provisioner->name : __('Nowy provisioner') }}</h1></div>
        <div class="btn-row"><a class="btn" href="{{ route('panel.admin.provisioners') }}">{{ __('← Provisionery') }}</a></div>
    </div>

    <form method="POST" action="{{ $provisioner->exists ? route('panel.admin.provisioners.update', $provisioner) : route('panel.admin.provisioners.store') }}">
        @csrf
        @if ($provisioner->exists) @method('PUT') @endif
        <div class="card">
            <div class="grid grid-2">
                <div class="field"><label for="p-name">{{ __('Nazwa') }}</label>
                    <input id="p-name" name="name" required maxlength="41" pattern="[a-z0-9][a-z0-9_\-]{0,40}" value="{{ old('name', $provisioner->name) }}" placeholder="docker-install">
                    <div class="hint">{{ __('Plik w maszynie: <ścieżka>/<nr>-<nazwa>, log: /var/log/virthub-provision/<nr>-<nazwa>.log') }}</div></div>
                <div class="field"><label for="p-path">{{ __('Katalog tymczasowy w maszynie') }}</label>
                    <input id="p-path" name="path" required maxlength="200" value="{{ old('path', $provisioner->path) }}"></div>
            </div>
            <div class="field"><label for="p-desc">{{ __('Opis') }}</label>
                <input id="p-desc" name="description" maxlength="500" value="{{ old('description', $provisioner->description) }}"></div>
            <div class="field"><label for="p-content">{{ __('Skrypt') }}</label>
                <textarea id="p-content" name="content" rows="18" required spellcheck="false" style="font-family:var(--mono); font-size:13px">{{ old('content', $provisioner->content) }}</textarea>
                <div class="hint">{{ __('Pierwsza linia to interpreter (#!/bin/bash, #!/usr/bin/env python3…). Skrypt działa jako root. Zmienne serwera: :vars', ['vars' => $vars]) }}</div></div>
            <label style="font-weight:400; display:flex; gap:8px; align-items:center">
                <input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $provisioner->exists ? $provisioner->is_active : true))> {{ __('Aktywny') }}
            </label>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="disc" :size="16"/> {{ __('Szablony') }}</h3>
            <p class="muted">{{ __('Provisioner uruchomi się na nowych maszynach i po reinstalacji z zaznaczonych szablonów. Windows pomijamy — cloudbase-init nie wykonuje tych skryptów.') }}</p>
            <div class="grid grid-2" style="gap:2px 16px; max-height:320px; overflow:auto">
                @forelse ($templates as $template)
                    <label style="font-weight:400; display:flex; gap:8px; align-items:center">
                        <input type="checkbox" name="templates[]" value="{{ $template->id }}" style="width:auto" @checked(in_array($template->id, $selected, true))>
                        {{ $template->name }} {{ $template->version }} <span class="muted">{{ $template->virtualization?->shortLabel() }}</span>
                    </label>
                @empty
                    <span class="muted">{{ __('Brak szablonów.') }}</span>
                @endforelse
            </div>
            <div class="field" style="max-width:200px; margin-top:12px"><label for="p-order">{{ __('Kolejność wykonania') }}</label>
                <input id="p-order" name="sort_order" type="number" min="0" max="1000" value="{{ old('sort_order', $order) }}">
                <div class="hint">{{ __('Mniejsza liczba = wcześniej, gdy szablon ma kilka provisionerów.') }}</div></div>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
        </div>
    </form>
    @if ($provisioner->exists)
        <form method="POST" action="{{ route('panel.admin.provisioners.destroy', $provisioner) }}" style="margin-top:12px" data-confirm="{{ __('Usunąć provisioner? Działające maszyny nie zmienią się.') }}">
            @csrf @method('DELETE') <button class="btn btn-danger" type="submit">{{ __('Usuń provisioner') }}</button>
        </form>
    @endif
@endsection
