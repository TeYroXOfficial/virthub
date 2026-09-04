@extends('layouts.panel')

@section('title', 'Konsola — '.$server->hostname)

@section('content')
    <h1>Konsola: {{ $server->hostname }}</h1>
    <p class="lede">
        Dostęp do ekranu maszyny niezależny od jej sieci — działa nawet wtedy,
        gdy zepsuta konfiguracja odcięła SSH.
    </p>

    @if ($proxyConfigured)
        <div class="card" style="padding:0; overflow:hidden">
            <div id="vnc-screen" style="min-height:520px; background:#000"></div>
        </div>
        <p class="hint">
            Bilet dostępu został właśnie zużyty. Odświeżenie tej strony wymaga
            otwarcia konsoli z widoku maszyny od nowa.
        </p>
    @else
        <div class="alert alert-info">
            <strong>Konsola nie jest jeszcze uruchomiona na tej instalacji.</strong>
            <p style="margin:8px 0 0">
                Bilet dostępu został poprawnie zweryfikowany, ale panel nie ma skonfigurowanego
                proxy WebSocket, które zestawia tunel do gniazda VNC na hypervisorze.
                Ustaw <code>VIRTHUB_CONSOLE_PROXY_URL</code> w pliku <code>.env</code>,
                gdy komponent proxy zostanie wdrożony (Faza 2 planu).
            </p>
        </div>

        <div class="card">
            <h3>Dostęp alternatywny</h3>
            <p>Do czasu wdrożenia konsoli administrator może połączyć się z maszyną z poziomu hypervisora:</p>
            <p class="secret">virsh console virthub-{{ $server->id }}</p>
            <p class="hint">
                Definicja maszyny ma włączoną konsolę szeregową, więc to polecenie zadziała
                nawet przy niedziałającej sieci gościa.
            </p>
        </div>
    @endif

    <div class="btn-row">
        <a class="btn" href="{{ route('panel.servers.show', $server) }}">Wróć do maszyny</a>
    </div>
@endsection
