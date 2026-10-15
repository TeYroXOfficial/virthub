"""Budowanie definicji domeny libvirt (XML).

Wydzielone jako czysta funkcja bez zależności od libvirt — dzięki temu kształt
maszyny, którą dostaje klient, da się przetestować na dowolnej maszynie
deweloperskiej, bez hypervisora.
"""

from __future__ import annotations

from typing import Any
from xml.sax.saxutils import escape


# Windows: oświecenia Hyper-V (mniej przerwań, szybszy zegar i blokady) — bez
# nich Windows w KVM działa zauważalnie wolniej.
HYPERV = """    <hyperv mode='custom'>
      <relaxed state='on'/>
      <vapic state='on'/>
      <spinlocks state='on' retries='8191'/>
      <vpindex state='on'/>
      <synic state='on'/>
      <stimer state='on'/>
    </hyperv>
"""


# Profile oświeceń Hyper-V (węzeł → szablon → maszyna w panelu):
# standard — powyższy zestaw; full — także zegar TSC, przerwania IPI, flush TLB,
# reset i vendor_id (sterowniki GPU NVIDIA w gościu); passthrough — wszystko,
# co obsługuje QEMU na tym hoście (tylko gdy węzły mają ten sam procesor i QEMU).
HYPERV_FULL_EXTRA = (
    ("vpindex", {}), ("runtime", {}), ("synic", {}), ("stimer", {}), ("reset", {}),
    ("vendor_id", {"value": "VirtHubKVM"}), ("frequencies", {}), ("reenlightenment", {}),
    ("tlbflush", {}), ("ipi", {}),
)


def hyperv_element(profile: str) -> Any:
    from xml.etree import ElementTree as ET

    if profile == "passthrough":
        return ET.Element("hyperv", {"mode": "passthrough"})
    el = ET.Element("hyperv", {"mode": "custom"})
    ET.SubElement(el, "relaxed", {"state": "on"})
    ET.SubElement(el, "vapic", {"state": "on"})
    ET.SubElement(el, "spinlocks", {"state": "on", "retries": "8191"})
    extra = HYPERV_FULL_EXTRA if profile == "full" else (("vpindex", {}), ("synic", {}), ("stimer", {}))
    for name, attrs in extra:
        ET.SubElement(el, name, {"state": "on", **attrs})
    return el


def apply_hyperv(root: Any, profile: str) -> None:
    """Podmienia <hyperv> w <features> i zegar hypervclock (oraz TSC dla full)."""
    from xml.etree import ElementTree as ET

    features = root.find("features")
    if features is None:
        features = ET.SubElement(root, "features")
    for old in features.findall("hyperv"):
        features.remove(old)
    clock = root.find("clock")
    if clock is not None:
        for timer in clock.findall("timer"):
            if timer.get("name") in ("hypervclock", "tsc"):
                clock.remove(timer)
    if profile == "off":
        return
    features.append(hyperv_element(profile))
    if clock is not None:
        ET.SubElement(clock, "timer", {"name": "hypervclock", "present": "yes"})
        if profile == "full":
            ET.SubElement(clock, "timer", {"name": "tsc", "present": "yes", "mode": "native"})


def cpu_xml(allow_nested: bool = False) -> str:
    if allow_nested:
        return "  <cpu mode='host-passthrough' check='none'/>"
    return (
        "  <cpu mode='host-passthrough' check='none'>\n"
        "    <feature policy='disable' name='vmx'/>\n"
        "    <feature policy='disable' name='svm'/>\n"
        "  </cpu>"
    )


