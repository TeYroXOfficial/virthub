@extends('layouts.panel')

@section('title', __('Webhooki'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Webhooki') }}</h1>
        <p class="lede">{{ __('Panel wysyła podpisane powiadomienia JSON o zdarzeniach (serwery, użytkownicy, kopie, faktury) do Twoich systemów.') }}</p></div></div>

    <div class="card flush dash-section">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Zdarzenia') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ostatnie dostarczenie') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($webhooks as $webhook)
                    <tr>
                        <td><strong>{{ $webhook->name }}</strong></td>
                        <td><code style="word-break:break-all">{{ \Illuminate\Support\Str::limit($webhook->url, 60) }}</code></td>
                        <td>{{ in_array('*', $webhook->events ?? [], true) ? __('wszystkie') : count($webhook->events ?? []) }}</td>
                        <td>
                            <span class="pill {{ $webhook->is_active ? 'ok' : 'neutral' }}">{{ $webhook->is_active ? __('aktywny') : __('wyłączony') }}</span>
                            @if ($webhook->pending_count) <span class="pill warning plain">{{ trans_choice(':count w kolejce|:count w kolejce|:count w kolejce', $webhook->pending_count) }}</span> @endif
                            @if ($webhook->failed_count) <span class="pill critical plain">{{ trans_choice(':count nieudane|:count nieudane|:count nieudanych', $webhook->failed_count) }}</span> @endif
                        </td>
                        <td>{{ $webhook->last_delivery_at?->diffForHumans() ?? __('nigdy') }}</td>
                        <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.admin.webhooks.show', $webhook) }}">{{ __('Szczegóły') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Brak webhooków.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <details class="card" @if ($webhooks->isEmpty() || $errors->any()) open @endif>
        <summary><strong>{{ __('Nowy webhook') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.webhooks.store') }}" style="margin-top:14px">
            @csrf
            @include('panel.admin.webhooks._fields', ['webhook' => null, 'suffix' => ''])
            <button class="btn btn-primary" type="submit">{{ __('Dodaj webhook') }}</button>
        </form>
    </details>

    <div class="card">
        <h3 class="card-title"><x-icon name="key" :size="16"/> {{ __('Weryfikacja podpisu') }}</h3>
        <p class="muted">{{ __('Każde żądanie ma nagłówki X-VirtHub-Event, X-VirtHub-Delivery i X-VirtHub-Signature: t=<czas>,v1=<podpis>. Podpis to HMAC-SHA256 z „<czas>.<treść żądania>” kluczem webhooka. Odrzucaj żądania starsze niż 5 minut. Odpowiedź 2xx oznacza dostarczenie; inaczej panel ponawia po 1 min, 5 min, 30 min, 2 h i 6 h.') }}</p>
        <pre class="secret" style="white-space:pre-wrap; font-size:12px">$t = explode(',', $_SERVER['HTTP_X_VIRTHUB_SIGNATURE']);
$ts = substr($t[0], 2); $sig = substr($t[1], 3);
$ok = abs(time() - $ts) &lt; 300
   &amp;&amp; hash_equals(hash_hmac('sha256', $ts.'.'.file_get_contents('php://input'), $secret), $sig);</pre>
    </div>
@endsection
