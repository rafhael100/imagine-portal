#!/usr/bin/env python3
"""Leaseweb Imagine P0 NON-MUTATING preflight.

Reads only OS metadata, fixed localhost port, non-secret file metadata, and
Docker read-only version/status commands. Never reads runtime.json contents,
environment variables, application credentials, databases or containers.

Usage on an AUTHORIZED host:
  python3 leaseweb_readonly_preflight.py
  python3 leaseweb_readonly_preflight.py --json
No sudo/root, installation or GitHub action is required. NO GO-LIVE CLAIM.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import subprocess
import sys
from typing import Any

DEFAULT_ROOT = Path("/srv/imagine/purchase-preview")
CHECK_PORT = 18083

def version_command(command: list[str]) -> dict[str, Any]:
    executable = shutil.which(command[0])
    if not executable:
        return {"available": False, "successful": False}
    try:
        result = subprocess.run(
            [executable, *command[1:]], check=False,
            capture_output=True, text=True,
            timeout=5, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")},
        )
    except (OSError, subprocess.TimeoutExpired):
        return {"available": True, "successful": False}
    # Never return STDOUT/STDERR: Docker CLI/version output may contain
    # custom build paths or environment metadata.
    return {"available": True, "successful": result.returncode == 0}

def local_listener(host: str = "127.0.0.1", port: int = CHECK_PORT) -> bool:
    if host != "127.0.0.1" or port != CHECK_PORT:
        raise ValueError("Only the fixed synthetic Portal localhost port may be probed.")
    try:
        with socket.create_connection((host, port), timeout=1):
            return True
    except OSError:
        return False

def file_metadata(path: Path) -> dict[str, Any]:
    # lstat, not open(): never follows symlinks to read hidden contents.
    try:
        s = path.lstat()
    except (OSError, ValueError):
        return {"exists": False, "regular": False, "symlink": False,
                "private_mode": False}
    mode = stat.S_IMODE(s.st_mode)
    return {
        "exists": True,
        "regular": stat.S_ISREG(s.st_mode),
        "symlink": stat.S_ISLNK(s.st_mode),
        "private_mode": stat.S_ISREG(s.st_mode) and (mode & 0o077) == 0,
    }

def memory_total_bytes() -> int | None:
    try:
        with open("/proc/meminfo", encoding="ascii") as stream:
            for row in stream:
                if row.startswith("MemTotal:"):
                    parts = row.split()
                    return int(parts[1]) * 1024
    except (OSError, ValueError, IndexError):
        pass
    return None

def overview(root: Path = DEFAULT_ROOT, *, probe: bool = True) -> dict[str, Any]:
    if not root.is_absolute():
        raise ValueError("Preflight requires an absolute staging location.")
    # All examined file names are fixed; no user-controlled traversal.
    app_dir = root.is_dir() and not root.is_symlink()
    private_dir = root / ".private"
    private_exists = private_dir.is_dir() and not private_dir.is_symlink()
    runtime = file_metadata(private_dir / "runtime.json")
    admin_pwd = file_metadata(private_dir / "admin-password")
    try:
        disk = shutil.disk_usage(str(root if root.exists() else Path("/")))
        disk_free_bytes = disk.free
    except OSError:
        disk_free_bytes = None
    docker_cli = version_command(["docker", "--version"])
    compose_cli = version_command(["docker", "compose", "version"])
    docker_daemon = version_command(["docker", "info", "--format", "{{.ServerVersion}}"])
    local_port = local_listener() if probe else False
    high_risk = [
        name for name, meta in
        [("runtime_permissions", runtime), ("database_password_permissions", admin_pwd)]
        if meta["exists"] and (not meta["private_mode"] or meta["symlink"])
    ]
    has_runtime = runtime["exists"] and runtime["regular"] and runtime["private_mode"]
    basic_host_readiness = (
        app_dir and private_exists and has_runtime and
        docker_cli["successful"] and compose_cli["successful"] and
        docker_daemon["successful"] and isinstance(disk_free_bytes, int) and
        disk_free_bytes >= 2 * 1024 ** 3 and not high_risk
    )
    return {
        "schema": "imagine.portal.leaseweb-readonly-preflight.v1",
        "safety": {
            "read_only": True, "secrets_read": False, "credentials_output": False,
            "docker_writes_executed": False, "sql_queries_executed": False,
            "public_network_access": False, "deployment_executed": False,
        },
        "checks": {
            "staging_folder_exists": bool(app_dir),
            "private_folder_exists": bool(private_exists),
            "runtime_file_metadata": runtime,
            "db_password_file_metadata": admin_pwd,
            "docker_cli": docker_cli,
            "compose_cli": compose_cli,
            "docker_daemon": docker_daemon,
            "local_preview_port_18083_open": bool(local_port),
            "disk_free_gib_rounded_down":
                disk_free_bytes // (1024 ** 3) if disk_free_bytes is not None else None,
            "ram_total_gib_rounded_down":
                memory_total_bytes() // (1024 ** 3)
                if memory_total_bytes() is not None else None,
            "private_file_permission_issues": high_risk,
        },
        "preflight_basic_host_ready": basic_host_readiness,
        "release_decision": {
            "status": "NO_GO",
            "merge_allowed": False,
            "deploy_allowed": False,
            "real_customers_allowed": False,
            "payments_allowed": False,
            "sites_publishing_allowed": False,
            "reason": "This script cannot validate IAM, PSP, contract, backup/restore, S2S authority, DNS or independent AppSec review.",
        },
    }

def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Non-mutating Leaseweb staging audit, never go-live.")
    parser.add_argument("--json", action="store_true", help="Output machine-readable report")
    args = parser.parse_args(argv)
    report = overview()
    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        checks = report["checks"]
        print("Imagine P0 / Leaseweb — diagnóstico SOMENTE LEITURA")
        print("Docker/Compose/daemon: ",
              checks["docker_cli"]["successful"],
              checks["compose_cli"]["successful"],
              checks["docker_daemon"]["successful"])
        print("Pasta privada / runtime restrito: ",
              checks["private_folder_exists"],
              checks["runtime_file_metadata"]["private_mode"])
        print("Porta da prévia 18083 acessível localmente: ",
              checks["local_preview_port_18083_open"])
        print("Espaço livre (GiB, arredondado): ", checks["disk_free_gib_rounded_down"])
        print("Checagem de permissões de arquivos: ", checks["private_file_permission_issues"])
        print("GATE: NO_GO — não ativar contratos, pagamentos, login real ou publicação.")
    return 0

if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