def build_domain_xml(
    *,
    name: str,
    uuid: str,
    vcpu: int,
    ram_mb: int,
    cpu_limit_percent: int | None = None,
    disk_path: str = "",
    seed_path: str | None,
    bridge: str,
    mac: str,
    interface_target: str,
    vnc_listen: str,
    vnc_password: str,
    windows: bool = False,
    allow_nested: bool = False,
    disk_xml: str | None = None,
    extra_interfaces: list[str] | None = None,
    extra_disks: list[str] | None = None,
    hardware: Any = None,
    interface_xml: str | None = None,
    arch: str = "x86_64",
) -> str:
    """Zwraca XML domeny gotowy do `virsh define`.

    Wybory, które nie są oczywiste:

    * `host-passthrough` — gość widzi prawdziwy model CPU hosta, co daje dostęp
      do AES-NI i reszty rozszerzeń. Kosztem jest brak migracji live na węzeł o
      innym procesorze; przy jednym hypervisorze to nie jest ograniczenie, a
      przy wielu i tak wymagana byłaby jednorodna flota (patrz Faza 5).
    * konsola szeregowa — bez niej klient z zepsutą konfiguracją sieci nie ma
      jak wejść na maszynę, a logi cloud-init z pierwszego bootu przepadają.
    * `discard='unmap'` — TRIM z gościa zwalnia miejsce w pliku qcow2, inaczej
      thin-provisioning przestaje działać po pierwszym zapełnieniu dysku.
    * bez `vmx`/`svm` — gość nie widzi wirtualizacji sprzętowej, więc nie uruchomi
      własnego KVM (zagnieżdżanie to główna droga ucieczek do hosta, np. Januscape
      i Zapscape). Działa niezależnie od ustawienia `nested` modułu na hoście.
    """
    # disk_xml: gotowy element dysku z warstwy magazynów (np. Ceph RBD).
    devices = [
        disk_xml or f"""    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2' cache='none' io='native' discard='unmap'/>
      <source file='{escape(disk_path)}'/>
      <target dev='vda' bus='virtio'/>
    </disk>"""
    ]
    # Dodatkowe dyski (vdb…) zaraz po głównym.
    devices.extend(extra_disks or [])

    if seed_path:
        devices.append(
            f"""    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{escape(seed_path)}'/>
      <target dev='sda' bus='sata'/>
      <readonly/>
    </disk>"""
        )

    devices.append(interface_xml or primary_interface_xml("bridge", bridge, mac, interface_target))
    # Karty w sieciach prywatnych — po karcie głównej, żeby ta pozostała pierwszą (eth0/ens3).
    devices.extend(extra_interfaces or [])

    devices.append(
        f"""    <graphics type='vnc' port='-1' autoport='yes' listen='{escape(vnc_listen)}'
              passwd='{escape(vnc_password)}'>
      <listen type='address' address='{escape(vnc_listen)}'/>
    </graphics>"""
    )

    devices.append(
        """    <serial type='pty'>
      <target type='isa-serial' port='0'/>
    </serial>
    <console type='pty'>
      <target type='serial' port='0'/>
    </console>
    <channel type='unix'>
      <target type='virtio' name='org.qemu.guest_agent.0'/>
    </channel>
    <video>
      <model type='virtio' heads='1'/>
    </video>
    <rng model='virtio'>
      <backend model='random'>/dev/urandom</backend>
    </rng>
    <memballoon model='virtio'/>"""
    )

    devices_xml = "\n".join(devices)

    xml = f"""<domain type='kvm'>
  <name>{escape(name)}</name>
  <uuid>{escape(uuid)}</uuid>
  <memory unit='MiB'>{ram_mb}</memory>
  <currentMemory unit='MiB'>{ram_mb}</currentMemory>
  <vcpu placement='static'>{vcpu}</vcpu>
{cputune_xml(cpu_limit_percent)}  <os>
    <type arch='x86_64' machine='q35'>hvm</type>
    <boot dev='hd'/>
  </os>
  <features>
    <acpi/>
    <apic/>
{HYPERV if windows else ""}  </features>
{cpu_xml(allow_nested)}
  <clock offset='{"localtime" if windows else "utc"}'>
    <timer name='rtc' tickpolicy='catchup'/>
    <timer name='pit' tickpolicy='delay'/>
    <timer name='hpet' present='no'/>
{"    <timer name='hypervclock' present='yes'/>" + chr(10) if windows else ""}  </clock>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
{devices_xml}
  </devices>
</domain>
"""
    if arch == "aarch64":
        xml = adapt_aarch64(xml)
    return with_hardware(xml, hardware, allow_nested) if hardware is not None else xml


