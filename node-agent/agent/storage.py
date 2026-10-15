"""Warstwa storage — celowo ukryta za interfejsem.

Control plane nigdy nie wie, czy dysk VPS-a leży w lokalnym qcow2, na ZFS-ie czy
w Cephie. Dzięki temu przejście na storage współdzielony (warunek migracji live
między hypervisorami, Faza 5) nie wymaga zmian po stronie panelu — wystarczy
nowa implementacja StorageDriver.
"""

from __future__ import annotations

import json
import logging
import shutil
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any
from xml.sax.saxutils import quoteattr

from .config import Settings
from .shell import run

log = logging.getLogger("virthub.storage")


class StorageError(RuntimeError):
    pass


class StorageDriver(ABC):
    """Kontrakt, który musi spełnić każdy backend dyskowy."""

    @abstractmethod
    def create_volume(self, name: str, template: str, disk_gb: int) -> Path: ...

    @abstractmethod
    def resize_volume(self, name: str, disk_gb: int) -> None: ...

    @abstractmethod
    def delete_volume(self, name: str) -> None: ...

    @abstractmethod
    def snapshot_volume(self, name: str, snapshot: str) -> Path: ...

    @abstractmethod
    def restore_volume(self, name: str, snapshot: str) -> None: ...

    @abstractmethod
    def volume_path(self, name: str) -> Path: ...

    @abstractmethod
    def capacity(self) -> tuple[int, int]:
        """Zwraca (total_gb, free_gb) dla przestrzeni na dyski VPS-ów."""

    # Dysk jako plik na węźle — kopie zapasowe, Elevate i przenosiny między
    # węzłami operują na pliku. Magazyny sieciowe (Ceph) tego nie mają.
    file_based = True

    def disk_xml(self, name: str, dev: str = "vda") -> str:
        """Element <disk> (domyślnie główny vda) do XML domeny libvirt."""
        return (
            "    <disk type='file' device='disk'>\n"
            "      <driver name='qemu' type='qcow2' cache='none' io='native' discard='unmap'/>\n"
            f"      <source file={quoteattr(str(self.volume_path(name)))}/>\n"
            f"      <target dev={quoteattr(dev)} bus='virtio'/>\n"
            "    </disk>"
        )

    def create_blank(self, name: str, disk_gb: int) -> Path:
        """Pusty dysk (dodatkowy dysk maszyny)."""
        raise StorageError("Ten magazyn nie obsługuje dodatkowych dysków.")

    def size_gb(self, name: str) -> int:
        """Rozmiar wirtualny dysku w GB."""
        info = json.loads(run(["qemu-img", "info", "--output=json", str(self.volume_path(name))]))
        return int(info["virtual-size"]) // (1024**3)

    def libvirt_prepare(self, conn: Any) -> None:
        """Przygotowanie libvirt przed zdefiniowaniem domeny (np. sekret Ceph)."""

    def report(self) -> dict[str, Any]:
        total, free = self.capacity()
        return {"total_gb": total, "free_gb": free, "used_gb": max(0, total - free)}

    # --- Elevate: nakładka na dysk (zmiany próbne do zatwierdzenia albo odrzucenia) ---

    def overlay_path(self, name: str) -> Path:
        return self.volume_path(name).with_name(f"{name}.elevate.qcow2")

    def has_overlay(self, name: str) -> bool:
        return self.overlay_path(name).exists()

    def create_overlay(self, name: str) -> Path:
        """Nakładka dla zatrzymanej maszyny (działającą obsługuje libvirt)."""
        raise StorageError("Ten magazyn nie obsługuje nakładek Elevate.")

    def commit_overlay(self, name: str) -> None:
        """Zapis zmian z nakładki do dysku (maszyna zatrzymana)."""
        raise StorageError("Ten magazyn nie obsługuje nakładek Elevate.")

    def drop_overlay(self, name: str) -> None:
        self.overlay_path(name).unlink(missing_ok=True)


