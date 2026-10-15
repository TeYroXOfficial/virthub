@extends('layouts.panel')

@section('title', __('Grupy hypervisorów'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Grupy hypervisorów') }}</h1>
            <p class="lede">
                {{ __('Grupa to zwykle jedna lokalizacja: węzły korzystają z jej pul adresów, a klient może ją wybrać przy zamówieniu, jeśli jest widoczna. Wyłączenie przyjmowania maszyn wstrzymuje sprzedaż na wszystkich węzłach grupy naraz.') }}
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('panel.admin.hypervisor-groups.placement') }}" class="card btn-row" style="align-items:flex-end">
        @csrf @method('PUT')
        <div class="field" style="margin:0; flex:1; min-width:260px">
            <label for="placement">{{ __('Domyślne rozmieszczanie nowych maszyn') }}</label>
            <select id="placement" name="placement_strategy">
                @foreach (\App\Domain\Provisioning\HypervisorSelector::STRATEGIES as $strategy)
                    <option value="{{ $strategy }}" @selected(\App\Domain\Provisioning\HypervisorSelector::defaultStrategy() === $strategy)>{{ \App\Domain\Provisioning\HypervisorSelector::label($strategy) }}</option>
                @endforeach
            </select>
            <div class="hint">{{ __('Dotyczy zamówień bez wybranej lokalizacji i grup bez własnej strategii.') }}</div>
        </div>
        <button class="btn" type="submit">{{ __('Zapisz') }}</button>
    </form>

    @include('panel.admin._groups')
@endsection
