<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') — {{ config('virthub.brand') }}</title>
    <style>
        /* Style są wbudowane w layout celowo: panel administracyjny musi wstać
           na świeżym serwerze bez kroku budowania assetów (npm/Vite). */
        :root {
            --bg: #f6f7f9;
            --surface: #ffffff;
            --surface-alt: #edf0f3;
            --border: #dbe1e7;
            --text: #172230;
            --muted: #57677a;
            --accent: #146b63;
            --accent-soft: #e4f1ef;
            --ok: #1a7f4b;
            --warn: #9a5a1e;
            --critical: #a3282d;
            --radius: 8px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1620; --surface: #16202c; --surface-alt: #1d2836;
                --border: #2a3745; --text: #e7ecf1; --muted: #92a3b5;
                --accent: #4fd8c4; --accent-soft: #16332f;
                --ok: #4ec98a; --warn: #e0a667; --critical: #ef7c81;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        code, .mono { font-family: "Cascadia Code", Consolas, "SF Mono", monospace; font-size: 13px; }
        a { color: var(--accent); }

        header.topbar {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 28px; background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .brand { font-weight: 700; font-size: 16px; letter-spacing: -0.01em; text-decoration: none; color: var(--text); }
        .brand span { color: var(--accent); }
        nav.topnav { display: flex; gap: 16px; margin-left: auto; align-items: center; font-size: 14px; }
        nav.topnav a { text-decoration: none; color: var(--muted); }
        nav.topnav a:hover, nav.topnav a[aria-current="page"] { color: var(--text); }

        main { max-width: 1100px; margin: 0 auto; padding: 32px 28px 80px; }
        h1 { font-size: 24px; margin: 0 0 6px; letter-spacing: -0.01em; }
        h2 { font-size: 17px; margin: 32px 0 12px; }
        .lede { color: var(--muted); margin: 0 0 24px; }

        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px 22px; margin-bottom: 16px;
        }
        .card h3 { margin: 0 0 14px; font-size: 15px; }

        .grid { display: grid; gap: 14px; }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table-wrap { overflow-x: auto; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        td.num { font-variant-numeric: tabular-nums; }

        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px;
            background: var(--surface-alt); color: var(--muted);
        }
        .pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .pill.ok { color: var(--ok); background: color-mix(in srgb, var(--ok) 12%, transparent); }
        .pill.warning { color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent); }
        .pill.critical { color: var(--critical); background: color-mix(in srgb, var(--critical) 12%, transparent); }
        .pill.neutral { color: var(--muted); }

        .btn {
            display: inline-block; padding: 8px 16px; border-radius: 6px; cursor: pointer;
            border: 1px solid var(--border); background: var(--surface); color: var(--text);
            font: inherit; font-size: 14px; text-decoration: none;
        }
        .btn:hover { background: var(--surface-alt); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
        @media (prefers-color-scheme: dark) { .btn-primary { color: #0f1620; } }
        .btn-primary:hover { filter: brightness(1.08); background: var(--accent); }
        .btn-danger { color: var(--critical); border-color: color-mix(in srgb, var(--critical) 40%, var(--border)); }
        .btn-row { display: flex; flex-wrap: wrap; gap: 8px; }

        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
        .hint { font-size: 12.5px; color: var(--muted); font-weight: 400; margin-top: 4px; }
        input[type=text], input[type=email], input[type=password], select, textarea {
            width: 100%; padding: 9px 11px; border: 1px solid var(--border);
            border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .field { margin-bottom: 18px; }

        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 14px; }
        .alert-info { background: var(--accent-soft); border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent); }
        .alert-error { background: color-mix(in srgb, var(--critical) 10%, transparent); border: 1px solid color-mix(in srgb, var(--critical) 35%, transparent); }
        .alert ul { margin: 4px 0 0; padding-left: 18px; }

        .kv { display: grid; grid-template-columns: max-content 1fr; gap: 8px 20px; font-size: 14px; }
        .kv dt { color: var(--muted); }
        .kv dd { margin: 0; }

        .meter { height: 6px; background: var(--surface-alt); border-radius: 3px; overflow: hidden; margin-top: 6px; }
        .meter > i { display: block; height: 100%; background: var(--accent); }
        .meter.hot > i { background: var(--warn); }

        .empty { text-align: center; padding: 40px 20px; color: var(--muted); }
        .muted { color: var(--muted); }
        .secret {
            font-family: "Cascadia Code", Consolas, monospace; font-size: 14px;
            background: var(--surface-alt); padding: 10px 14px; border-radius: 6px;
            word-break: break-all; user-select: all;
        }
    </style>
</head>
<body>
@auth
    <header class="topbar">
        <a class="brand" href="{{ route('panel.dashboard') }}">
            {{ config('virthub.brand') }}<span>.</span>
        </a>
        <nav class="topnav">
            <a href="{{ route('panel.dashboard') }}"
               @if(request()->routeIs('panel.dashboard')) aria-current="page" @endif>Moje maszyny</a>
            <a href="{{ route('panel.servers.create') }}"
               @if(request()->routeIs('panel.servers.create')) aria-current="page" @endif>Zamów VPS</a>
            <span class="muted">{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button class="btn" type="submit">Wyloguj</button>
            </form>
        </nav>
    </header>
@endauth

<main>
    @if (session('status'))
        <div class="alert alert-info">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            <strong>Nie udało się wykonać operacji:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