def domain_arch(root: Any) -> str:
    type_el = root.find("./os/type")
    return (type_el.get("arch") if type_el is not None else None) or "x86_64"


def adapt_aarch64(xml: str) -> str:
    """Definicja dla węzła ARM (aarch64): maszyna `virt` z UEFI (AAVMF), GICv3,
    konsola PL011, nośnik cloud-init na VirtIO SCSI i mysz/klawiatura USB dla VNC
    — bez elementów istniejących tylko na x86 (APIC, zegary RTC/PIT/HPET, vmx/svm,
    oświecenia Hyper-V, SATA)."""
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    os_el = root.find("os")
    type_el = os_el.find("type")
    type_el.set("arch", "aarch64")
    type_el.set("machine", "virt")
    os_el.set("firmware", "efi")
    features = root.find("features")
    for child in list(features):
        if child.tag in ("apic", "hyperv"):
            features.remove(child)
    ET.SubElement(features, "gic", {"version": "3"})
    cpu = root.find("cpu")
    for feature in cpu.findall("feature"):
        cpu.remove(feature)
    clock = root.find("clock")
    for timer in list(clock):
        clock.remove(timer)
    devices = root.find("devices")
    devices.find("emulator").text = "/usr/bin/qemu-system-aarch64"
    for serial in devices.findall("serial"):
        serial.find("target").set("type", "system-serial")
        ET.SubElement(serial.find("target"), "model", {"name": "pl011"})
    for disk in devices.findall("disk"):
        target = disk.find("target")
        if disk.get("device") == "cdrom" and target is not None and target.get("bus") == "sata":
            target.set("bus", "scsi")
    if not any(c.get("type") == "scsi" for c in devices.findall("controller")):
        first = next((i for i, el in enumerate(devices) if el.tag == "disk"), 1)
        devices.insert(first, ET.Element("controller", {"type": "scsi", "index": "0", "model": "virtio-scsi"}))
    devices.append(ET.Element("controller", {"type": "usb", "model": "qemu-xhci"}))
    devices.append(ET.Element("input", {"type": "tablet", "bus": "usb"}))
    devices.append(ET.Element("input", {"type": "keyboard", "bus": "usb"}))
    return ET.tostring(root, encoding="unicode")


def primary_interface_xml(backend: str, bridge: str, mac: str, target: str, vlan: int | None = None, direct_dev: str = "") -> str:
    """Karta główna: mostek Linuksa, port Open vSwitch (VLAN tagiem na porcie) albo
    macvtap na karcie fizycznej (tryb bridge — maszyny na tej karcie widzą się nawzajem)."""
    common = (f"      <mac address='{escape(mac)}'/>\n      <target dev='{escape(target)}'/>\n"
              "      <model type='virtio'/>\n")
    if backend == "direct":
        return (f"    <interface type='direct'>\n      <source dev='{escape(direct_dev)}' mode='bridge'/>\n"
                f"{common}    </interface>")
    if backend == "ovs":
        tag = f"      <vlan><tag id='{int(vlan)}'/></vlan>\n" if vlan else ""
        return (f"    <interface type='bridge'>\n      <source bridge='{escape(bridge)}'/>\n"
                f"      <virtualport type='openvswitch'/>\n{tag}{common}    </interface>")
    return (f"    <interface type='bridge'>\n      <source bridge='{escape(bridge)}'/>\n"
            f"{common}    </interface>")


def disk_dev(slot: int) -> str:
    """Nazwa urządzenia dysku: slot 0 = vda (główny), 1–7 = vdb–vdh."""
    return "vd" + "abcdefgh"[slot]


