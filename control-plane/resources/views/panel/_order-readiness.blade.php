{{-- Podpowiedzi dla personelu: dlaczego klient nie może niczego zamówić. --}}
@if (auth()->user()?->hasAnyAdminPermission())
    @php $issues = app(\App\Domain\Provisioning\OrderReadiness::class)->issues(); @endphp
    @if ($issues !== [])
        <div class="alert alert-warning" role="status">
            <div>
                <strong>{{ __('Widzisz to jako administrator. Klienci nie mogą teraz zamówić maszyny:') }}</strong>
                <ul style="margin:6px 0 0; padding-left:18px">
                    @foreach ($issues as $issue)
                        <li>{{ $issue['message'] }} @if ($issue['url'])<a href="{{ $issue['url'] }}">{{ __('Popraw') }}</a>@endif</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endif
