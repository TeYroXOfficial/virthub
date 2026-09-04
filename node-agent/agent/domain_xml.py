"""Budowanie definicji domeny libvirt (XML).

Wydzielone jako czysta funkcja bez zależności od libvirt — dzięki temu kształt
maszyny, którą dostaje klient, da się przetestować na dowolnej maszynie
deweloperskiej, bez hypervisora.
"""

from __future__ import annotations

from xml.sax.saxutils import escape


def build_domain_xml(
    *,
    name: str,
    uuid: str,
    vcpu: int,
    ram_mb: int,
    disk_path: str,
    seed_path: str | None,
    bridge: str,
    mac: str,
    interface_target: str,
    vnc_listen: str,
    vnc_password: str,
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
  <os>
    <type arch='x86_64' machine='q35'>hvm</type>
    <boot dev='hd'/>
  </os>
  <features>
    <acpi/>
    <apic/>
  </features>
  <cpu mode='host-passthrough' check='none'/>
  <clock offset='utc'>
    <timer name='rtc' tickpolicy='catchup'/>
    <timer name='pit' tickpolicy='delay'/>
    <timer name='hpet' present='no'/>
  </clock>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
{devices_xml}
  </devices>
</domain>
"""


def domain_name(server_id: int) -> str:
    """Nazwa domeny w libvirt. Bez nazwy hosta od klienta — ta może się zmieniać
    i zawierać znaki, których libvirt nie przyjmie."""
    return f"virthub-{server_id}"
