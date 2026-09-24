@extends('layouts.panel')

@php
    use App\Domain\Access\Permissions;

    $exists = $user->exists;
    $actor = auth()->user();
    $self = $exists && $actor->is($user);
    $roleLabel = ['admin' => 'Administrator — pełny dostęp', 'support' => 'Wsparcie — obsługa klientów i wybrane działy', 'customer' => 'Klient — własne maszyny'];
    $role = old('role', $user->role ?? 'customer');
    $custom = (bool) old('custom_permissions', $user->permissions !== null);
    $selected = old('permissions', $user->permissions ?? ($defaults[$role] ?? []));
    $restrict = (bool) old('restrict_packages', $user->allowed_package_ids !== null);
    $allowedPackages = array_map('intval', old('allowed_package_ids', $user->allowed_package_ids ?? []));
@endphp

@section('title', $exists ? $user->email : 'Nowe konto')

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ $exists ? $user->name : 'Nowe konto' }}</h1>
            <div class="meta-line">
                @if ($exists)
                    <span>{{ $user->email }}</span>
                    @if ($user->isSuspended()) <span class="pill critical">zablokowane</span> @endif
                    <span class="sep">·</span><span>maszyny: {{ $user->servers()->count() }}</span>
                    <span class="sep">·</span><span>ostatnie logowanie: {{ $user->last_login_at?->diffForHumans() ?? 'nigdy' }}</span>
                @else
                    <span>Konto klienta albo personelu. Hasło możesz nadać albo wygenerować.</span>
                @endif
            </div>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.admin.users') }}"><x-icon name="arrow-left" :size="16"/> Lista</a>
        </div>
    </div>

    @if (session('generated_password'))
        <div class="alert alert-info">
            <strong>Hasło do przekazania użytkownikowi — zapisz je teraz, nie zobaczysz go ponownie.</strong>
            <p class="secret" style="margin-top:10px">{{ session('generated_password') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ $exists ? route('panel.admin.users.update', $user) : route('panel.admin.users.store') }}" id="user-form">
        @csrf
        @if ($exists) @method('PUT') @endif

        <div class="grid grid-2">
            <div class="card">
                <h3 class="card-title"><x-icon name="users" :size="16"/> Konto</h3>
                <div class="field">
                    <label for="name">Nazwa</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
                </div>
                <div class="field">
                    <label for="email">E-mail (login)</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
                </div>
                <div class="field">
                    <label for="password">{{ $exists ? 'Nowe hasło' : 'Hasło' }} <span class="muted">(opcjonalnie)</span></label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="10"
                           placeholder="{{ $exists ? 'zostaw puste, żeby nie zmieniać' : 'puste = wygeneruj' }}">
                    <div class="hint">Co najmniej 10 znaków.{{ $exists ? '' : ' Wygenerowane hasło pokażemy raz, po zapisaniu.' }}</div>
                </div>
                <div class="field">
                    <label for="role">Rola</label>
                    <select id="role" name="role" @disabled($self)>
                        @foreach ($roles as $r)
                            <option value="{{ $r }}" @selected($role === $r)>{{ $roleLabel[$r] }}</option>
                        @endforeach
                    </select>
                    @if ($self)
                        <input type="hidden" name="role" value="{{ $user->role }}">
                        <div class="hint">Nie możesz zmienić roli własnego konta.</div>
                    @endif
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><x-icon name="package" :size="16"/> Limity</h3>
                <div class="field">
                    <label for="max_servers">Limit maszyn</label>
                    <input id="max_servers" name="max_servers" type="number" min="0"
                           value="{{ old('max_servers', $user->max_servers) }}" placeholder="domyślnie {{ $globalLimit }}">
                    <div class="hint">Puste = limit globalny ({{ $globalLimit }}). Personel bez limitu zamawia bez ograniczeń.</div>
                </div>
                <div class="field">
                    <label style="font-weight:600; display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="restrict_packages" value="1" style="width:auto" id="restrict" @checked($restrict)>
                        Tylko wybrane pakiety
                    </label>
                </div>
                <div class="field" data-packages>
                    @forelse ($packages as $package)
                        <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                            <input type="checkbox" name="allowed_package_ids[]" value="{{ $package->id }}" style="width:auto"
                                   @checked(in_array($package->id, $allowedPackages, true))>
                            {{ $package->name }}
                            <span class="muted">— {{ $package->vcpu }} vCPU, {{ $package->ramGb() }} GB RAM{{ $package->is_active ? '' : ', wycofany' }}</span>
                        </label>
                    @empty
                        <p class="muted">Brak pakietów.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px" data-permissions>
            <h3 class="card-title"><x-icon name="lock" :size="16"/> Uprawnienia</h3>
            <div data-admin-note @if($role !== 'admin') hidden @endif>
                <p class="muted" style="margin:0">Administrator ma zawsze wszystkie uprawnienia.</p>
            </div>
            <div data-permission-editor @if($role === 'admin') hidden @endif>
                <div class="field">
                    <label style="font-weight:600; display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="custom_permissions" value="1" style="width:auto" id="custom" @checked($custom)>
                        Własne uprawnienia zamiast domyślnych dla roli
                    </label>
                    <div class="hint">Bez zaznaczenia konto dostaje domyślny zestaw roli — i zmiany domyślnych obejmą je automatycznie.</div>
                </div>

                <div class="grid grid-2" style="gap:24px">
                    <div>
                        <div class="nav-section" style="margin:0 0 8px">Własne maszyny</div>
                        @foreach (Permissions::SERVER as $key => [$label, $hint])
                            <label style="font-weight:400; display:flex; gap:10px; align-items:flex-start; margin-bottom:10px">
                                <input type="checkbox" name="permissions[]" value="{{ $key }}" style="width:auto; margin-top:4px"
                                       data-permission @checked(in_array($key, $selected, true))>
                                <span><strong>{{ $label }}</strong><br><span class="hint">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <div data-staff-only @if($role === 'customer') hidden @endif>
                        <div class="nav-section" style="margin:0 0 8px">Administracja (personel)</div>
                        @foreach (Permissions::ADMIN as $key => [$label, $hint])
                            <label style="font-weight:400; display:flex; gap:10px; align-items:flex-start; margin-bottom:10px">
                                <input type="checkbox" name="permissions[]" value="{{ $key }}" style="width:auto; margin-top:4px"
                                       data-permission @checked(in_array($key, $selected, true)) @disabled(! $actor->isAdmin())>
                                <span><strong>{{ $label }}</strong><br><span class="hint">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="btn-row" style="margin-top:4px">
            <button class="btn btn-primary" type="submit">{{ $exists ? 'Zapisz zmiany' : 'Utwórz konto' }}</button>
        </div>
    </form>

    @if ($exists && ! $self)
        <h2>Działania na koncie</h2>
        <div class="card">
            <div class="btn-row">
                <form method="POST" action="{{ route('panel.admin.users.password', $user) }}" style="margin:0"
                      onsubmit="return confirm('Wygenerować nowe hasło? Stare przestanie działać, a tokeny API zostaną unieważnione.')">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="key" :size="16"/> Nowe hasło</button>
                </form>
                @if ($user->isSuspended())
                    <form method="POST" action="{{ route('panel.admin.users.unsuspend', $user) }}" style="margin:0">
                        @csrf
                        <button class="btn" type="submit">Odblokuj konto</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('panel.admin.users.suspend', $user) }}" style="margin:0"
                          onsubmit="return confirm('Zablokować konto? Użytkownik zostanie wylogowany, a jego tokeny API unieważnione. Maszyny działają dalej.')">
                        @csrf
                        <button class="btn btn-danger" type="submit">Zablokuj konto</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('panel.admin.users.destroy', $user) }}" style="margin:0"
                      onsubmit="return confirm('Usunąć konto {{ $user->email }}? Tej operacji nie da się cofnąć.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit" @disabled($user->servers()->exists())
                            title="{{ $user->servers()->exists() ? 'Konto ma maszyny — usuń je najpierw' : '' }}">Usuń konto</button>
                </form>
            </div>
            <p class="hint" style="margin-top:10px">
                Zablokowane konto nie zaloguje się ani nie użyje API; jego maszyny działają dalej
                (do zatrzymania ich służy zawieszenie maszyn).
            </p>
        </div>
    @endif

    @push('scripts')
        <script>
            // Uprawnienia: domyślne roli podglądowo, własne po zaznaczeniu.
            (function () {
                const form = document.getElementById('user-form');
                const defaults = @js($defaults);
                const role = form.querySelector('#role');
                const custom = form.querySelector('#custom');
                const restrict = form.querySelector('#restrict');

                function sync(resetToDefaults) {
                    const r = role.value;
                    form.querySelector('[data-admin-note]').hidden = r !== 'admin';
                    form.querySelector('[data-permission-editor]').hidden = r === 'admin';
                    form.querySelector('[data-staff-only]').hidden = r === 'customer';
                    const own = custom.checked;
                    form.querySelectorAll('[data-permission]').forEach((box) => {
                        if (!own || resetToDefaults) box.checked = (defaults[r] || []).includes(box.value);
                        box.closest('label').style.opacity = own ? 1 : .6;
                        box.dataset.locked = own ? '' : '1';
                    });
                    form.querySelector('[data-packages]').hidden = !restrict.checked;
                }

                form.querySelectorAll('[data-permission]').forEach((box) => box.addEventListener('click', (e) => {
                    if (box.dataset.locked) { e.preventDefault(); custom.checked = true; sync(false); box.checked = !box.checked; }
                }));
                role.addEventListener('change', () => sync(true));
                custom.addEventListener('change', () => sync(false));
                restrict.addEventListener('change', () => sync(false));
                sync(false);
            })();
        </script>
    @endpush
@endsection
