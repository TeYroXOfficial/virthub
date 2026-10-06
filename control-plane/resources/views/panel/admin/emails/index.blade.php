@extends('layouts.panel')

@section('title', __('Szablony e-mail'))

@php
    $groupNames = [
        'account' => __('Konto'), 'server' => __('Maszyny'), 'app' => __('Aplikacje'),
        'ticket' => __('Zgłoszenia'), 'billing' => __('Billing'),
    ];
    $lang = \App\Domain\Mail\EmailTemplates::baseOf(app()->getLocale());
    $mailLocales = collect(app(\App\Domain\Settings\Languages::class)->all())->pluck('name', 'code')->all() ?: ['pl' => 'Polski', 'en' => 'English'];
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Szablony e-mail') }}</h1>
            <p class="lede">{{ __('Wiadomości wysyłane automatycznie: po utworzeniu usługi, reinstalacji, zmianie hasła, przy zgłoszeniach i fakturach. Każdy szablon ma wersję w każdym języku panelu — klient dostaje tę w swoim języku.') }}</p>
        </div>
        <div class="actions"><a class="btn" href="{{ route('panel.admin.mail') }}"><x-icon name="mail" :size="15"/> {{ __('Poczta (SMTP)') }}</a></div>
    </div>

    @foreach ($groups as $group => $templates)
        <div class="card flush dash-section">
            <div class="dash-head"><h3 class="card-title" style="margin:0">{{ $groupNames[$group] ?? $group }}</h3></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Wiadomość') }}</th><th>{{ __('Klucz') }}</th>@foreach ($mailLocales as $name) <th>{{ $name }}</th> @endforeach</tr></thead>
                    <tbody>
                    @foreach ($templates as $t)
                        <tr>
                            <td><strong>{{ $t['name'][$lang] }}</strong></td>
                            <td class="mono muted">{{ $t['key'] }}</td>
                            @foreach (array_keys($mailLocales) as $loc)
                                @php $o = ($overrides[$t['key']] ?? collect())->firstWhere('locale', $loc); @endphp
                                <td class="nowrap">
                                    <a class="btn btn-sm" href="{{ route('panel.admin.emails.edit', [$t['key'], $loc]) }}">{{ __('Edytuj') }}</a>
                                    @if ($o && ! $o->enabled)
                                        <span class="pill neutral">{{ __('wyłączony') }}</span>
                                    @elseif ($o)
                                        <span class="pill info">{{ __('zmieniony') }}</span>
                                    @else
                                        <span class="pill ok plain">{{ __('domyślny') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endsection
