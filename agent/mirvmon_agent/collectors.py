from __future__ import annotations

import re
import subprocess
import time
from pathlib import Path
from typing import Any

import psutil

from .redaction import redact_command


class ServiceChangeTracker:
    def __init__(self) -> None:
        self._states: dict[str, dict[str, str]] = {}

    def changed(
        self,
        services: list[dict[str, str]],
    ) -> list[dict[str, str]]:
        changes = []
        for service in services:
            name = service.get("name", "")
            if not name:
                continue
            if self._states.get(name) != service:
                changes.append(service)
            self._states[name] = service.copy()
        return changes


class SystemCollector:
    SKIP_FILESYSTEMS = {
        "cgroup",
        "cgroup2",
        "devpts",
        "devtmpfs",
        "overlay",
        "proc",
        "squashfs",
        "sysfs",
        "tmpfs",
    }

    def __init__(self) -> None:
        self._network: dict[str, tuple[int, int, float]] = {}

    def metrics(self) -> dict[str, float]:
        memory = psutil.virtual_memory()
        metrics: dict[str, float] = {
            "cpu_load": float(psutil.cpu_percent(interval=1)),
            "ram_used": float(memory.percent),
            "ram_total_gb": round(memory.total / (1024**3), 2),
            "uptime": float(max(0, int(time.time() - psutil.boot_time()))),
        }
        self._disk_metrics(metrics)
        self._network_metrics(metrics)
        self._temperatures(metrics)
        return metrics

    def process_snapshot(
        self,
        include_commands: bool,
    ) -> dict[str, list[dict[str, Any]]]:
        processes = []
        for process in psutil.process_iter(
            ["pid", "name", "cmdline", "cpu_percent", "memory_percent"]
        ):
            try:
                info = process.info
                command = " ".join(info.get("cmdline") or [])
                if include_commands:
                    command = redact_command(command)[:512]
                else:
                    command = ""
                processes.append(
                    {
                        "pid": int(info["pid"]),
                        "name": str(info.get("name") or "")[:255],
                        "command": command,
                        "cpu": float(info.get("cpu_percent") or 0),
                        "memory": float(info.get("memory_percent") or 0),
                    }
                )
            except (psutil.AccessDenied, psutil.NoSuchProcess, psutil.ZombieProcess):
                continue

        def top(key: str) -> list[dict[str, Any]]:
            return [
                {
                    "pid": process["pid"],
                    "name": process["name"],
                    "command": process["command"],
                    "value": round(float(process[key]), 2),
                }
                for process in sorted(
                    processes,
                    key=lambda item: float(item[key]),
                    reverse=True,
                )[:20]
            ]

        return {"top_cpu": top("cpu"), "top_memory": top("memory")}

    def services(self) -> list[dict[str, str]]:
        try:
            result = subprocess.run(
                [
                    "systemctl",
                    "list-units",
                    "--type=service",
                    "--all",
                    "--no-legend",
                    "--no-pager",
                ],
                capture_output=True,
                text=True,
                timeout=15,
                check=False,
            )
        except (OSError, subprocess.SubprocessError):
            return []

        services = []
        for line in result.stdout.splitlines():
            fields = line.split(None, 4)
            if len(fields) < 4 or not fields[0].endswith(".service"):
                continue
            active = fields[2]
            status = (
                "running"
                if active == "active"
                else "stopped"
                if active in {"failed", "inactive", "deactivating"}
                else "unknown"
            )
            services.append(
                {
                    "name": fields[0],
                    "status": status,
                    "load_state": fields[1][:50],
                    "active_state": active[:50],
                    "sub_state": fields[3][:50],
                }
            )
        return sorted(services, key=lambda item: item["name"])[:500]

    def _disk_metrics(self, metrics: dict[str, float]) -> None:
        root_recorded = False
        for partition in psutil.disk_partitions(all=False):
            if partition.fstype in self.SKIP_FILESYSTEMS:
                continue
            try:
                usage = psutil.disk_usage(partition.mountpoint)
            except (OSError, PermissionError):
                continue
            suffix = re.sub(r"[^a-z0-9_]", "_", partition.mountpoint.lower()).strip("_")
            suffix = suffix or "root"
            metrics[f"disk_used_{suffix}"[:100]] = float(usage.percent)
            metrics[f"disk_total_gb_{suffix}"[:100]] = round(
                usage.total / (1024**3),
                2,
            )
            if partition.mountpoint == "/":
                metrics["disk_used"] = float(usage.percent)
                root_recorded = True
        if not root_recorded:
            try:
                metrics["disk_used"] = float(psutil.disk_usage("/").percent)
            except (OSError, PermissionError):
                pass

    def _network_metrics(self, metrics: dict[str, float]) -> None:
        now = time.monotonic()
        counters = psutil.net_io_counters(pernic=True)
        stats = psutil.net_if_stats()
        for name, counter in counters.items():
            interface = stats.get(name)
            if interface is None or not interface.isup or name.startswith(("lo", "docker", "veth", "br-")):
                continue
            previous = self._network.get(name)
            self._network[name] = (counter.bytes_recv, counter.bytes_sent, now)
            if previous is None or now <= previous[2]:
                continue
            elapsed = now - previous[2]
            safe_name = re.sub(r"[^a-z0-9_]", "_", name.lower())[:80]
            metrics[f"net_in_{safe_name}"] = max(
                0.0,
                (counter.bytes_recv - previous[0]) / elapsed,
            )
            metrics[f"net_out_{safe_name}"] = max(
                0.0,
                (counter.bytes_sent - previous[1]) / elapsed,
            )

    def _temperatures(self, metrics: dict[str, float]) -> None:
        try:
            temperatures = psutil.sensors_temperatures()
        except (AttributeError, OSError):
            return
        values = [
            float(entry.current)
            for entries in temperatures.values()
            for entry in entries
            if entry.current is not None
        ]
        if values:
            metrics["temp_cpu"] = max(values)
