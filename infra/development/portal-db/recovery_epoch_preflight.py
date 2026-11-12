#!/usr/bin/env python3
"""F355: offline synthetic anti-rollback comparison, NEVER a serving authorization.

No PostgreSQL/network access. Both inputs are untrusted local CI files; a
matching pair cannot prove witness independence, durability, or authenticity.
"""
from __future__ import annotations

import argparse
import json
import os
import stat
import sys
from pathlib import Path

LIMIT = 4096
SNAPSHOT_FIELDS = frozenset({
    "organization", "instance", "generation", "session_revision",
    "membership_revision", "witnessed_sequence",
})
WITNESS_FIELDS = frozenset({
    "organization", "instance", "generation_floor", "session_floor",
    "membership_floor", "sequence",
})


class RecoveryBlocked(Exception):
    """An unverified, potentially stale or compromised snapshot must remain offline."""


def read_private_json(path: Path) -> dict:
    # O_NOFOLLOW + a single file descriptor prevent following a symlink swap.
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    try:
        fd = os.open(path, flags)
        with os.fdopen(fd, "rb") as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode):
                raise RecoveryBlocked("not_regular")
            if stat.S_IMODE(info.st_mode) != 0o600:
                raise RecoveryBlocked("not_private")
            if not 1 <= info.st_size <= LIMIT:
                raise RecoveryBlocked("size")
            raw = handle.read(LIMIT + 1)
            if len(raw) > LIMIT or len(raw) != info.st_size:
                raise RecoveryBlocked("size")
    except (OSError, UnicodeError, ValueError) as exc:
        raise RecoveryBlocked("unavailable") from exc
    # Different JSON parsers can disagree about repeated keys. A witness
    # document with ambiguous revocation evidence must never be accepted.
    def distinct_pairs(pairs: list[tuple[str, object]]) -> dict:
        result = {}
        for key, value in pairs:
            if key in result:
                raise RecoveryBlocked("duplicate_json_key")
            result[key] = value
        return result

    try:
        data = json.loads(
            raw.decode("utf-8"),
            object_pairs_hook=distinct_pairs,
            parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)),
        )
    except (UnicodeError, ValueError, json.JSONDecodeError) as exc:
        raise RecoveryBlocked("invalid_json") from exc
    if not isinstance(data, dict):
        raise RecoveryBlocked("not_object")
    return data


def validate(document: dict, keys: frozenset[str]) -> None:
    if document.keys() != keys:
        raise RecoveryBlocked("schema")
    for label in ("organization", "instance"):
        value = document[label]
        if not isinstance(value, str) or not (1 <= len(value) <= 64):
            raise RecoveryBlocked("identity")
        if not all(char.isascii() and (char.isalnum() or char in "-_") for char in value):
            raise RecoveryBlocked("identity")
    for key in keys - {"organization", "instance"}:
        number = document[key]
        if type(number) is not int or not (1 <= number <= 1_000_000_000):
            raise RecoveryBlocked("revision")


def evaluate(snapshot: dict, witness: dict) -> str:
    """Classify ONLY; no return value ever means authorize users/HTTP."""
    validate(snapshot, SNAPSHOT_FIELDS)
    validate(witness, WITNESS_FIELDS)
    if (snapshot["organization"] != witness["organization"]
            or snapshot["instance"] != witness["instance"]):
        raise RecoveryBlocked("different_scope")
    # No fallback to the restored database for a missing or lagging witness.
    # Both earlier and later values are blocked, since consistency is unknown.
    if (snapshot["generation"] != witness["generation_floor"]
            or snapshot["session_revision"] != witness["session_floor"]
            or snapshot["membership_revision"] != witness["membership_floor"]
            or snapshot["witnessed_sequence"] != witness["sequence"]):
        raise RecoveryBlocked("unreconciled_epoch")
    return "LAB_CONSISTENT_STILL_NO_SERVING"


def inspect(snapshot_file: Path, witness_file: Path) -> str:
    try:
        source = snapshot_file.resolve(strict=True)
        authority = witness_file.resolve(strict=True)
        if source == authority:
            raise RecoveryBlocked("same_file")
        s1, s2 = os.stat(source), os.stat(authority)
        if (s1.st_dev, s1.st_ino) == (s2.st_dev, s2.st_ino):
            raise RecoveryBlocked("same_inode")
        # lstat before read rejects named links; open additionally uses O_NOFOLLOW.
        if snapshot_file.is_symlink() or witness_file.is_symlink():
            raise RecoveryBlocked("symlink")
    except OSError as exc:
        raise RecoveryBlocked("unavailable") from exc
    return evaluate(read_private_json(snapshot_file), read_private_json(witness_file))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--witness", required=True, type=Path)
    arguments = parser.parse_args(argv)
    # This command is intentionally CI-only; it cannot activate restored roles.
    if (os.getenv("GITHUB_ACTIONS") != "true"
            or os.getenv("PORTAL_DB_DISPOSABLE_CI") != "1"
            or os.getenv("RUNNER_ENVIRONMENT") != "github-hosted"):
        print(json.dumps({"status": "BLOCKED", "can_authorize_http": False}))
        return 64
    try:
        result = inspect(arguments.snapshot, arguments.witness)
    except RecoveryBlocked:
        print(json.dumps({"status": "BLOCKED", "can_authorize_http": False}))
        return 65
    print(json.dumps({"status": result, "can_authorize_http": False}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
