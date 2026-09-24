@if (session('status'))
    <div class="alert alert-info" role="status"><div>{{ session('status') }}</div></div>
@endif

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <div>
            <strong>Nie udało się wykonać operacji:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
