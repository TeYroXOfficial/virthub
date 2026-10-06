<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — VirtHub Licencje</title>
    <style>
        :root { --bg:#f4f6f8; --surface:#fff; --border:#e1e6eb; --text:#0f1a26; --muted:#677789; --accent:#0e8f80; --ok:#16874d; --warn:#a45f10; --critical:#c0333a; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b1017; --surface:#121922; --border:#243040; --text:#e6edf3; --muted:#8b9bab; --accent:#2bb5a3; } }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
        header { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 16px; display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
        header strong { margin-right:auto; }
        header a { color:var(--text); text-decoration:none; } header a[aria-current] { color:var(--accent); font-weight:600; }
        main { max-width:1100px; margin:0 auto; padding:20px 16px; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:16px; }
        h1 { font-size:22px; margin:0 0 16px; } h2 { font-size:16px; margin:0 0 12px; }
        table { width:100%; border-collapse:collapse; } th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--border); vertical-align:top; }
        th { color:var(--muted); font-weight:500; font-size:12px; text-transform:uppercase; }
        .table-wrap { overflow-x:auto; }
        input, select, textarea { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; }
        input[type=checkbox] { width:auto; }
        label { display:block; font-weight:500; margin-bottom:4px; }
        .field { margin-bottom:12px; } .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
        .btn { display:inline-block; padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); cursor:pointer; font:inherit; text-decoration:none; }
        .btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; } .btn-danger { color:var(--critical); } .btn-sm { padding:4px 10px; font-size:13px; }
        .pill { display:inline-block; padding:1px 8px; border-radius:99px; font-size:12px; border:1px solid currentColor; }
        .ok { color:var(--ok); } .warn { color:var(--warn); } .crit { color:var(--critical); } .muted { color:var(--muted); }
        .mono { font-family:ui-monospace, monospace; } .alert { padding:10px 14px; border-radius:8px; margin-bottom:16px; border:1px solid; }
        .alert-ok { color:var(--ok); } .alert-err { color:var(--critical); }
        .row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    </style>
</head>
<body>
@auth
    <header>
        <strong>VirtHub · Licencje</strong>
        <a href="{{ route('licenses.index') }}" @if (request()->routeIs('licenses.*')) aria-current="page" @endif>Licencje</a>
        <a href="{{ route('addons.index') }}" @if (request()->routeIs('addons.*')) aria-current="page" @endif>Addony</a>
        <form method="POST" action="{{ route('logout') }}" style="margin:0">@csrf <button class="btn btn-sm" type="submit">Wyloguj</button></form>
    </header>
@endauth
<main>
    @if (session('status')) <div class="alert alert-ok">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert alert-err">@foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach</div> @endif
    @yield('content')
</main>
</body>
</html>
