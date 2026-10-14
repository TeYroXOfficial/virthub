@extends('layouts.panel')

@section('title', $mailout->exists ? $mailout->subject : __('Nowy mailout'))

@php
    $type = old('audience', $mailout->audience['type'] ?? 'all');
    $ids = array_map('intval', old('ids', $mailout->audience['ids'] ?? []));
    $lists = ['hypervisors' => $hypervisors, 'packages' => $packages, 'products' => $products];
    // Składane, bo dosłowne podwójne klamry Blade wziąłby za własne wyrażenie.
    $varList = collect(['user.name', 'user.email', 'servers.count', 'brand', 'panel_url'])->map(fn ($v) => '{'.'{ '.$v.' }'.'}')->implode(', ');
@endphp

@section('content')
    <div class="page-header">
        <div><h1>{{ $mailout->exists ? $mailout->subject : __('Nowy mailout') }}</h1>
            @if ($recipients !== null)
                <div class="meta-line"><span>{{ trans_choice(':count odbiorca|:count odbiorców|:count odbiorców', $recipients) }}</span></div>
            @endif</div>
        <div class="btn-row"><a class="btn" href="{{ route('panel.admin.mailouts') }}">{{ __('← Mailouty') }}</a></div>
    </div>

    <form method="POST" action="{{ $mailout->exists ? route('panel.admin.mailouts.update', $mailout) : route('panel.admin.mailouts.store') }}" class="card">
        @csrf
        @if ($mailout->exists) @method('PUT') @endif
        <div class="field"><label for="m-subject">{{ __('Temat') }}</label>
            <input id="m-subject" name="subject" required maxlength="200" value="{{ old('subject', $mailout->subject) }}" placeholder="{{ __('np. Prace serwisowe w nocy z soboty na niedzielę') }}"></div>
        <div class="field"><label for="m-body">{{ __('Treść (Markdown)') }}</label>
            <textarea id="m-body" name="body" rows="14" required style="font-family:var(--mono)">{{ old('body', $mailout->body) }}</textarea>
            <div class="hint">{{ __('Zmienne: :vars.', ['vars' => $varList]) }}</div></div>

        <div class="grid grid-2">
            <div class="field"><label for="m-aud">{{ __('Odbiorcy') }}</label>
                <select id="m-aud" name="audience" onchange="document.querySelectorAll('[data-aud]').forEach(el => el.hidden = el.dataset.aud !== this.value)">
                    @foreach ($audiences as $key => $label) <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option> @endforeach
                </select></div>
            <div>
                @foreach ($lists as $key => $items)
                    <div class="field" data-aud="{{ $key }}" @if ($type !== $key) hidden @endif>
                        <label>{{ $audiences[$key] }}</label>
                        <div style="max-height:180px; overflow:auto; border:1px solid var(--border); border-radius:var(--radius-sm); padding:6px 10px">
                            @forelse ($items as $item)
                                <label style="font-weight:400; display:flex; gap:8px; align-items:center">
                                    <input type="checkbox" name="ids[]" value="{{ $item->id }}" style="width:auto" @checked($type === $key && in_array($item->id, $ids, true))> {{ $item->name }}
                                </label>
                            @empty
                                <span class="muted">{{ __('Brak pozycji.') }}</span>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <label style="font-weight:400; display:flex; gap:8px; align-items:center; margin-bottom:12px">
            <input type="checkbox" name="service_announcement" value="1" style="width:auto" @checked(old('service_announcement', $mailout->exists && ! $mailout->respect_opt_out))>
            {{ __('Ogłoszenie serwisowe — wyślij także do osób wypisanych z newslettera (awarie, prace, zmiany regulaminu)') }}
        </label>
        <button class="btn btn-primary" type="submit">{{ __('Zapisz szkic') }}</button>
    </form>

    @if ($mailout->exists)
        @if ($preview)
            <div class="card">
                <h3 class="card-title"><x-icon name="mail" :size="16"/> {{ __('Podgląd (z Twoimi danymi)') }}</h3>
                <p><strong>{{ $preview['subject'] }}</strong></p>
                <div style="border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; background:#fff; color:#0f1a26">{!! $preview['html'] !!}</div>
            </div>
        @endif
        <div class="card">
            <div class="btn-row">
                <form method="POST" action="{{ route('panel.admin.mailouts.test', $mailout) }}" style="margin:0">@csrf
                    <button class="btn" type="submit">{{ __('Wyślij test do mnie') }}</button></form>
                <form method="POST" action="{{ route('panel.admin.mailouts.start', $mailout) }}" style="margin:0"
                      data-confirm="{{ trans_choice('Wysłać mailout do :count odbiorcy? Tego nie da się cofnąć.|Wysłać mailout do :count odbiorców? Tego nie da się cofnąć.|Wysłać mailout do :count odbiorców? Tego nie da się cofnąć.', (int) $recipients) }}">@csrf
                    <button class="btn btn-primary" type="submit" @disabled(! $recipients)>{{ __('Wyślij teraz') }}</button></form>
                <form method="POST" action="{{ route('panel.admin.mailouts.destroy', $mailout) }}" style="margin:0" data-confirm="{{ __('Usunąć szkic?') }}">@csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit">{{ __('Usuń') }}</button></form>
            </div>
            <p class="hint" style="margin-top:8px">{{ __('Wysyłka idzie partiami po :count wiadomości na minutę; postęp zobaczysz na tej stronie.', ['count' => \App\Domain\Mail\Mailouts::BATCH]) }}</p>
        </div>
    @endif
@endsection
