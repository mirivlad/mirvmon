import re
import subprocess
import time

import psutil

from .redaction import redact_command


class ServiceChangeTracker:
    def __init__(self):
        self._states = {}

    def changed(
        self, services
    ):
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

    def __init__(self):
        self._network = {}

    def metrics(self):
        memory = psutil.virtual_memory()
        metrics = {
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
    ):
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

        def top(key):
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

    def services(self):
        if self._systemd_is_pid_one():
            return self._systemd_services()
        return self._sysv_services()

    @staticmethod
    def _command(arguments):
        try:
            result = subprocess.run(
                arguments,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True,
                timeout=15,
                check=False,
            )
        except (OSError, subprocess.SubprocessError):
            return ""
        return result.stdout

    @staticmethod
    def _systemd_is_pid_one():
        try:
            with open("/proc/1/comm", "r") as stream:
                return stream.read().strip() == "systemd"
        except OSError:
            return False

    def _systemd_services(self):
        output = self._command([
            "systemctl", "list-units", "--type=service", "--all", "--no-legend", "--no-pager",
        ])

        services = []
        for line in output.splitlines():
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

    def _sysv_services(self):
        services = []
        output = self._command(["service", "--status-all"])
        for line in output.splitlines():
            match = re.match(r"^\s*\[\s*([+\-?])\s*\]\s+(.+?)\s*$", line)
            if not match:
                continue
            state = match.group(1)
            services.append({
                "name": match.group(2)[:255],
                "status": "running" if state == "+" else "stopped" if state == "-" else "unknown",
                "load_state": "sysv",
                "active_state": "active" if state == "+" else "inactive" if state == "-" else "unknown",
                "sub_state": "unknown",
            })
        if services:
            return sorted(services, key=lambda item: item["name"])[:500]

        output = self._command(["chkconfig", "--list"])
        for line in output.splitlines():
            fields = line.split()
            if not fields:
                continue
            running = any(field.endswith(":on") for field in fields[1:])
            services.append({
                "name": fields[0][:255],
                "status": "running" if running else "stopped",
                "load_state": "sysv",
                "active_state": "active" if running else "inactive",
                "sub_state": "unknown",
            })
        return sorted(services, key=lambda item: item["name"])[:500]

    def _disk_metrics(self, metrics):
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

    def _network_metrics(self, metrics):
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

    def _temperatures(self, metrics):
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
