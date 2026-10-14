@extends('layouts.panel')

@section('title', $webhook->name)

@section('content')
    <div class="page-header">
        <div><h1>{{ $webhook->name }}</h1>
            <div class="meta-line"><span><code>{{ $webhook->url }}</code></span>
                <span class="pill {{ $webhook->is_active ? 'ok' : 'neutral' }}">{{ $webhook->is_active ? __('aktywny') : __('wyłączony') }}</span></div></div>
        <div class="btn-row">
            <a class="btn" href="{{ route('panel.admin.webhooks') }}">{{ __('← Webhooki') }}</a>
            <form method="POST" action="{{ route('panel.admin.webhooks.ping', $webhook) }}" style="margin:0">@csrf
                <button class="btn" type="submit">{{ __('Wyślij test') }}</button></form>
        </div>
    </div>

    @if (session('webhook_secret'))
        <div class="card" style="margin-bottom:16px; border-color:var(--warning)">
            <h3 class="card-title"><x-icon name="key" :size="16"/> {{ __('Sekret podpisu') }}</h3>
            <input type="text" readonly value="{{ session('webhook_secret') }}" onclick="this.select()" aria-label="{{ __('Sekret podpisu') }}">
            <p class="hint">{{ __('Zapisz go u odbiorcy — panel nie pokaże go ponownie.') }}</p>
        </div>
    @endif

    @if (! $webhook->is_active && $webhook->consecutive_failures >= \App\Domain\Webhooks\Webhooks::DISABLE_AFTER)
        <div class="alert alert-error"><div>{{ __('Webhook wyłączony automatycznie po :count nieudanych dostarczeniach z rzędu. Popraw adres i włącz go ponownie.', ['count' => $webhook->consecutive_failures]) }}</div></div>
    @endif

    <details class="card" @if ($errors->any()) open @endif>
        <summary><strong>{{ __('Ustawienia') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.webhooks.update', $webhook) }}" style="margin-top:14px">
            @csrf @method('PUT')
            @include('panel.admin.webhooks._fields', ['webhook' => $webhook, 'suffix' => '-e'])
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; margin-bottom:12px">
                <input type="checkbox" name="is_active" value="1" style="width:auto" @checked($webhook->is_active)> {{ __('Aktywny') }}
            </label>
            <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
        </form>
        <div class="btn-row" style="margin-top:12px">
            <form method="POST" action="{{ route('panel.admin.webhooks.secret', $webhook) }}" style="margin:0" data-confirm="{{ __('Wygenerować nowy sekret? Odbiorca odrzuci żądania, dopóki go nie zaktualizujesz.') }}">@csrf
                <button class="btn" type="submit">{{ __('Nowy sekret') }}</button></form>
            <form method="POST" action="{{ route('panel.admin.webhooks.destroy', $webhook) }}" style="margin:0" data-confirm="{{ __('Usunąć webhook razem z historią dostarczeń?') }}">@csrf @method('DELETE')
                <button class="btn btn-danger" type="submit">{{ __('Usuń webhook') }}</button></form>
        </div>
    </details>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Dostarczenia (30 dni)') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Czas') }}</th><th>{{ __('Zdarzenie') }}</th><th>{{ __('Status') }}</th><th>{{ __('Odpowiedź') }}</th><th class="num">{{ __('Próby') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($deliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->created_at->format('d.m.Y H:i:s') }}</td>
                        <td><code>{{ $delivery->event }}</code></td>
                        <td>
                            @php $tone = ['success' => 'ok', 'pending' => 'warning', 'failed' => 'critical'][$delivery->status] ?? 'neutral'; @endphp
                            <span class="pill {{ $tone }}">{{ ['success' => __('dostarczone'), 'pending' => __('w kolejce'), 'failed' => __('nieudane')][$delivery->status] ?? $delivery->status }}</span>
                            @if ($delivery->status === 'pending' && $delivery->attempts > 0 && $delivery->next_attempt_at)
                                <div class="hint">{{ __('kolejna próba :time', ['time' => $delivery->next_attempt_at->diffForHumans()]) }}</div>
                            @endif
                        </td>
                        <td>{{ $delivery->response_code ? 'HTTP '.$delivery->response_code : '' }} {{ $delivery->error && ! $delivery->response_code ? \Illuminate\Support\Str::limit($delivery->error, 60) : '' }}
                            @if ($delivery->duration_ms !== null) <span class="muted">{{ $delivery->duration_ms }} ms</span> @endif</td>
                        <td class="num">{{ $delivery->attempts }}</td>
                        <td style="text-align:right; white-space:nowrap">
                            <details style="display:inline-block; text-align:left"><summary class="btn btn-sm">{{ __('Treść') }}</summary>
                                <pre class="secret" style="white-space:pre-wrap; font-size:12px; max-width:640px; max-height:320px; overflow:auto">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                @if ($delivery->response_body)
                                    <pre class="secret" style="white-space:pre-wrap; font-size:12px; max-width:640px; max-height:200px; overflow:auto">{{ $delivery->response_body }}</pre>
                                @endif
                            </details>
                            <form method="POST" action="{{ route('panel.admin.webhooks.redeliver', $delivery) }}" style="display:inline; margin:0">@csrf
                                <button class="btn btn-sm" type="submit">{{ __('Wyślij ponownie') }}</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Jeszcze nic nie wysłano.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $deliveries->links() }}
@endsection
