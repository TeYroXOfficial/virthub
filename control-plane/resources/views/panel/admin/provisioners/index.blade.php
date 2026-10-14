@extends('layouts.panel')

@section('title', __('Provisionery'))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Provisionery') }}</h1>
            <p class="lede">{{ __('Skrypty uruchamiane przy pierwszym starcie maszyny (i po reinstalacji) — np. instalacja Dockera, panelu, agenta monitoringu.') }}</p></div>
        <div class="actions"><a class="btn btn-primary" href="{{ route('panel.admin.provisioners.create') }}"><x-icon name="plus" :size="16"/> {{ __('Nowy provisioner') }}</a></div>
    </div>

    <div class="card flush dash-section">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Opis') }}</th><th class="num">{{ __('Szablony') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($provisioners as $provisioner)
                    <tr>
                        <td><code>{{ $provisioner->name }}</code></td>
                        <td class="muted">{{ $provisioner->description }}</td>
                        <td class="num">{{ $provisioner->templates_count }}</td>
                        <td><span class="pill {{ $provisioner->is_active ? 'ok' : 'neutral' }}">{{ $provisioner->is_active ? __('aktywny') : __('wyłączony') }}</span></td>
                        <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.admin.provisioners.edit', $provisioner) }}">{{ __('Edytuj') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('Brak provisionerów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