class Qcow2LocalDriver(StorageDriver):
    """Lokalne dyski qcow2 z backing file na obrazie szablonu.

    Nowy VPS nie kopiuje całego obrazu bazowego — dostaje cienką warstwę
    copy-on-write nad wspólnym szablonem. Tworzenie dysku jest przez to
    natychmiastowe, a 20 VPS-ów z tej samej Ubuntu zajmuje miejsce jednego
    obrazu plus faktycznie zapisane dane.
    """

    def __init__(self, settings: Settings, root: Path | None = None, thin: bool = True):
        self.settings = settings
        # Własny katalog magazynu (datastore) albo domyślny katalog węzła.
        self.root = root or settings.image_dir
        self.thin = thin

    def volume_path(self, name: str) -> Path:
        return self.root / f"{name}.qcow2"

    def _snapshot_path(self, name: str, snapshot: str) -> Path:
        return self.root / f"{name}.snap-{snapshot}.qcow2"

    def _template_path(self, template: str) -> Path:
        # Odcinamy wszelkie separatory ścieżek — nazwa szablonu przychodzi z API.
        safe = Path(template).name
        path = self.settings.template_dir / safe
        if not path.exists():
            raise StorageError(
                f"Nie znaleziono szablonu {safe} w {self.settings.template_dir}. "
                f"Zaimportuj obraz przez panel administratora."
            )
        return path

    def create_volume(self, name: str, template: str, disk_gb: int) -> Path:
        target = self.volume_path(name)
        if target.exists():
            raise StorageError(f"Dysk {target} już istnieje — odmawiam nadpisania.")

        backing = self._template_path(template)
        self.root.mkdir(parents=True, exist_ok=True)
        if not self.thin:
            # Gruby dysk: pełna kopia szablonu z zarezerwowanym miejscem — bez
            # łańcucha backing i bez ryzyka, że magazyn zabraknie w trakcie pracy.
            run(["qemu-img", "convert", "-f", "qcow2", "-O", "qcow2", "-o", "preallocation=falloc",
                 str(backing), str(target)], timeout=7200)
            run(["qemu-img", "resize", "--preallocation=falloc", str(target), f"{disk_gb}G"], timeout=7200)
            log.info("Utworzono gruby dysk %s (%sGB z %s)", target, disk_gb, backing.name)
            return target
        run([
            "qemu-img", "create",
            "-f", "qcow2",
            "-F", "qcow2",
            "-b", str(backing),
            str(target),
            f"{disk_gb}G",
        ])
        log.info("Utworzono dysk %s (%sGB, backing=%s)", target, disk_gb, backing.name)
        return target

    def create_blank(self, name: str, disk_gb: int) -> Path:
        target = self.volume_path(name)
        if target.exists():
            raise StorageError(f"Dysk {target} już istnieje — odmawiam nadpisania.")
        self.root.mkdir(parents=True, exist_ok=True)
        run(["qemu-img", "create", "-f", "qcow2", *([] if self.thin else ["-o", "preallocation=falloc"]), str(target), f"{disk_gb}G"],
            timeout=7200)
        log.info("Utworzono pusty dysk %s (%sGB)", target, disk_gb)
        return target

    def resize_volume(self, name: str, disk_gb: int) -> None:
        target = self.volume_path(name)
        current_gb = self._virtual_size_gb(target)
        if disk_gb < current_gb:
            raise StorageError(
                f"Zmniejszenie dysku z {current_gb}GB do {disk_gb}GB nie jest wspierane — "
                f"qcow2 nie da się bezpiecznie skurczyć bez ingerencji w system plików gościa."
            )
        if disk_gb == current_gb:
            return
        run(["qemu-img", "resize", *([] if self.thin else ["--preallocation=falloc"]), str(target), f"{disk_gb}G"])
        log.info("Powiększono dysk %s do %sGB", target, disk_gb)

    def create_overlay(self, name: str) -> Path:
        overlay = self.overlay_path(name)
        if overlay.exists():
            raise StorageError(f"Nakładka Elevate dla {name} już istnieje.")
        base = self.volume_path(name)
        run(["qemu-img", "create", "-f", "qcow2", "-F", "qcow2", "-b", str(base), str(overlay)])
        return overlay

    def commit_overlay(self, name: str) -> None:
        overlay = self.overlay_path(name)
        if not overlay.exists():
            raise StorageError(f"Brak nakładki Elevate dla {name}.")
        run(["qemu-img", "commit", "-q", str(overlay)], timeout=7200)
        overlay.unlink(missing_ok=True)

    def delete_volume(self, name: str) -> None:
        target = self.volume_path(name)
        target.unlink(missing_ok=True)
        self.drop_overlay(name)
        for snap in self.root.glob(f"{name}.snap-*.qcow2"):
            snap.unlink(missing_ok=True)
        seed = self.settings.seed_dir / f"{name}-seed.iso"
        seed.unlink(missing_ok=True)
        log.info("Usunięto dysk i artefakty VPS-a %s", name)

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        source = self.volume_path(name)
        target = self._snapshot_path(name, snapshot)
        if target.exists():
            raise StorageError(f"Snapshot {snapshot} już istnieje dla {name}.")
        # convert zamiast cp: spłaszcza łańcuch backing files, więc snapshot
        # przetrwa usunięcie szablonu bazowego.
        run(["qemu-img", "convert", "-O", "qcow2", str(source), str(target)], timeout=3600)
        log.info("Utworzono snapshot %s", target)
        return target

    def restore_volume(self, name: str, snapshot: str) -> None:
        source = self._snapshot_path(name, snapshot)
        if not source.exists():
            raise StorageError(f"Nie znaleziono snapshotu {snapshot} dla VPS-a {name}.")
        target = self.volume_path(name)
        shutil.copy2(source, target)
        log.info("Przywrócono %s ze snapshotu %s", target, snapshot)

    def capacity(self) -> tuple[int, int]:
        usage = shutil.disk_usage(self.root if self.root.exists() else self.settings.image_dir)
        return usage.total // (1024**3), usage.free // (1024**3)

    def _virtual_size_gb(self, path: Path) -> int:
        raw = run(["qemu-img", "info", "--output=json", str(path)])
        info = json.loads(raw)
        return int(info["virtual-size"]) // (1024**3)


