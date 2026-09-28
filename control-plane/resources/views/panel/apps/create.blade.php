@extends('layouts.panel')

@section('title', __('Nowa aplikacja'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Nowa aplikacja') }}</h1>
            <p class="lede">{{ __('Wybierz, co chcesz uruchomić, i plan zasobów. Instalacja startuje od razu — postęp zobaczysz w konsoli.') }}</p>
        </div>
    </div>

    @if ($eggs->isEmpty() || $plans->isEmpty())
        <div class="card empty">
            <x-icon name="gamepad" :size="40"/>
            <p>{{ __('Aplikacje nie są jeszcze dostępne — administrator nie dodał szablonów albo planów.') }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('panel.apps.store') }}">
            @csrf
            @foreach ($eggs as $category => $group)
                <h2 class="section-title">{{ __(\App\Models\AppEgg::CATEGORIES[$category] ?? 'Inne') }}</h2>
                <div class="egg-grid" style="margin-bottom:20px">
                    @foreach ($group as $egg)
                        <label class="egg-card">
                            <input type="radio" name="egg" value="{{ $egg->id }}" required @checked((int) old('egg') === $egg->id)>
                            <strong><x-icon :name="$egg->category === 'bot' ? 'bot' : 'gamepad'" :size="18"/> {{ $egg->name }}</strong>
                            <p>{{ $egg->description }}</p>
                            @if (count($egg->images()) > 1)
                                <select name="image" data-egg="{{ $egg->id }}" aria-label="{{ __('Wersja środowiska') }}" disabled>
                                    @foreach ($egg->images() as $label => $image)
                                        <option value="{{ $image }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </label>
                    @endforeach
                </div>
            @endforeach

            <h2 class="section-title">{{ __('Plan') }}</h2>
            <div class="egg-grid" style="margin-bottom:20px">
                @foreach ($plans as $plan)
                    <label class="egg-card">
                        <input type="radio" name="plan" value="{{ $plan->id }}" required @checked((int) old('plan') === $plan->id)>
                        <strong>{{ $plan->name }}</strong>
                        <p>
                            {{ __(':memory MB RAM · :disk GB dysku', ['memory' => $plan->memory_mb, 'disk' => round($plan->disk_mb / 1024, 1)]) }}<br>
                            {{ $plan->cpu_percent ? __('procesor: :percent% rdzenia', ['percent' => $plan->cpu_percent]) : __('procesor bez limitu') }}
                            · {{ trans_choice(':count port|:count porty|:count portów', $plan->ports, ['count' => $plan->ports]) }}
                            @if ($plan->price_hint_cents)
                                <br><strong>{{ number_format($plan->price_hint_cents / 100, 2, ',', ' ') }} {{ $plan->currency }}</strong>
                            @endif
                        </p>
                    </label>
                @endforeach
            </div>
            @error('plan') <div class="alert alert-error">{{ $message }}</div> @enderror

            <div class="card" style="max-width:520px">
                <div class="field">
                    <label for="app-name">{{ __('Nazwa') }}</label>
                    <input id="app-name" name="name" type="text" maxlength="60" required value="{{ old('name') }}" placeholder="{{ __('np. Serwer survival') }}">
                </div>
                <button class="btn btn-primary" type="submit"><x-icon name="plus" :size="16"/> {{ __('Utwórz aplikację') }}</button>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        // Wybór wersji środowiska (np. Java 21 / 25) dotyczy tylko zaznaczonego szablonu.
        document.querySelectorAll('input[name="egg"]').forEach((radio) => radio.addEventListener('change', () => {
            document.querySelectorAll('select[data-egg]').forEach((s) => { s.disabled = s.dataset.egg !== radio.value; });
        }));
        const checked = document.querySelector('input[name="egg"]:checked');
        if (checked) checked.dispatchEvent(new Event('change'));
    </script>
@endpush
