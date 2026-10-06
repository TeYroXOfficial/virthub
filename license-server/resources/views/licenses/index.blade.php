@extends('layout')
@section('title', 'Licencje')
@section('content')
    <div class="row" style="justify-content:space-between; margin-bottom:16px">
        <h1 style="margin:0">Licencje</h1>
        <a class="btn btn-primary" href="{{ route('licenses.create') }}">Nowa licencja</a>
    </div>
    <form class="row" style="margin-bottom:12px"><input name="q" value="{{ $q }}" placeholder="Szukaj: klucz, właściciel, e-mail, domena" style="max-width:360px"><button class="btn" type="submit">Szukaj</button></form>
    <div class="card" style="padding:0"><div class="table-wrap">
        <table>
            <thead><tr><th>Klucz</th><th>Właściciel</th><th>Domena</th><th>Stan</th><th>Addony</th><th>Ostatnio</th></tr></thead>
            <tbody>
            @forelse ($licenses as $l)
                <tr>
                    <td class="mono"><a href="{{ route('licenses.edit', $l) }}">{{ $l->key }}</a></td>
                    <td>{{ $l->owner_name }}<div class="muted">{{ $l->owner_email }}</div></td>
                    <td class="mono">{{ $l->domain ?? '— (przypnie się przy aktywacji)' }}</td>
                    <td>
                        @if ($l->status !== 'active') <span class="pill crit">zawieszona</span>
                        @elseif ($l->expires_at && $l->expires_at->isPast()) <span class="pill crit">wygasła</span>
                        @else <span class="pill ok">aktywna</span> @endif
                        @if ($l->expires_at) <div class="muted">do {{ $l->expires_at->format('Y-m-d') }}</div> @endif
                    </td>
                    <td>{{ $l->addons_count }}</td>
                    <td class="muted">{{ $l->last_seen_at?->diffForHumans() ?? 'nigdy' }} @if ($l->panel_version) <div class="mono">{{ $l->panel_version }}</div> @endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">Brak licencji.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
    {{ $licenses->links() }}
@endsection
