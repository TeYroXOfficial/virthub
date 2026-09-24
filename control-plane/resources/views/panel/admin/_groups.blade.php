{{-- Grupy węzłów: wspólne pule adresów, lokalizacja do wyboru przy zamówieniu, wstrzymanie sprzedaży. --}}
<h2 id="groups" class="section-head">Grupy węzłów</h2>
<p class="lede">
    Grupa to zwykle jedna lokalizacja: węzły korzystają z jej pul adresów, a klient może ją wybrać
    przy zamówieniu, jeśli jest widoczna. Wyłączenie przyjmowania maszyn wstrzymuje sprzedaż na
    wszystkich węzłach grupy naraz.
</p>

@php
    $groupFields = function ($group) {
        return [
            'location' => $group?->location,
            'is_public' => (bool) $group?->is_public,
            'accepts' => $group ? $group->accepts_new_servers : true,
            'sort' => $group?->sort_order ?? 0,
        ];
    };
@endphp

<details class="form-block card" @if($groups->isEmpty()) open @endif>
    <summary>Utwórz grupę</summary>
    <form method="POST" action="{{ route('panel.admin.hypervisor-groups.store') }}" style="margin-top:12px">
        @csrf
        @include('panel.admin._group-fields', ['group' => null, 'f' => $groupFields(null), 'idp' => 'new'])
        @include('panel.admin._group-members', ['group' => null])
        <button class="btn btn-primary" type="submit">Utwórz grupę</button>
    </form>
</details>

@foreach ($groups as $group)
    <div class="card">
        <h3 class="card-title">
            <x-icon name="network" :size="16"/> {{ $group->name }}
            <span style="margin-left:auto; display:flex; gap:6px; flex-wrap:wrap">
                @if ($group->is_public)
                    <span class="pill ok">widoczna: {{ $group->publicName() }}</span>
                @endif
                @unless ($group->accepts_new_servers)
                    <span class="pill warning">sprzedaż wstrzymana</span>
                @endunless
                <span class="pill neutral">{{ $group->hypervisors->count() }} węzł. · {{ $group->ip_pools_count }} pul</span>
            </span>
        </h3>
        @if ($group->description)
            <p class="muted" style="margin-top:0">{{ $group->description }}</p>
        @endif
        @if ($group->hypervisors->isNotEmpty())
            <p style="margin:0 0 8px">
                @foreach ($group->hypervisors as $member)
                    <a class="pill {{ $member->isOnline() ? 'ok' : 'neutral' }}" style="text-decoration:none"
                       href="{{ route('panel.admin.hypervisors.show', $member) }}">{{ $member->name }}</a>
                @endforeach
            </p>
        @endif

        <details class="form-block">
            <summary>Ustawienia grupy</summary>
            <form method="POST" action="{{ route('panel.admin.hypervisor-groups.update', $group) }}">
                @csrf @method('PUT')
                @include('panel.admin._group-fields', ['group' => $group, 'f' => $groupFields($group), 'idp' => $group->id])
                @include('panel.admin._group-members', ['group' => $group])
                <button class="btn btn-primary" type="submit">Zapisz</button>
            </form>
        </details>

        @if ($group->ip_pools_count === 0)
            <form method="POST" action="{{ route('panel.admin.hypervisor-groups.destroy', $group) }}"
                  onsubmit="return confirm('Usunąć grupę {{ $group->name }}? Węzły zostaną bez grupy.')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-danger" type="submit">Usuń grupę</button>
            </form>
        @else
            <p class="hint">Grupę z pulami adresów można usunąć po usunięciu jej pul.</p>
        @endif
    </div>
@endforeach
