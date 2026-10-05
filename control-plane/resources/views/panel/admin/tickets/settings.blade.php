@extends('layouts.panel')

@section('title', __('Działy i odpowiedzi'))

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.tickets') }}" style="font-size:13px">{{ __('← Zgłoszenia') }}</a>
            <h1>{{ __('Działy i odpowiedzi') }}</h1>
            <p class="lede">{{ __('Działy, do których klienci kierują zgłoszenia, gotowe odpowiedzi personelu i automatyczne zamykanie.') }}</p>
        </div>
    </div>

    <div class="card">
        <form method="POST" action="{{ route('panel.admin.tickets.settings.update') }}" class="filter-bar">
            @csrf @method('PUT')
            <div class="field" style="margin:0">
                <label for="s-close">{{ __('Zamykaj po dniach bez odpowiedzi klienta') }}</label>
                <input id="s-close" type="number" name="autoclose_days" min="0" max="365" value="{{ old('autoclose_days', $autoclose) }}" style="max-width:140px">
            </div>
            <button class="btn" type="submit">{{ __('Zapisz') }}</button>
            <span class="hint" style="margin:0 0 10px">{{ __('Dotyczy zgłoszeń ze stanem „odpowiedziano”. 0 wyłącza automatyczne zamykanie.') }}</span>
        </form>
    </div>

    <div class="card flush dash-section" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Działy') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Opis') }}</th><th>{{ __('Kolejność') }}</th><th>{{ __('Aktywny') }}</th><th>{{ __('Zgłoszenia') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($departments as $d)
                    <tr>
                        <td colspan="4">
                            <form method="POST" action="{{ route('panel.admin.tickets.departments.update', $d) }}" class="filter-bar" id="dept-{{ $d->id }}">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $d->name }}" required maxlength="100" aria-label="{{ __('Nazwa') }}" style="max-width:200px">
                                <input name="description" value="{{ $d->description }}" maxlength="255" aria-label="{{ __('Opis') }}" style="flex:1; min-width:200px">
                                <input type="number" name="sort_order" value="{{ $d->sort_order }}" min="0" max="1000" aria-label="{{ __('Kolejność') }}" style="max-width:90px">
                                <label class="check-line" style="margin:0"><input type="checkbox" name="is_active" value="1" @checked($d->is_active)> {{ __('aktywny') }}</label>
                                <button class="btn btn-sm" type="submit">{{ __('Zapisz') }}</button>
                            </form>
                        </td>
                        <td class="num">{{ $d->tickets_count }}</td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('panel.admin.tickets.departments.destroy', $d) }}" style="margin:0" data-confirm="{{ __('Usunąć dział :name?', ['name' => $d->name]) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <form method="POST" action="{{ route('panel.admin.tickets.departments.store') }}" class="filter-bar" style="padding:14px 18px; border-top:1px solid var(--border)">
            @csrf
            <input type="hidden" name="is_active" value="1">
            <div class="field" style="margin:0"><label for="d-name">{{ __('Nowy dział') }}</label><input id="d-name" name="name" required maxlength="100"></div>
            <div class="field" style="margin:0; flex:1"><label for="d-desc">{{ __('Opis') }}</label><input id="d-desc" name="description" maxlength="255"></div>
            <button class="btn" type="submit"><x-icon name="plus" :size="15"/> {{ __('Dodaj') }}</button>
        </form>
    </div>

    <div class="card dash-section" style="margin-top:16px">
        <h3 class="card-title">{{ __('Gotowe odpowiedzi') }}</h3>
        @foreach ($canned as $c)
            <details class="form-block">
                <summary>{{ $c->title }}</summary>
                <form method="POST" action="{{ route('panel.admin.tickets.canned.update', $c) }}">
                    @csrf @method('PUT')
                    <div class="field"><input name="title" value="{{ $c->title }}" required maxlength="100" aria-label="{{ __('Tytuł') }}"></div>
                    <div class="field"><textarea name="body" rows="5" class="prose-input" required maxlength="10000" aria-label="{{ __('Treść') }}">{{ $c->body }}</textarea></div>
                    <div class="btn-row">
                        <button class="btn btn-sm" type="submit">{{ __('Zapisz') }}</button>
                        <button class="btn btn-sm btn-ghost" type="submit" form="canned-del-{{ $c->id }}">{{ __('Usuń') }}</button>
                    </div>
                </form>
                <form id="canned-del-{{ $c->id }}" method="POST" action="{{ route('panel.admin.tickets.canned.destroy', $c) }}" data-confirm="{{ __('Usunąć gotową odpowiedź?') }}">
                    @csrf @method('DELETE')
                </form>
            </details>
        @endforeach
        <details class="form-block" @if ($canned->isEmpty()) open @endif>
            <summary>{{ __('Nowa gotowa odpowiedź') }}</summary>
            <form method="POST" action="{{ route('panel.admin.tickets.canned.store') }}">
                @csrf
                <div class="field"><label for="c-title">{{ __('Tytuł') }}</label><input id="c-title" name="title" required maxlength="100"></div>
                <div class="field"><label for="c-body">{{ __('Treść') }}</label><textarea id="c-body" name="body" rows="5" class="prose-input" required maxlength="10000"></textarea></div>
                <button class="btn" type="submit">{{ __('Dodaj') }}</button>
            </form>
        </details>
    </div>
@endsection
