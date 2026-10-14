@php($t = fn ($key, $vars = []) => \Paymenter\Extensions\Servers\VirtHub\VirtHub::t($key, $vars))
<div class="virthub space-y-4">
    @if (session('virthub_message'))
        <div class="rounded-md bg-green-500/10 text-green-600 px-4 py-2">{{ session('virthub_message') }}</div>
    @endif
    @if (session('virthub_error'))
        <div class="rounded-md bg-red-500/10 text-red-600 px-4 py-2">{{ session('virthub_error') }}</div>
    @endif
    @isset($error)
        <div class="rounded-md bg-yellow-500/10 text-yellow-600 px-4 py-2">{{ $error }}</div>
    @else
        <div class="flex items-center gap-3">
            <h3 class="text-lg font-semibold">{{ $server['hostname'] }}</h3>
            <span class="rounded-full px-2 py-0.5 text-sm {{ $server['state'] === 'running' ? 'bg-green-500/15 text-green-600' : 'bg-base/10' }}">{{ $t('state.'.$server['state']) }}</span>
        </div>
        @if ($server['state'] === 'building')
            <div class="h-2 rounded bg-base/10"><div class="h-2 rounded bg-primary" style="width: {{ (int) $server['build_progress'] }}%"></div></div>
            <p class="text-sm">{{ $t('build_progress', ['percent' => (int) $server['build_progress']]) }}</p>
        @endif
        @if (!empty($server['busy']))<p class="text-sm opacity-75">{{ $t('busy') }}</p>@endif

        <div class="grid md:grid-cols-2 gap-4">
            <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="opacity-70">{{ $t('ip_addresses') }}</dt><dd>@foreach ($ips as $ip)<code>{{ $ip }}</code><br>@endforeach</dd>
                <dt class="opacity-70">{{ $t('os') }}</dt><dd>{{ $server['guest_os']['name'] ?? ($server['os']['name'] ?? '') }}</dd>
                <dt class="opacity-70">{{ $t('resources') }}</dt><dd>{{ $server['resources']['vcpu'] }} vCPU · {{ $server['resources']['ram_mb'] }} MB RAM · {{ $server['resources']['disk_gb'] }} GB</dd>
                <dt class="opacity-70">{{ $t('traffic') }}</dt><dd>{{ $traffic }}
                    @if ($trafficPercent !== null)<div class="h-1.5 mt-1 rounded bg-base/10"><div class="h-1.5 rounded bg-primary" style="width: {{ $trafficPercent }}%"></div></div>@endif</dd>
                <dt class="opacity-70">{{ $t('username') }}</dt><dd><code>{{ $username }}</code></dd>
                @if ($password)
                    <dt class="opacity-70">{{ $t('password') }}</dt><dd><code>{{ $password }}</code></dd>
                @endif
            </dl>

            <div class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('extensions.servers.virthub.password', $service) }}" onsubmit="return confirm(@js($t('confirm_reset_password')))">
                        @csrf <button class="rounded-md bg-base/10 px-3 py-1.5 text-sm" type="submit">{{ $t('reset_password') }}</button>
                    </form>
                    <form method="POST" action="{{ route('extensions.servers.virthub.credentials', $service) }}">
                        @csrf <button class="rounded-md bg-base/10 px-3 py-1.5 text-sm" type="submit">{{ $t('show_password') }}</button>
                    </form>
                </div>
                @if ($templates)
                    <form method="POST" action="{{ route('extensions.servers.virthub.reinstall', $service) }}" class="space-y-2">
                        @csrf
                        <h4 class="font-semibold">{{ $t('reinstall') }}</h4>
                        <p class="text-sm opacity-75">{{ $t('reinstall_warning') }}</p>
                        <select name="template" required class="w-full rounded-md bg-background-secondary border border-neutral px-2 py-1.5" aria-label="{{ $t('reinstall_select') }}">
                            @foreach ($templates as $tpl)
                                <option value="{{ $tpl['id'] }}" @selected(($server['os']['id'] ?? null) === $tpl['id'])>{{ $tpl['name'] }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> {{ $t('reinstall_confirm') }}</label>
                        <button class="rounded-md bg-red-600 text-white px-3 py-1.5 text-sm" type="submit">{{ $t('reinstall') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endisset
</div>
