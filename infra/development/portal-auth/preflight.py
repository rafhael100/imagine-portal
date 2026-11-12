#!/usr/bin/env python3
"""Host-side refusal checks. No file contents or Docker environment output."""
import json
import os
from pathlib import Path
import stat
import subprocess

def inspect(kind, name):
    return json.loads(subprocess.check_output(["docker", kind, "inspect", name], stderr=subprocess.DEVNULL))[0]

def main():
    assert os.geteuid() == 0
    container = os.environ["PORTAL_DB_CONTAINER"]
    network = os.environ["PORTAL_DB_NETWORK"]
    private = Path(os.environ["PORTAL_DEV_PRIVATE_DIR"])
    assert private.is_absolute() and not private.is_symlink()
    metadata = private.lstat()
    assert stat.S_ISDIR(metadata.st_mode) and metadata.st_uid == 0 and stat.S_IMODE(metadata.st_mode) == 0o700
    for name in ("runtime.json", "app-key"):
        value = (private / name).lstat()
        assert stat.S_ISREG(value.st_mode) and value.st_uid == 82
        assert stat.S_IMODE(value.st_mode) == 0o600 and value.st_nlink == 1
    n = inspect("network", network)
    assert n["Internal"] is True and n["Driver"] == "bridge"
    c = inspect("container", container)
    assert c["State"]["Running"] is True and c["State"]["Health"]["Status"] == "healthy"
    assert set(c["NetworkSettings"]["Networks"]) == {network}
    assert not any(c["HostConfig"].get("PortBindings", {}).values())
    assert c["HostConfig"].get("NetworkMode") != "host" and not c["HostConfig"]["Privileged"]
    print("PASS private auth preflight: existing healthy database, internal network, no DB ports and restricted credential files")

if __name__ == "__main__":
    try:
        main()
    except Exception:
        raise SystemExit("Private Portal auth preflight refused; inspect configuration without exposing credentials.")
