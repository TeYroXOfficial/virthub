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
        ];
        // Działy administracji widoczne tylko z odpowiednim uprawnieniem.
        $adminNav = [
            ['panel.admin.index', __('Przegląd'), 'dashboard', 'panel.admin.index', null],
            ['panel.admin.services', __('Wszystkie usługi'), 'list', 'panel.admin.services', 'admin.servers|admin.apps'],
            ['panel.admin.servers', __('Maszyny'), 'servers', 'panel.admin.servers', 'admin.servers'],
            ['panel.admin.users', __('Użytkownicy'), 'users', 'panel.admin.users*', 'admin.users'],
            ['panel.admin.hypervisors', __('Hypervisory'), 'node', 'panel.admin.hypervisors', 'admin.hypervisors'],
            ['panel.admin.ip-pools', __('Adresy IP'), 'network', 'panel.admin.ip-pools', 'admin.ip_pools'],
            ['panel.admin.packages', __('Pakiety'), 'package', 'panel.admin.packages', 'admin.packages'],
            ['panel.admin.templates', __('Szablony'), 'disc', 'panel.admin.templates', 'admin.templates'],
            ['panel.admin.isos', __('Obrazy ISO'), 'disc', 'panel.admin.isos', 'admin.templates'],
            ['panel.admin.apps', __('Aplikacje'), 'gamepad', 'panel.admin.apps*', 'admin.apps'],
            ['panel.admin.updates', __('Aktualizacje'), 'refresh', 'panel.admin.updates*', 'admin.updates'],
        ];
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
                    @foreach ($adminNav as [$route, $label, $icon, $pattern, $permission])
                        @continue($permission && ! collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p)))
                        <a class="nav-link" href="{{ route($route) }}"
                           @if(request()->routeIs($pattern)) aria-current="page" @endif>
                            <x-icon :name="$icon"/> {{ $label }}
                        </a>
                    @endforeach
                @endif
            </nav>

            @include('layouts._locale')
            <div class="sidebar-footer">
                <span class="avatar">{{ mb_substr($user->name ?: $user->email, 0, 1) }}</span>
                <div class="user-meta">
                    <strong title="{{ $user->email }}">{{ $user->name ?: $user->email }}</strong>
                    <span>{{ $user->isAdmin() ? __('administrator') : ($user->isStaff() ? __('wsparcie') : __('klient')) }}</span>
                </div>
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