class MockStorageDriver(StorageDriver):
    """Backend na stację developerską bez KVM — tworzy puste pliki zamiast dysków."""

    def __init__(self, settings: Settings, root: Path | None = None, thin: bool = True):
        self.settings = settings
        self.root = root or settings.image_dir
        self.thin = thin

    def volume_path(self, name: str) -> Path:
        return self.root / f"{name}.qcow2"

    def size_gb(self, name: str) -> int:
        import re

        match = re.search(r"size=(\d+)G", self.volume_path(name).read_text(encoding="utf-8"))
        return int(match.group(1)) if match else 0

    def create_volume(self, name: str, template: str, disk_gb: int) -> Path:
        target = self.volume_path(name)
        self.root.mkdir(parents=True, exist_ok=True)
        target.write_text(f"mock volume template={template} size={disk_gb}G\n", encoding="utf-8")
        return target

    def create_blank(self, name: str, disk_gb: int) -> Path:
        target = self.volume_path(name)
        if target.exists():
            raise StorageError(f"Dysk {target} już istnieje — odmawiam nadpisania.")
        self.root.mkdir(parents=True, exist_ok=True)
        target.write_text(f"mock volume blank size={disk_gb}G\n", encoding="utf-8")
        return target

    def resize_volume(self, name: str, disk_gb: int) -> None:
        if self.volume_path(name).exists() and disk_gb < self.size_gb(name):
            raise StorageError(f"Zmniejszenie dysku do {disk_gb}GB nie jest wspierane.")
        self.volume_path(name).write_text(f"mock volume size={disk_gb}G\n", encoding="utf-8")

    def delete_volume(self, name: str) -> None:
        self.volume_path(name).unlink(missing_ok=True)
        self.drop_overlay(name)

    def create_overlay(self, name: str) -> Path:
        overlay = self.overlay_path(name)
        if overlay.exists():
            raise StorageError(f"Nakładka Elevate dla {name} już istnieje.")
        overlay.write_text(f"mock overlay of {name}\n", encoding="utf-8")
        return overlay

    def commit_overlay(self, name: str) -> None:
        overlay = self.overlay_path(name)
        if not overlay.exists():
            raise StorageError(f"Brak nakładki Elevate dla {name}.")
        overlay.unlink()

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        target = self.root / f"{name}.snap-{snapshot}.qcow2"
        target.write_text("mock snapshot\n", encoding="utf-8")
        return target

    def restore_volume(self, name: str, snapshot: str) -> None:
        source = self.root / f"{name}.snap-{snapshot}.qcow2"
        if not source.exists():
            raise StorageError(f"Nie znaleziono snapshotu {snapshot} dla VPS-a {name}.")

    def capacity(self) -> tuple[int, int]:
        usage = shutil.disk_usage(self.settings.image_dir)
        return usage.total // (1024**3), usage.free // (1024**3)


def build_storage_driver(settings: Settings) -> StorageDriver:
    """Router magazynów: dysk każdej maszyny trafia do swojego datastore'u."""
    from .datastores import StorageRouter

    return StorageRouter(settings)


def build_default_driver(settings: Settings) -> StorageDriver:
    return MockStorageDriver(settings) if settings.is_mock else Qcow2LocalDriver(settings)