def with_hardware(xml: str, hw: Any, allow_nested: bool = False) -> str:
    """Nakłada ustawienia sprzętu (magistrala i I/O dysków, firmware, vTPM, CPU,
    cpuset) na definicję domeny. Czysta funkcja: ta sama ścieżka dla nowej
    maszyny i dla zmiany istniejącej (zmiana działa po wyłączeniu i włączeniu).
    """
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    devices = root.find("devices")
    if devices is None:
        raise ValueError("Nieprawidłowa definicja domeny — brak <devices>.")
    arm = domain_arch(root) == "aarch64"

    vcpu = root.find("vcpu")
    if vcpu is not None:
        if hw.cpuset:
            vcpu.set("cpuset", hw.cpuset)
        else:
            vcpu.attrib.pop("cpuset", None)

    # --- firmware ---------------------------------------------------------
    os_el = root.find("os")
    if os_el is not None:
        for child in list(os_el):
            if child.tag in ("loader", "nvram", "firmware"):
                os_el.remove(child)
        if hw.firmware == "uefi" or arm:  # ARM (virt) uruchamia się tylko z UEFI
            os_el.set("firmware", "efi")
            # Bez Secure Boot: nie wymaga SMM ani zapisanych kluczy, a Windows 11 i tak się zainstaluje z vTPM.
            fw = ET.Element("firmware")
            ET.SubElement(fw, "feature", {"enabled": "no", "name": "enrolled-keys"})
            ET.SubElement(fw, "feature", {"enabled": "no", "name": "secure-boot"})
            os_el.insert(0, fw)
        else:
            os_el.attrib.pop("firmware", None)

    # --- vTPM -------------------------------------------------------------
    for tpm in devices.findall("tpm"):
        devices.remove(tpm)
    if hw.tpm:
        tpm = ET.SubElement(devices, "tpm", {"model": "tpm-tis-device" if arm else "tpm-crb"})
        ET.SubElement(tpm, "backend", {"type": "emulator", "version": "2.0"})

    # --- procesor ---------------------------------------------------------
    old_cpu = root.find("cpu")
    index = list(root).index(old_cpu) if old_cpu is not None else len(root)
    if old_cpu is not None:
        root.remove(old_cpu)
    # Modele CPU z listy panelu są x86 — na ARM zawsze procesor hosta.
    if hw.cpu_model in (None, "host-passthrough") or (arm and hw.cpu_model != "host-model"):
        cpu = ET.Element("cpu", {"mode": "host-passthrough", "check": "none"})
    elif hw.cpu_model == "host-model":
        cpu = ET.Element("cpu", {"mode": "host-model", "check": "partial"})
    else:
        # Stały model: ta sama maszyna wystartuje (i zmigruje na żywo) na węzłach z różnymi procesorami.
        cpu = ET.Element("cpu", {"mode": "custom", "match": "exact", "check": "partial"})
        ET.SubElement(cpu, "model", {"fallback": "allow"}).text = hw.cpu_model
    if not allow_nested and not arm:
        for name in ("vmx", "svm"):
            ET.SubElement(cpu, "feature", {"policy": "disable", "name": name})
    root.insert(index, cpu)

    # --- dyski ------------------------------------------------------------
    bus = "scsi" if hw.disk_bus == "scsi" else "virtio"
    for disk in devices.findall("disk"):
        if disk.get("device") != "disk":
            continue
        target = disk.find("target")
        if target is not None and target.get("bus") != bus:
            target.set("bus", bus)
            # Adres zależy od magistrali (PCI ↔ drive) — libvirt nada nowy.
            for address in disk.findall("address"):
                disk.remove(address)
        driver = disk.find("driver")
        if driver is not None and disk.get("type") != "network":
            driver.set("io", hw.io_mode)
            if hw.io_mode == "native":
                driver.set("cache", "none")  # io=native działa tylko z O_DIRECT

    for controller in devices.findall("controller"):
        if controller.get("type") == "scsi" and controller.get("model") == "virtio-scsi":
            devices.remove(controller)
    iothreads = root.find("iothreads")
    if bus == "scsi":
        controller = ET.Element("controller", {"type": "scsi", "index": "0", "model": "virtio-scsi"})
        # „Threaded”: kontroler w osobnym wątku I/O, poza głównym wątkiem QEMU.
        ET.SubElement(controller, "driver", {"iothread": "1"})
        first_disk = next((i for i, el in enumerate(devices) if el.tag == "disk"), 0)
        devices.insert(first_disk, controller)
        if iothreads is None:
            iothreads = ET.Element("iothreads")
            root.insert(list(root).index(vcpu) + 1 if vcpu is not None else 0, iothreads)
        iothreads.text = "1"
    elif iothreads is not None:
        root.remove(iothreads)

    if getattr(hw, "hyperv", None) and not arm:
        apply_hyperv(root, hw.hyperv)

    return ET.tostring(root, encoding="unicode")


