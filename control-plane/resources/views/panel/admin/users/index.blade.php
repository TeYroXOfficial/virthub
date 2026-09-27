@extends('layouts.panel')

@section('title', __('Użytkownicy'))

@php
    $roleLabel = ['admin' => __('administrator'), 'support' => __('wsparcie'), 'customer' => __('klient')];
    $roleTone = ['admin' => 'critical', 'support' => 'info', 'customer' => 'neutral'];
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Użytkownicy') }}</h1>
            <p class="lede">{{ __('Konta klientów i personelu, ich uprawnienia, limity i blokady.') }}</p>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="{{ route('panel.admin.users.create') }}"><x-icon name="plus" :size="16"/> {{ __('Nowe konto') }}</a>
        </div>
    </div>

    <form method="GET" class="card" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end">
        <div class="field" style="flex:1; min-width:220px; margin:0">
            <label for="q">{{ __('Szukaj') }}</label>
            <input id="q" name="q" type="text" value="{{ request('q') }}" placeholder="{{ __('e-mail albo nazwa') }}">
        </div>
        <div class="field" style="margin:0">
            <label for="role">{{ __('Rola') }}</label>
            <select id="role" name="role">
                <option value="">{{ __('wszystkie') }}</option>
                @foreach ($roleLabel as $value => $label)
                    <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="status">{{ __('Stan') }}</label>
            <select id="status" name="status">
                <option value="">{{ __('wszystkie') }}</option>
                <option value="suspended" @selected(request('status') === 'suspended')>{{ __('zablokowane') }}</option>
            </select>
        </div>
        <button class="btn" type="submit">{{ __('Filtruj') }}</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Użytkownik') }}</th><th>{{ __('Rola') }}</th><th>{{ __('Maszyny') }}</th><th>{{ __('Uprawnienia') }}</th><th>{{ __('Ostatnie logowanie') }}</th><th></th></tr>
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
                            @if ($u->isSuspended()) <span class="pill critical plain">{{ __('zablokowane') }}</span> @endif
                        </td>
                        <td class="num">{{ $u->servers_count }} / {{ $u->isStaff() && $u->max_servers === null ? '∞' : $u->serverLimit() }}</td>
                        <td class="muted">
                            @if ($u->isAdmin())
                                {{ __('wszystkie') }}
                            @elseif ($u->permissions === null)
                                {{ __('domyślne roli') }}
                            @else
                                {{ __('własne (:effectivepermissions)', ['effectivepermissions' => count($u->effectivePermissions())]) }}
                            @endif
                            @if ($u->allowed_package_ids !== null)
                                <div class="hint">{{ __('pakiety: :allowed_package_ids', ['allowed_package_ids' => count($u->allowed_package_ids)]) }}</div>
                            @endif
                        </td>
                        <td class="muted">{{ $u->last_login_at?->diffForHumans() ?? __('nigdy') }}</td>
                        <td style="text-align:right">
                            @if ($manager->canManage(auth()->user(), $u))
                                <a class="btn btn-sm" href="{{ route('panel.admin.users.edit', $u) }}">{{ __('Edytuj') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Brak użytkowników spełniających kryteria.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $users->links() }}
@endsection
