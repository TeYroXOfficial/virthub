@extends('layouts.panel')

@section('title', 'Użytkownicy')

@php
    $roleLabel = ['admin' => 'administrator', 'support' => 'wsparcie', 'customer' => 'klient'];
    $roleTone = ['admin' => 'critical', 'support' => 'info', 'customer' => 'neutral'];
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>Użytkownicy</h1>
            <p class="lede">Konta klientów i personelu, ich uprawnienia, limity i blokady.</p>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="{{ route('panel.admin.users.create') }}"><x-icon name="plus" :size="16"/> Nowe konto</a>
        </div>
    </div>

    <form method="GET" class="card" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end">
        <div class="field" style="flex:1; min-width:220px; margin:0">
            <label for="q">Szukaj</label>
            <input id="q" name="q" type="text" value="{{ request('q') }}" placeholder="e-mail albo nazwa">
        </div>
        <div class="field" style="margin:0">
            <label for="role">Rola</label>
            <select id="role" name="role">
                <option value="">wszystkie</option>
                @foreach ($roleLabel as $value => $label)
                    <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="status">Stan</label>
            <select id="status" name="status">
                <option value="">wszystkie</option>
                <option value="suspended" @selected(request('status') === 'suspended')>zablokowane</option>
            </select>
        </div>
        <button class="btn" type="submit">Filtruj</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Użytkownik</th><th>Rola</th><th>Maszyny</th><th>Uprawnienia</th><th>Ostatnie logowanie</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($users as $u)
                    <tr>
                        <td>
                            <strong>{{ $u->name }}</strong>
                            <div class="hint">{{ $u->email }}</div>
                        </td>
                        <td>
                            <span class="pill {{ $roleTone[$u->role] ?? 'neutral' }}">{{ $roleLabel[$u->role] ?? $u->role }}</span>
                            @if ($u->isSuspended()) <span class="pill critical plain">zablokowane</span> @endif
                        </td>
                        <td class="num">{{ $u->servers_count }} / {{ $u->isStaff() && $u->max_servers === null ? '∞' : $u->serverLimit() }}</td>
                        <td class="muted">
                            @if ($u->isAdmin())
                                wszystkie
                            @elseif ($u->permissions === null)
                                domyślne roli
                            @else
                                własne ({{ count($u->effectivePermissions()) }})
                            @endif
                            @if ($u->allowed_package_ids !== null)
                                <div class="hint">pakiety: {{ count($u->allowed_package_ids) }}</div>
                            @endif
                        </td>
                        <td class="muted">{{ $u->last_login_at?->diffForHumans() ?? 'nigdy' }}</td>
                        <td style="text-align:right">
                            @if ($manager->canManage(auth()->user(), $u))
                                <a class="btn btn-sm" href="{{ route('panel.admin.users.edit', $u) }}">Edytuj</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Brak użytkowników spełniających kryteria.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $users->links() }}
@endsection