CPU_PERIOD_US = 100_000


def cpu_quota_us(cpu_limit_percent: int | None) -> int:
    """Przydział czasu CPU całej maszyny na okres 100 ms (−1 = bez limitu)."""
    return cpu_limit_percent * CPU_PERIOD_US // 100 if cpu_limit_percent else -1


def cputune_xml(cpu_limit_percent: int | None) -> str:
    """Twardy limit procesora dla całej domeny — suma wszystkich vCPU.

    `global_quota` (a nie `quota` na vCPU): 150% to półtora rdzenia niezależnie
    od tego, na ile vCPU gość rozłoży obciążenie.
    """
    if not cpu_limit_percent:
        return ""
    return (
        "  <cputune>\n"
        f"    <global_period>{CPU_PERIOD_US}</global_period>\n"
        f"    <global_quota>{cpu_quota_us(cpu_limit_percent)}</global_quota>\n"
        "  </cputune>\n"
    )


def domain_name(server_id: int) -> str:
    """Nazwa domeny w libvirt. Bez nazwy hosta od klienta — ta może się zmieniać
    i zawierać znaki, których libvirt nie przyjmie."""
    return f"virthub-{server_id}"


ISO_TARGET = "sdb"


def with_iso(xml: str, iso_path: str | None, boot_from_iso: bool) -> str:
    """Definicja domeny z płytą ISO w drugim napędzie i kolejnością rozruchu.

    Płyta idzie do osobnego napędu `sdb` — pierwszy (`sda`) to nośnik
    cloud-init, którego nie wolno ruszać. Kolejność rozruchu ustawiamy per
    urządzenie (`<boot order>`) zamiast `<os><boot dev>`: przy dwóch napędach
    CD tylko tak da się wskazać, z której płyty startować. Oba zapisy naraz
    libvirt odrzuca, więc `<os><boot>` usuwamy.
    """
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    devices = root.find("devices")
    os_el = root.find("os")
    if devices is None or os_el is None:
        raise ValueError("Nieprawidłowa definicja domeny — brak <devices> albo <os>.")

    for boot in os_el.findall("boot"):
        os_el.remove(boot)
    for disk in devices.findall("disk"):
        for boot in disk.findall("boot"):
            disk.remove(boot)

    cdrom = next(
        (d for d in devices.findall("disk")
         if d.get("device") == "cdrom" and (d.find("target") is not None and d.find("target").get("dev") == ISO_TARGET)),
        None,
    )

    if iso_path is None:
        if cdrom is not None:
            devices.remove(cdrom)
        boot_from_iso = False
    else:
        if cdrom is None:
            cdrom = ET.Element("disk", {"type": "file", "device": "cdrom"})
            ET.SubElement(cdrom, "driver", {"name": "qemu", "type": "raw"})
            ET.SubElement(cdrom, "target", {"dev": ISO_TARGET, "bus": "sata"})
            ET.SubElement(cdrom, "readonly")
            # Za ostatnim dyskiem, żeby kolejność urządzeń w XML była czytelna.
            disks = devices.findall("disk")
            index = list(devices).index(disks[-1]) + 1 if disks else 0
            devices.insert(index, cdrom)
        source = cdrom.find("source")
        if source is None:
            source = ET.Element("source")
            cdrom.insert(1, source)
        source.attrib.clear()
        source.set("file", iso_path)

    main_disk = next(
        (d for d in devices.findall("disk")
         if d.get("device") == "disk" and d.find("target") is not None and d.find("target").get("dev") == "vda"),
        None,
    )
    if boot_from_iso and cdrom is not None:
        ET.SubElement(cdrom, "boot", {"order": "1"})
        if main_disk is not None:
            ET.SubElement(main_disk, "boot", {"order": "2"})
    elif main_disk is not None:
        ET.SubElement(main_disk, "boot", {"order": "1"})

    return ET.tostring(root, encoding="unicode")


