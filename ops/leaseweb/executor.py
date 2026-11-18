#!/usr/bin/python3 -I
"""Dispatch one administrator-approved staging operation from a protected queue."""
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

BASE = Path("/etc/imagine-ops")
STATE = Path("/var/lib/imagine-ops")
HANDLERS = Path("/usr/local/libexec/imagine-ops")
OPERATIONS = {
    "status", "backup-run", "backup-verify", "restore-test",
    "deploy-staging", "install-n8n-staging",
}
TOKEN = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}")
DIGEST = re.compile(r"sha256:[a-f0-9]{64}")


def protected(path, owner, directory=False):
    """Verify the full path, including ancestors, without accepting symlinks."""
    for item in reversed((path, *path.parents)):
        info = item.lstat()
        expected = stat.S_ISDIR if directory or item != path else stat.S_ISREG
        if info.st_uid != owner or info.st_mode & 0o022 or not expected(info.st_mode):
            raise ValueError("untrusted_path")
    return path


def load(path, owner):
    with protected(path, owner).open("rb") as stream:
        data = stream.read(65537)
    if len(data) > 65536:
        raise ValueError("oversized_record")
    return json.loads(data)


def approved(record, policy, request, now):
    if set(record) != {"operation", "target", "version", "task", "initiator",
                       "approver", "expires", "environment", "request"}:
        raise ValueError("invalid_schema")
    for field in ("target", "task", "initiator", "approver", "request"):
        if not isinstance(record[field], str) or not TOKEN.fullmatch(record[field]):
            raise ValueError("invalid_identifier")
    operation, target, version = (record[k] for k in ("operation", "target", "version"))
    if record["request"] != request or record["environment"] != "staging":
        raise ValueError("scope_mismatch")
    if type(record["expires"]) is not int or not now < record["expires"] <= now + 86400:
        raise ValueError("invalid_expiry")
    if operation not in OPERATIONS or record["approver"] not in policy["approvers"]:
        raise ValueError("operation_or_approver_denied")
    if not isinstance(version, str) or not (TOKEN.fullmatch(version) or DIGEST.fullmatch(version)):
        raise ValueError("invalid_version")
    if operation in {"deploy-staging", "install-n8n-staging"} and not DIGEST.fullmatch(version):
        raise ValueError("digest_required")
    if version not in policy["targets"].get(target, {}).get(operation, []):
        raise ValueError("target_or_version_denied")
    return operation, target, version


def audit(event, **fields):
    print(json.dumps({"event": event, "timestamp": int(time.time()), **fields}), flush=True)


def dispatch(request, base=BASE, state=STATE, handlers=HANDLERS, owner=0,
             execute=subprocess.run):
    if not TOKEN.fullmatch(request):
        raise ValueError("invalid_request")
    record = load(base / "approved" / (request + ".json"), owner)
    policy = load(base / "policy.json", owner)
    operation, target, version = approved(record, policy, request, int(time.time()))
    handler = protected(handlers / operation, owner)
    protected(state, owner, directory=True)
    fields = {key: record[key] for key in
              ("request", "task", "initiator", "approver", "operation", "target", "version")}
    lock_fd = os.open(state / "executor.lock", os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o600)
    with os.fdopen(lock_fd, "r+b") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        approved(record, policy, request, int(time.time()))
        if operation != "status":
            marker = state / (request + ".used")
            fd = os.open(marker, os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_WRONLY, 0o600)
            os.close(fd)
            directory = os.open(state, os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        audit("started", **fields)
        outcome = "failed"
        try:
            result = execute(
                [str(handler), target, version], cwd="/",
                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"},
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, timeout=900, check=False,
            )
            outcome = "succeeded" if result.returncode == 0 else "handler_failed"
        finally:
            audit("finished", outcome=outcome, **fields)
        return outcome == "succeeded"


def main():
    if len(sys.argv) != 2 or os.geteuid() != 0:
        audit("denied", reason="usage_or_identity")
        return 2
    try:
        return 0 if dispatch(sys.argv[1]) else 1
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        audit("denied", reason="policy_state_or_execution_error")
        return 2


if __name__ == "__main__":
    sys.exit(main())
