"""Konfiguracja agenta — wyłącznie ze zmiennych środowiskowych.

Agent działa jako usługa systemd na hypervisorze, więc konfiguracja idzie przez
EnvironmentFile, nie przez plik w repozytorium. Żadnych sekretów w kodzie.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path


class ConfigError(RuntimeError):
    """Agent nie ma kompletu konfiguracji potrzebnej do startu."""


def _env(key: str, default: str | None = None, *, required: bool = False) -> str:
    value = os.environ.get(key, default)
    if required and not value:
        raise ConfigError(
            f"Brak wymaganej zmiennej środowiskowej {key}. "
            f"Uzupełnij ją w /etc/virthub-agent/agent.env i zrestartuj usługę."
        )
    return value or ""


def _env_int(key: str, default: int) -> int:
    raw = os.environ.get(key)
    if not raw:
        return default
    try:
        return int(raw)
    except ValueError as exc:
        raise ConfigError(f"Zmienna {key} musi być liczbą całkowitą, jest: {raw!r}") from exc


def _env_path(key: str, default: str) -> Path:
    return Path(os.environ.get(key) or default)


@dataclass(frozen=True)
class Settings:
    # Uwierzytelnianie żądań z control plane (HMAC-SHA256, sekret współdzielony).
    agent_token: str

    # "libvirt" — maszyny wirtualne KVM (wymaga VT-x/AMD-V),
    # "lxc"     — kontenery przez Incus (działa bez wsparcia sprzętowego),
    # "mock"    — stacja developerska, żadnych prawdziwych maszyn.
    driver: str
    libvirt_uri: str

    # Kontenery: pula dyskowa Incusa i serwer obrazów, z którego węzeł
    # sam pobiera szablony przy pierwszym użyciu.
    incus_storage_pool: str
    incus_image_remote: str

    # Układ katalogów na hoście.
    image_dir: Path      # dyski działających VPS-ów (qcow2)
    template_dir: Path   # bazowe obrazy szablonów OS (backing files)
    seed_dir: Path       # wygenerowane ISO cloud-init
    state_db: Path       # lokalna kolejka zadań (SQLite)

    # Sieć.
    bridge: str
    nft_table: str

    # Konsola.
    vnc_listen: str

    # Powrotny kanał do control plane (raport wyniku zadania).
    control_plane_url: str
    callback_secret: str

    # Bezpieczeństwo protokołu.
    max_clock_skew: int  # sekundy — okno na replay protection

    @property
    def is_mock(self) -> bool:
        return self.driver == "mock"

    @property
    def virtualization(self) -> str:
        """Rodzaj maszyn, jakie daje ten węzeł — tak widzi go panel."""
        return {"libvirt": "kvm", "lxc": "lxc"}.get(self.driver, "mock")

    def ensure_directories(self) -> None:
        for path in (self.image_dir, self.template_dir, self.seed_dir, self.state_db.parent):
            path.mkdir(parents=True, exist_ok=True)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    driver = _env("VH_AGENT_DRIVER", "libvirt").lower()
    if driver not in {"libvirt", "lxc", "mock"}:
        raise ConfigError(
            f"VH_AGENT_DRIVER musi być 'libvirt', 'lxc' albo 'mock', jest: {driver!r}"
        )

    settings = Settings(
        agent_token=_env("VH_AGENT_TOKEN", required=True),
        driver=driver,
        libvirt_uri=_env("VH_LIBVIRT_URI", "qemu:///system"),
        incus_storage_pool=_env("VH_INCUS_POOL", "default"),
        incus_image_remote=_env("VH_INCUS_REMOTE", "images"),
        image_dir=_env_path("VH_IMAGE_DIR", "/var/lib/virthub/images"),
        template_dir=_env_path("VH_TEMPLATE_DIR", "/var/lib/virthub/templates"),
        seed_dir=_env_path("VH_SEED_DIR", "/var/lib/virthub/seeds"),
        state_db=_env_path("VH_STATE_DB", "/var/lib/virthub/agent-state.sqlite3"),
        bridge=_env("VH_BRIDGE", "br0"),
        nft_table=_env("VH_NFT_TABLE", "virthub"),
        vnc_listen=_env("VH_VNC_LISTEN", "127.0.0.1"),
        control_plane_url=_env("VH_CONTROL_PLANE_URL", "").rstrip("/"),
        callback_secret=_env("VH_CALLBACK_SECRET", ""),
        max_clock_skew=_env_int("VH_MAX_CLOCK_SKEW", 300),
    )
    return settings