def with_disk_source(xml: str, path: str) -> str:
    """Podmienia plik dysku vda (Elevate: nakładka ↔ dysk bazowy)."""
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    for disk in root.find("devices").findall("disk"):
        target = disk.find("target")
        if disk.get("device") == "disk" and target is not None and target.get("dev") == "vda":
            source = disk.find("source")
            if source is None:
                source = ET.SubElement(disk, "source")
            source.attrib.clear()
            source.set("file", path)
            # Nakładka ma łańcuch backing — libvirt odczyta go sam z pliku.
            backing = disk.find("backingStore")
            if backing is not None:
                disk.remove(backing)
            return ET.tostring(root, encoding="unicode")
    raise ValueError("Domena nie ma dysku vda")


SEED_TARGET = "sda"
RESCUE_DISK_DEV = "vdi"


def with_seed(xml: str, seed_path: str | None) -> str:
    """Dysk konfiguracji (sda) podłączony albo odłączony; reszta definicji bez zmian."""
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    devices = root.find("devices")
    seed = next((d for d in devices.findall("disk") if d.get("device") == "cdrom"
                 and (t := d.find("target")) is not None and t.get("dev") == SEED_TARGET), None)
    if seed_path is None:
        if seed is not None:
            devices.remove(seed)
        return ET.tostring(root, encoding="unicode")
    if seed is None:
        seed = ET.Element("disk", {"type": "file", "device": "cdrom"})
        ET.SubElement(seed, "driver", {"name": "qemu", "type": "raw"})
        ET.SubElement(seed, "source", {"file": seed_path})
        ET.SubElement(seed, "target", {"dev": SEED_TARGET, "bus": "sata"})
        ET.SubElement(seed, "readonly")
        disks = devices.findall("disk")
        index = list(devices).index(disks[-1]) + 1 if disks else 0
        devices.insert(index, seed)
    else:
        source = seed.find("source")
        if source is None:
            source = ET.SubElement(seed, "source")
        source.attrib.clear()
        source.set("file", seed_path)
    return ET.tostring(root, encoding="unicode")


def rescue_xml(xml: str, overlay_path: str, seed_path: str) -> str:
    """Definicja w trybie ratunkowym: vda = system ratunkowy (nakładka na szablon),
    dotychczasowy dysk główny podłączony jako vdi, nośnik cloud-init ratunkowy,
    rozruch z vda (bez ISO). Dysk w Cephie podłącza się tak samo — kopiujemy element."""
    import copy
    from xml.etree import ElementTree as ET

    root = ET.fromstring(with_seed(xml, seed_path))
    devices = root.find("devices")
    main = next((d for d in devices.findall("disk") if d.get("device") == "disk"
                 and (t := d.find("target")) is not None and t.get("dev") == "vda"), None)
    if main is None:
        raise ValueError("Domena nie ma dysku vda")
    bus = main.find("target").get("bus") or "virtio"

    original = copy.deepcopy(main)
    original.find("target").set("dev", RESCUE_DISK_DEV)
    for child in list(original):
        if child.tag in ("address", "boot", "alias"):
            original.remove(child)

    rescue = ET.Element("disk", {"type": "file", "device": "disk"})
    ET.SubElement(rescue, "driver", {"name": "qemu", "type": "qcow2", "cache": "none", "discard": "unmap"})
    ET.SubElement(rescue, "source", {"file": overlay_path})
    ET.SubElement(rescue, "target", {"dev": "vda", "bus": bus})
    index = list(devices).index(main)
    devices.remove(main)
    devices.insert(index, rescue)
    devices.insert(index + 1, original)

    for disk in devices.findall("disk"):
        for boot in disk.findall("boot"):
            disk.remove(boot)
    iso = next((d for d in devices.findall("disk") if d.get("device") == "cdrom"
                and (t := d.find("target")) is not None and t.get("dev") == ISO_TARGET), None)
    if iso is not None:
        devices.remove(iso)
    os_el = root.find("os")
    if os_el is not None and os_el.find("boot") is None:
        ET.SubElement(os_el, "boot", {"dev": "hd"})
    return ET.tostring(root, encoding="unicode")
