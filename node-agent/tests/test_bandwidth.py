"""Limit prędkości karty: dławienie po transferze i prędkość portu z pakietu."""

from __future__ import annotations

import sys
import types

from agent.schemas import NetworkConfigRequest
from test_provisioning import create_vm, wait_for_job


def test_mock_zapamietuje_limit(client, template):
    vm = create_vm(client, template, server_id=2201, bandwidth_mbps=1000)["result"]
    body = {"interfaces": [{"address": "203.0.113.22", "prefix": 24, "gateway": "203.0.113.1"}]}
    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/network", json={**body, "bandwidth_mbps": 10}).json()["job_id"])
    assert job["status"] == "done" and job["result"]["bandwidth_mbps"] == 10
    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/network", json=body).json()["job_id"])
    assert job["result"]["bandwidth_mbps"] == 10, "Brak pola = limit bez zmian"
    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/network", json={**body, "bandwidth_mbps": None}).json()["job_id"])
    assert job["result"]["bandwidth_mbps"] is None


def test_libvirt_ustawia_i_zdejmuje_limit(settings, monkeypatch):
    calls = []
    fake = types.SimpleNamespace(VIR_DOMAIN_AFFECT_CONFIG=2, VIR_DOMAIN_AFFECT_LIVE=1, libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    from agent.driver import LibvirtDriver

    class Domain:
        def name(self):
            return "virthub-31"

        def isActive(self):
            return True

        def setInterfaceParameters(self, iface, params, flags):
            calls.append((iface, params, flags))

    drv = LibvirtDriver(settings)
    monkeypatch.setattr(drv, "_domain", lambda uuid: Domain())
    monkeypatch.setattr(drv.network, "configure", lambda *a, **k: None)
    iface = [{"address": "203.0.113.31", "prefix": 24}]

    drv.configure_network("u", NetworkConfigRequest(interfaces=iface))
    assert calls == [], "Starszy panel bez pola — limit bez zmian"
    drv.configure_network("u", NetworkConfigRequest(interfaces=iface, bandwidth_mbps=10))
    assert calls[-1][0] == "vh31" and calls[-1][2] == 3
    assert calls[-1][1]["inbound.average"] == 1250 and calls[-1][1]["outbound.average"] == 1250
    drv.configure_network("u", NetworkConfigRequest(interfaces=iface, bandwidth_mbps=None))
    assert calls[-1][1]["inbound.average"] == 0 and calls[-1][1]["outbound.average"] == 0


def test_incus_ustawia_limity_karty(settings, monkeypatch):
    from agent.lxc_driver import IncusDriver

    ran = []
    drv = IncusDriver.__new__(IncusDriver)
    drv.settings = settings
    drv.network = types.SimpleNamespace(configure=lambda *a, **k: None)
    monkeypatch.setattr(drv, "_name_for", lambda uuid: "virthub-32", raising=False)
    monkeypatch.setattr(drv, "_incus", lambda *a, **k: ran.append(a), raising=False)
    iface = [{"address": "203.0.113.32", "prefix": 24}]
    drv.configure_network("u", NetworkConfigRequest(interfaces=iface, bandwidth_mbps=50))
    assert ran[-1] == ("config", "device", "set", "virthub-32", "eth0", "limits.ingress=50Mbit", "limits.egress=50Mbit")
    drv.configure_network("u", NetworkConfigRequest(interfaces=iface, bandwidth_mbps=None))
    assert ran[-1] == ("config", "device", "unset", "virthub-32", "eth0", "limits.egress")
