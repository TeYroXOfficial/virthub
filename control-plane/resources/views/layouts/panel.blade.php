<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Panel')) — {{ config('virthub.brand') }}</title>
    {{-- Zwykły plik statyczny — panel wstaje na świeżym serwerze bez npm/Vite.
         Znacznik czasu modyfikacji wymusza odświeżenie po aktualizacji. --}}
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ @filemtime(public_path('css/panel.css')) }}">
    @stack('head')
    @if (app()->getLocale() !== 'pl')
        {{-- Tłumaczenia tekstów ze skryptów w public/js (vhT). --}}
        <script>window.VH_T = @js(\App\Support\JsTranslations::for(app()->getLocale()));</script>
    @endif
</head>
<body>
@auth
    @php
        $user = auth()->user();
        $nav = [
            ['panel.dashboard', __('Moje maszyny'), 'servers', 'panel.dashboard', null],
            ['panel.servers.create', __('Zamów serwer'), 'plus', 'panel.servers.create', 'servers.order'],
            ['panel.apps.index', __('Aplikacje'), 'gamepad', 'panel.apps.*', 'apps.order'],
            ['panel.tickets.index', __('Zgłoszenia'), 'ticket', 'panel.tickets.*', null],
        ];
        // Administracja w grupach jak w panelach hostingowych: grupa jest
        // zwijana i otwiera się sama, gdy zawiera bieżącą stronę. Pozycje
        // widoczne tylko z odpowiednim uprawnieniem ('admin' = administrator).
        $adminGroups = [
            [null, null, [
                ['panel.admin.index', __('Pulpit'), 'dashboard', 'panel.admin.index', null],
            ]],
            [__('Usługi'), 'servers', [
                ['panel.admin.services', __('Wszystkie usługi'), 'list', 'panel.admin.services', 'admin.servers|admin.apps'],
                ['panel.admin.servers', __('Maszyny'), 'servers', 'panel.admin.servers', 'admin.servers'],
                ['panel.admin.apps', __('Aplikacje'), 'gamepad', 'panel.admin.apps', 'admin.apps'],
            ]],
            [null, null, [
                ['panel.admin.users', __('Użytkownicy'), 'users', 'panel.admin.users*', 'admin.users'],
            ]],
            [__('Wsparcie'), 'ticket', [
                ['panel.admin.tickets', __('Zgłoszenia'), 'ticket', 'panel.admin.tickets|panel.admin.tickets.show', 'admin.tickets'],
                ['panel.admin.tickets.settings', __('Działy i odpowiedzi'), 'sliders', 'panel.admin.tickets.settings*', 'admin.tickets'],
            ]],
            [__('Infrastruktura'), 'node', [
                ['panel.admin.hypervisors', __('Hypervisory'), 'node', 'panel.admin.hypervisors*', 'admin.hypervisors'],
                ['panel.admin.hypervisor-groups', __('Grupy hypervisorów'), 'network', 'panel.admin.hypervisor-groups', 'admin.hypervisors|admin.ip_pools'],
                ['panel.admin.monitoring', __('Monitorowanie'), 'monitor', 'panel.admin.monitoring*', 'admin.hypervisors'],
                ['panel.admin.security', __('Bezpieczeństwo'), 'shield', 'panel.admin.security*', 'admin.hypervisors'],
            ]],
            [__('Sieć'), 'network', [
                ['panel.admin.ip-pools', __('Bloki IP'), 'network', 'panel.admin.ip-pools*', 'admin.ip_pools'],
                ['panel.admin.network.ipv4', __('Adresy IPv4'), 'network', 'panel.admin.network.ipv4', 'admin.ip_pools'],
                ['panel.admin.network.nat', __('Adresy IPv4 NAT'), 'network', 'panel.admin.network.nat', 'admin.ip_pools'],
                ['panel.admin.network.ipv6', __('Adresy IPv6'), 'network', 'panel.admin.network.ipv6', 'admin.ip_pools'],
                ['panel.admin.network.dns', __('rDNS (PowerDNS)'), 'network', 'panel.admin.network.dns*', 'admin.settings'],
            ]],
            [__('Oferta'), 'package', [
                ['panel.admin.packages', __('Pakiety VPS'), 'package', 'panel.admin.packages*', 'admin.packages'],
                ['panel.admin.apps.plans', __('Plany aplikacji'), 'sliders', 'panel.admin.apps.plans*', 'admin.apps'],
            ]],
            [__('Media'), 'disc', [
                ['panel.admin.templates', __('Szablony systemów'), 'disc', 'panel.admin.templates*', 'admin.templates'],
                ['panel.admin.isos', __('Obrazy ISO'), 'disc', 'panel.admin.isos*', 'admin.templates'],
                ['panel.admin.apps.eggs', __('Szablony aplikacji'), 'puzzle', 'panel.admin.apps.eggs*', 'admin.apps'],
            ]],
            [__('Migracje'), 'upload', [
                ['panel.admin.apps.pterodactyl', __('Z Pterodactyla'), 'upload', 'panel.admin.apps.pterodactyl*', 'admin.apps'],
            ]],
            [__('System'), 'sliders', [
                ['panel.admin.updates', __('Aktualizacje'), 'refresh', 'panel.admin.updates*', 'admin.updates'],
                ['panel.admin.mail', __('Poczta (SMTP)'), 'mail', 'panel.admin.mail*', 'admin.settings'],
                ['panel.admin.emails', __('Szablony e-mail'), 'mail', 'panel.admin.emails*', 'admin.settings'],
                ['panel.admin.logs', __('Dziennik zdarzeń'), 'archive', 'panel.admin.logs', 'admin'],
            ]],
        ];
        $allowed = fn (?string $permission) => $permission === null
            || ($permission === 'admin' ? $user->isAdmin() : collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p)));
    @endphp

    <div class="mobile-bar">
        <button class="icon-btn" type="button" aria-label="{{ __('Menu') }}"
                onclick="document.body.classList.toggle('nav-open')">
            <x-icon name="menu"/>
        </button>
        <a class="brand" href="{{ route('panel.dashboard') }}">
            <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
            {{ config('virthub.brand') }}
        </a>
    </div>

    <div class="shell">
        <aside class="sidebar" onclick="if (event.target.closest('a')) document.body.classList.remove('nav-open')">
            <a class="brand" href="{{ route('panel.dashboard') }}">
                <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
                {{ config('virthub.brand') }}
            </a>

            <nav>
                @foreach ($nav as [$route, $label, $icon, $pattern, $permission])
                    @continue($permission && ! $user->hasPermission($permission))
                    <a class="nav-link" href="{{ route($route) }}"
                       @if(request()->routeIs($pattern) || ($route === 'panel.dashboard' && request()->routeIs('panel.servers.show'))) aria-current="page" @endif>
                        <x-icon :name="$icon"/> {{ $label }}
                    </a>
                @endforeach

                @if ($user->hasAnyAdminPermission())
                    <div class="nav-section">{{ __('Administracja') }}</div>
                    @foreach ($adminGroups as [$groupLabel, $groupIcon, $items])
                        @php $items = array_values(array_filter($items, fn ($i) => $allowed($i[4]))); @endphp
                        @continue($items === [])
                        @if ($groupLabel === null)
                            @foreach ($items as [$route, $label, $icon, $pattern])
                                <a class="nav-link" href="{{ route($route) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>
                                    <x-icon :name="$icon"/> {{ $label }}
                                </a>
                            @endforeach
                        @else
                            @php $groupActive = collect($items)->contains(fn ($i) => request()->routeIs($i[3])); @endphp
                            <details class="nav-group" @if ($groupActive) open @endif>
                                <summary class="nav-link"><x-icon :name="$groupIcon"/> {{ $groupLabel }}</summary>
                                @foreach ($items as [$route, $label, $icon, $pattern])
                                    <a class="nav-link nav-sub" href="{{ route($route) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>
                                        {{ $label }}
                                    </a>
                                @endforeach
                            </details>
                        @endif
                    @endforeach
                @endif
            </nav>

            @include('layouts._locale')
            <div class="sidebar-footer">
                <a href="{{ route('panel.account') }}" class="account-link" title="{{ __('Moje konto') }}">
                    @if ($avatar = $user->avatarUrl())
                        <img class="avatar" src="{{ $avatar }}" alt="">
                    @else
                        <span class="avatar">{{ mb_substr($user->name ?: $user->email, 0, 1) }}</span>
                    @endif
                    <div class="user-meta">
                        <strong title="{{ $user->email }}">{{ $user->name ?: $user->email }}</strong>
                        <span>{{ $user->isAdmin() ? __('administrator') : ($user->isStaff() ? __('wsparcie') : __('klient')) }} · {{ __('Moje konto') }}</span>
                    </div>
                </a>
                <form method="POST" action="{{ route('logout') }}" style="margin:0">
                    @csrf
                    <button class="icon-btn" type="submit" title="{{ __('Wyloguj') }}" aria-label="{{ __('Wyloguj') }}">
                        <x-icon name="logout"/>
                    </button>
                </form>
            </div>
        </aside>

        <main class="content">
            <div class="page">
                @include('layouts._flash')
                @yield('content')
            </div>
        </main>
    </div>
@else
    <main class="auth-wrap">
        <div class="auth-card">
            <div style="display:flex; justify-content:flex-end; margin-bottom:8px">@include('layouts._locale')</div>
            @include('layouts._flash')
            @yield('content')
        </div>
    </main>
@endauth
<script src="{{ asset('js/vh-dialog.js') }}?v={{ @filemtime(public_path('js/vh-dialog.js')) }}"></script>
@stack('scripts')
</body>
</html>
