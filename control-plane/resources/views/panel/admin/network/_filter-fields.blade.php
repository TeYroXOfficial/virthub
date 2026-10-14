@php $p = $profile; $rulesJson = $p && $p->rules ? json_encode($p->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ''; @endphp
<div class="grid grid-2">
    <div class="field"><label for="nf-name{{ $suffix }}">{{ __('Nazwa') }}</label>
        <input id="nf-name{{ $suffix }}" name="name" required maxlength="100" value="{{ $p?->name }}" placeholder="{{ __('np. Standard, Bez filtrowania, Mail zablokowany') }}"></div>
    <div class="field"><label for="nf-desc{{ $suffix }}">{{ __('Opis') }}</label>
        <input id="nf-desc{{ $suffix }}" name="description" maxlength="500" value="{{ $p?->description }}"></div>
</div>
@foreach ([
    'anti_spoofing' => [__('Anty-spoofing'), __('Ruch tylko z adresów maszyny i tylko IPv4/IPv6/ARP (bez znaczników VLAN i innych protokołów L2).')],
    'block_router_advertisements' => [__('Blokuj ogłoszenia routera IPv6 (RA)'), __('Maszyna nie przejmie ruchu IPv6 sąsiadów, ogłaszając się jako router.')],
    'block_dhcp_server' => [__('Blokuj serwer DHCP / DHCPv6'), __('Maszyna nie rozda adresów innym maszynom w sieci.')],
] as $field => [$label, $hint])
    <label style="font-weight:400; display:flex; gap:8px; align-items:flex-start; margin-bottom:6px">
        <input type="checkbox" name="{{ $field }}" value="1" style="width:auto; margin-top:4px" @checked($p ? $p->{$field} : true)>
        <span><strong>{{ $label }}</strong><br><span class="hint">{{ $hint }}</span></span>
    </label>
@endforeach
<div class="field"><label for="nf-rules{{ $suffix }}">{{ __('Reguły administratora (JSON)') }}</label>
    <textarea id="nf-rules{{ $suffix }}" name="rules" rows="6" spellcheck="false" style="font-family:var(--mono); font-size:12px" placeholder='[{"action": "drop", "direction": "out", "protocol": "tcp", "port_from": 25}]'>{{ $rulesJson }}</textarea>
    <div class="hint">{{ __('Lista reguł: action (accept|drop), direction (in|out), protocol (tcp|udp|icmp|any), port_from, port_to, source (IP/CIDR drugiej strony). Działają przed zaporą klienta i klient ich nie zmieni.') }}</div></div>
<label style="font-weight:400; display:flex; gap:8px; align-items:center; margin-bottom:12px">
    <input type="checkbox" name="is_default" value="1" style="width:auto" @checked($p?->is_default)> {{ __('Profil domyślny (maszyny bez wybranego profilu)') }}
</label>
