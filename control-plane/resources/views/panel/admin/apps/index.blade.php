@extends('layouts.panel')

@section('title', __('Aplikacje'))

@section('content')
    @include('panel.admin.apps._nav')

    <form method="GET" class="console-input" style="max-width:480px; margin:0 0 16px">
        <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('nazwa, UUID albo e-mail klienta') }}" aria-label="{{ __('Szukaj') }}">
        <button class="btn" type="submit">{{ __('Szukaj') }}</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Nazwa') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Szablon') }}</th><th>{{ __('Węzeł / adres') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Stan') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($apps as $app)
                    <tr>
                        <td><a href="{{ route('panel.apps.show', $app) }}"><strong>{{ $app->name }}</strong></a></td>
                        <td class="muted">{{ $app->user?->email }}</td>
                        <td class="muted">{{ $app->egg?->name }}</td>
                        <td><span class="muted">{{ $app->hypervisor?->name ?? '—' }}</span><div class="hint mono">{{ $app->address() }}</div></td>
                        <td class="num">{{ __(':memory MB · :disk MB', ['memory' => $app->memory_mb, 'disk' => $app->disk_mb]) }}</td>
                        <td><span class="pill {{ $app->isSuspended() || $app->status === 'install_failed' ? 'critical' : ($app->isInstalling() ? 'warning' : 'neutral') }}">{{ $app->statusLabel() }}</span>
                            @if ($app->abuse_detected_at) <span class="pill critical plain" title="{{ $app->suspension_reason }}">{{ __('nadużycie') }}</span> @endif</td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('panel.admin.apps.suspend', $app) }}" style="margin:0">
                                @csrf
                                <button class="btn btn-sm" type="submit">{{ $app->isSuspended() ? __('Odwieś') : __('Zawieś') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted" style="text-align:center; padding:24px">{{ __('Brak aplikacji.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $apps->links() }}
@endsection
