@extends('layout')
@section('title', $license->exists ? $license->key : 'Nowa licencja')
@section('content')
    <h1>{{ $license->exists ? 'Licencja' : 'Nowa licencja' }} @if ($license->exists) <span class="mono">{{ $license->key }}</span> @endif</h1>
    <form method="POST" action="{{ $license->exists ? route('licenses.update', $license) : route('licenses.store') }}">
        @csrf @if ($license->exists) @method('PUT') @endif
        <div class="card">
            <div class="grid">
                <div class="field"><label for="owner_name">Właściciel</label><input id="owner_name" name="owner_name" required maxlength="150" value="{{ old('owner_name', $license->owner_name) }}"></div>
                <div class="field"><label for="owner_email">E-mail</label><input id="owner_email" name="owner_email" type="email" value="{{ old('owner_email', $license->owner_email) }}"></div>
                <div class="field"><label for="domain">Domena panelu</label><input id="domain" name="domain" value="{{ old('domain', $license->domain) }}" placeholder="pusta — przypnie się przy pierwszej aktywacji">
                    <div class="muted">Zmiana domeny = przeniesienie licencji na inny panel.</div></div>
                <div class="field"><label for="status">Stan</label>
                    <select id="status" name="status">
                        <option value="active" @selected(old('status', $license->status) === 'active')>aktywna</option>
                        <option value="suspended" @selected(old('status', $license->status) === 'suspended')>zawieszona</option>
                    </select></div>
                <div class="field"><label for="expires_at">Ważna do</label><input id="expires_at" name="expires_at" type="date" value="{{ old('expires_at', $license->expires_at?->format('Y-m-d')) }}">
                    <div class="muted">puste = bezterminowo</div></div>
            </div>
            <div class="field"><label for="notes">Notatki</label><textarea id="notes" name="notes" rows="2">{{ old('notes', $license->notes) }}</textarea></div>
        </div>
        <div class="card">
            <h2>Addony</h2>
            @forelse ($addons as $addon)
                @php $pivot = $license->addons->firstWhere('id', $addon->id)?->pivot; @endphp
                <div class="row" style="margin-bottom:8px">
                    <label style="margin:0; min-width:220px; font-weight:400"><input type="checkbox" name="addons[]" value="{{ $addon->id }}" @checked($pivot)> {{ $addon->name }} <span class="mono muted">{{ $addon->slug }}</span></label>
                    <input type="date" name="addon_expires[{{ $addon->id }}]" value="{{ $pivot?->expires_at ? \Illuminate\Support\Carbon::parse($pivot->expires_at)->format('Y-m-d') : '' }}" style="max-width:180px" aria-label="Ważny do">
                    <span class="muted">ważny do (puste = jak licencja)</span>
                </div>
            @empty
                <p class="muted">Brak addonów — dodaj je w zakładce Addony.</p>
            @endforelse
        </div>
        <button class="btn btn-primary" type="submit">Zapisz</button>
    </form>

    @if ($license->exists)
        <div class="card" style="margin-top:16px">
            <h2>Ostatnie zdarzenia</h2>
            <table>
                @forelse ($license->events->take(30) as $e)
                    <tr><td class="muted" style="width:170px">{{ $e->created_at }}</td><td class="mono">{{ $e->action }}</td><td class="mono muted">{{ $e->meta ? json_encode($e->meta) : '' }}</td><td class="mono muted">{{ $e->ip }}</td></tr>
                @empty
                    <tr><td class="muted">Brak zdarzeń.</td></tr>
                @endforelse
            </table>
        </div>
        <form method="POST" action="{{ route('licenses.destroy', $license) }}" onsubmit="return confirm('Usunąć licencję? Panel straci addony przy najbliższym sprawdzeniu.')">
            @csrf @method('DELETE') <button class="btn btn-danger" type="submit">Usuń licencję</button></form>
    @endif
@endsection
