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

    @include('panel.admin._groups')
@endsection
