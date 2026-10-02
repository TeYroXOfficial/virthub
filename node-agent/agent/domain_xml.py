"""Budowanie definicji domeny libvirt (XML).

Wydzielone jako czysta funkcja bez zależności od libvirt — dzięki temu kształt
maszyny, którą dostaje klient, da się przetestować na dowolnej maszynie
deweloperskiej, bez hypervisora.
"""

from __future__ import annotations

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
    disk_path: str,
    seed_path: str | None,
    bridge: str,
    mac: str,
    interface_target: str,
    vnc_listen: str,
    vnc_password: str,
    windows: bool = False,
    allow_nested: bool = False,
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
    devices = [
        f"""    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2' cache='none' io='native' discard='unmap'/>
      <source file='{escape(disk_path)}'/>
      <target dev='vda' bus='virtio'/>
    </disk>"""
    ]

    if seed_path:
        devices.append(
            f"""    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{escape(seed_path)}'/>
      <target dev='sda' bus='sata'/>
      <readonly/>
    </disk>"""
        )

    devices.append(
        f"""    <interface type='bridge'>
      <source bridge='{escape(bridge)}'/>
      <mac address='{escape(mac)}'/>
      <target dev='{escape(interface_target)}'/>
      <model type='virtio'/>
    </interface>"""
    )

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

    return f"""<domain type='kvm'>
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
