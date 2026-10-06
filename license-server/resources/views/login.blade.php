@extends('layout')
@section('title', 'Logowanie')
@section('content')
    <div class="card" style="max-width:380px; margin:60px auto">
        <h1>VirtHub · Licencje</h1>
        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"></div>
            <div class="field"><label for="password">Hasło</label><input id="password" name="password" type="password" required></div>
            <button class="btn btn-primary" type="submit">Zaloguj</button>
        </form>
    </div>
@endsection
