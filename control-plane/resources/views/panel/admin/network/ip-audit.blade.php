@extends('layouts.panel')

@section('title', __('Audyt IP'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Audyt IP') }}</h1>
            <p class="lede">{{ __('Historia przydziałów adresów: która maszyna i który klient mieli adres i od kiedy do kiedy — także po usunięciu maszyny. Przydatne przy zgłoszeniach nadużyć.') }}</p>
        </div>
        <div class="actions">
            @foreach (\App\Support\Export::FORMATS as $format)
                <a class="btn btn-sm" href="{{ route('panel.admin.network.ip-audit.export', ['format' => $format] + request()->query()) }}"><x-icon name="download" :size="14"/> {{ strtoupper($format) }}</a>
            @endforeach
        </div>
    </div>

    <form method="GET" class="card grid grid-4" style="align-items:end">
        <div class="field" style="margin:0"><label for="q">{{ __('Adres, nazwa hosta albo e-mail') }}</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="203.0.113.10"></div>
        <div class="field" style="margin:0"><label for="from">{{ __('Od') }}</label><input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="field" style="margin:0"><label for="to">{{ __('Do') }}</label><input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
        <div class="field" style="margin:0"><label for="state">{{ __('Stan') }}</label>
            <select id="state" name="state">
                <option value="">{{ __('wszystkie') }}</option>
                <option value="active" @selected(($filters['state'] ?? '') === 'active')>{{ __('przydzielone teraz') }}</option>
                <option value="released" @selected(($filters['state'] ?? '') === 'released')>{{ __('zwolnione') }}</option>
            </select></div>
        <div class="btn-row"><button class="btn btn-primary" type="submit">{{ __('Filtruj') }}</button>
            <a class="btn" href="{{ route('panel.admin.network.ip-audit') }}">{{ __('Wyczyść') }}</a></div>
    </form>

    <div class="card flush">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Adres') }}</th><th>{{ __('Maszyna') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Węzeł') }}</th><th>{{ __('Od') }}</th><th>{{ __('Do') }}</th></tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="mono">{{ $row->address }}</td>
                        <td>@if ($row->server_id && \App\Models\Server::whereKey($row->server_id)->exists())
                                <a href="{{ route('panel.servers.show', $row->server_id) }}">{{ $row->hostname }}</a>
                            @else {{ $row->hostname ?? '—' }} <span class="muted">{{ $row->server_id ? '#'.$row->server_id : '' }}</span> @endif</td>
                        <td>{{ $row->user_email ?? '—' }}</td>
                        <td>{{ $row->hypervisor_name ?? '—' }}</td>
                        <td class="num">{{ $row->assigned_at->format('d.m.Y H:i') }}</td>
                        <td class="num">@if ($row->released_at) {{ $row->released_at->format('d.m.Y H:i') }} @else <span class="pill ok">{{ __('teraz') }}</span> @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Brak przydziałów dla tych filtrów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $rows->links() }}
@endsection
