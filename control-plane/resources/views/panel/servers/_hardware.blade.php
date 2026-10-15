{{-- Dyski i sprzęt maszyny KVM: personel — wszystko, klient — podgląd i rozruch BIOS/UEFI. --}}
@php
    use App\Domain\Provisioning\ServerHardware as HW;
    $isStaff = auth()->user()->can('manageResources', $server);
    $canBoot = $isStaff || auth()->user()->can('rebuild', $server);
    $extraDisks = $server->disks;
    $hw = HW::resolve($server);
    $sourceLabel = ['server' => __('maszyna'), 'template' => __('szablon'), 'hypervisor' => __('węzeł'), 'default' => __('domyślne')];
    $inherit = fn (string $key) => __('dziedzicz (:value — :source)', ['value' => is_bool($hw[$key]['value']) ? ($hw[$key]['value'] ? __('tak') : __('nie')) : ($hw[$key]['value'] ?? '—'), 'source' => $sourceLabel[$hw[$key]['source']] ?? '']);
@endphp

@unless ($server->isContainer())
    <div class="card" id="hardware">
        <h3 class="card-title"><x-icon name="disc" :size="16"/> {{ __('Dyski i sprzęt') }}</h3>
        @error('hardware') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror

        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Dysk') }}</th><th>{{ __('Rozmiar') }}</th>@if ($isStaff)<th></th>@endif</tr></thead>
                <tbody>
                <tr>
                    <td><code>vda</code> <span class="muted">{{ __('główny') }}</span></td>
                    <td>{{ $server->disk_gb }} GB</td>
                    @if ($isStaff)
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('panel.servers.disks.primary', $server) }}" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0;justify-content:flex-end">
                                @csrf @method('PUT')
                                <input name="size_gb" type="number" min="{{ $server->disk_gb + 1 }}" max="65536" placeholder="{{ $server->disk_gb + 10 }}" style="width:90px" required>
                                <button class="btn btn-sm" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Powiększ') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
                @foreach ($extraDisks as $disk)
                    <tr>
                        <td><code>{{ $disk->dev() }}</code> <span class="muted">{{ __('dodatkowy') }}</span></td>
                        <td>{{ $disk->size_gb }} GB</td>
                        @if ($isStaff)
                            <td style="text-align:right">
                                <form method="POST" action="{{ route('panel.servers.disks.update', [$server, $disk]) }}" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0;justify-content:flex-end">
                                    @csrf @method('PUT')
                                    <input name="size_gb" type="number" min="{{ $disk->size_gb + 1 }}" max="65536" placeholder="{{ $disk->size_gb + 10 }}" style="width:90px" required>
                                    <button class="btn btn-sm" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Powiększ') }}</button>
                                </form>
                                <form method="POST" action="{{ route('panel.servers.disks.destroy', [$server, $disk]) }}" style="margin:0;display:inline"
                                      data-confirm="{{ __('Usunąć dysk :dev? Wszystkie dane z tego dysku zostaną bezpowrotnie skasowane.', ['dev' => $disk->dev()]) }}">
                                    @csrf @method('DELETE') <input type="hidden" name="confirm" value="1">
                                    <button class="btn btn-sm btn-danger" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Usuń') }}</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($isStaff && $extraDisks->count() < \App\Models\ServerDisk::MAX_SLOTS)
            <form method="POST" action="{{ route('panel.servers.disks.store', $server) }}" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px">
                @csrf
                <input name="size_gb" type="number" min="1" max="65536" placeholder="{{ __('GB') }}" style="width:110px" required>
                <button class="btn btn-sm" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Dodaj dysk') }}</button>
                <span class="hint">{{ __('Dysk pojawi się w systemie od razu, także na działającej maszynie. Nowy dysk trzeba sformatować i zamontować w systemie.') }}</span>
            </form>
        @endif
        @if ($extraDisks->isNotEmpty())
            <p class="hint">{{ __('Dodatkowe dyski nie są objęte migawkami, kopiami na cele zewnętrzne ani migracją — obejmują one tylko dysk główny.') }}</p>
        @endif

        @if ($canBoot)
            <form method="POST" action="{{ route('panel.servers.hardware', $server) }}" class="grid grid-3" style="margin-top:12px;align-items:end">
                @csrf @method('PUT')
                <div class="field"><label>{{ __('Rozruch') }}</label>
                    <select name="hw_firmware">
                        <option value="">{{ $inherit('firmware') }}</option>
                        <option value="bios" @selected($server->hw_firmware === 'bios')>BIOS</option>
                        <option value="uefi" @selected($server->hw_firmware === 'uefi')>UEFI</option>
                    </select></div>
                @if ($isStaff)
                    <div class="field"><label>{{ __('vTPM 2.0') }}</label>
                        <select name="hw_tpm">
                            <option value="">{{ $inherit('tpm') }}</option>
                            <option value="1" @selected($server->hw_tpm === true)>{{ __('włączony') }}</option>
                            <option value="0" @selected($server->hw_tpm === false)>{{ __('wyłączony') }}</option>
                        </select></div>
                    <div class="field"><label>{{ __('Magistrala dysków') }}</label>
                        <select name="hw_disk_bus">
                            <option value="">{{ $inherit('disk_bus') }}</option>
                            <option value="virtio" @selected($server->hw_disk_bus === 'virtio')>VirtIO</option>
                            <option value="scsi" @selected($server->hw_disk_bus === 'scsi')>{{ __('VirtIO SCSI (wątek I/O)') }}</option>
                        </select></div>
                    <div class="field"><label>{{ __('Tryb I/O') }}</label>
                        <select name="hw_io_mode">
                            <option value="">{{ $inherit('io_mode') }}</option>
                            @foreach (HW::IO_MODES as $mode) <option value="{{ $mode }}" @selected($server->hw_io_mode === $mode)>{{ $mode }}</option> @endforeach
                        </select></div>
                    <div class="field"><label>{{ __('Model procesora') }}</label>
                        <select name="hw_cpu_model">
                            <option value="">{{ $inherit('cpu_model') }}</option>
                            @foreach (HW::CPU_MODELS as $model) <option value="{{ $model }}" @selected($server->hw_cpu_model === $model)>{{ $model }}</option> @endforeach
                        </select></div>
                    <div class="field"><label>{{ __('Oświecenia Hyper-V') }}</label>
                        <select name="hw_hyperv">
                            <option value="">{{ $inherit('hyperv') }}</option>
                            @foreach (['off' => __('wyłączone'), 'standard' => __('standardowe'), 'full' => __('pełne (TSC, IPI, TLB, vendor_id)'), 'passthrough' => __('passthrough (wszystko z hosta)')] as $value => $label) <option value="{{ $value }}" @selected($server->hw_hyperv === $value)>{{ $label }}</option> @endforeach
                        </select></div>
                @endif
                <div class="field"><button class="btn" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Zapisz') }}</button></div>
            </form>
            <p class="hint">{{ __('Zmiany działają po wyłączeniu i włączeniu maszyny. Zmiana BIOS ↔ UEFI wymaga systemu zainstalowanego w tym trybie — inaczej maszyna nie wystartuje.') }}
                @if ($hw['cpuset']['value']) {{ __('Rdzenie hosta (z węzła): :cpuset.', ['cpuset' => $hw['cpuset']['value']]) }} @endif</p>
        @endif
    </div>
@endunless
