"""Przekazywanie urządzeń PCI (GPU, karty sieciowe) do maszyn KVM.

Lista urządzeń pochodzi z /sys/bus/pci/devices — bez zależności od lspci.
Do maszyny trafiają jako <hostdev managed='yes'>: libvirt sam odpina
urządzenie od sterownika hosta (vfio-pci) przy starcie maszyny i oddaje je
przy wyłączeniu. Wymaga włączonego IOMMU (intel_iommu=on / amd_iommu=on).
"""

from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path
from xml.etree import ElementTree as ET

PCI_ADDRESS = re.compile(r"^([0-9a-f]{4}):([0-9a-f]{2}):([0-9a-f]{2})\.([0-7])$")

# Klasy PCI (pierwsze dwa bajty) — do czytelnych etykiet w panelu.
CLASSES = {
    "0300": "vga", "0302": "gpu", "0380": "display",
    "0200": "ethernet", "0207": "infiniband", "0280": "network",
    "0108": "nvme", "0106": "sata", "0c03": "usb", "0403": "audio", "1200": "accelerator",
}


class PciError(ValueError):
    pass


def _read(path: Path) -> str | None:
    try:
        return path.read_text().strip()
    except OSError:
        return None


def _names() -> dict[str, str]:
    """Nazwy urządzeń z lspci, jeśli jest (tylko do wyświetlenia)."""
    if not shutil.which("lspci"):
        return {}
    try:
        out = subprocess.run(["lspci", "-D", "-mm"], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return {}
    names: dict[str, str] = {}
    for line in out.splitlines():
        parts = re.findall(r'"([^"]*)"', line)
        address = line.split(" ", 1)[0]
        if len(parts) >= 3:
            names[address] = f"{parts[1]} {parts[2]}".strip()
    return names


def list_devices(root: Path = Path("/sys/bus/pci/devices")) -> list[dict]:
    if not root.is_dir():
        return []
    names = _names()
    devices = []
    for dev in sorted(root.iterdir()):
        address = dev.name
        if not PCI_ADDRESS.match(address):
            continue
        klass = (_read(dev / "class") or "0x000000")[2:6]
        driver = (dev / "driver").resolve().name if (dev / "driver").exists() else None
        group = (dev / "iommu_group").resolve().name if (dev / "iommu_group").exists() else None
        devices.append({
            "address": address,
            "vendor_id": (_read(dev / "vendor") or "")[2:],
            "device_id": (_read(dev / "device") or "")[2:],
            "class": klass,
            "type": CLASSES.get(klass, "other"),
            "driver": driver,
            "iommu_group": int(group) if group and group.isdigit() else None,
            "name": names.get(address),
        })
    return devices


def iommu_enabled(root: Path = Path("/sys/kernel/iommu_groups")) -> bool:
    return root.is_dir() and any(root.iterdir())


def hostdev_element(address: str, custom_xml: str | None = None) -> ET.Element:
    """<hostdev> dla adresu PCI albo własny XML administratora (sprawdzony)."""
    if custom_xml:
        try:
            element = ET.fromstring(custom_xml)
        except ET.ParseError as exc:
            raise PciError(f"Nieprawidłowy XML hostdev: {exc}") from exc
        if element.tag != "hostdev":
            raise PciError("Własny XML musi być pojedynczym elementem <hostdev>.")
        return element
    match = PCI_ADDRESS.match(address)
    if not match:
        raise PciError(f"Nieprawidłowy adres PCI: {address!r}")
    domain, bus, slot, function = match.groups()
    element = ET.Element("hostdev", {"mode": "subsystem", "type": "pci", "managed": "yes"})
    source = ET.SubElement(element, "source")
    ET.SubElement(source, "address", {"domain": f"0x{domain}", "bus": f"0x{bus}", "slot": f"0x{slot}", "function": f"0x{function}"})
    return element


def with_hostdevs(xml: str, devices: list[tuple[str, str | None]]) -> str:
    """Definicja domeny z dokładnie tymi urządzeniami PCI (pozostałe hostdev PCI usunięte)."""
    root = ET.fromstring(xml)
    devs = root.find("devices")
    for existing in list(devs.findall("hostdev")):
        if existing.get("type") == "pci":
            devs.remove(existing)
    for address, custom in devices:
        devs.append(hostdev_element(address, custom))
    return ET.tostring(root, encoding="unicode")


def hostdev_addresses(xml: str) -> list[str]:
    root = ET.fromstring(xml)
    out = []
    for hd in root.find("devices").findall("hostdev"):
        addr = hd.find("source/address")
        if hd.get("type") == "pci" and addr is not None:
            d, b, s, f = (addr.get(k, "0x0")[2:] for k in ("domain", "bus", "slot", "function"))
            out.append(f"{int(d, 16):04x}:{int(b, 16):02x}:{int(s, 16):02x}.{int(f, 16)}")
    return out
