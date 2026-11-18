#!/usr/bin/python3 -I
"""Transfer and recover an administrator-sealed staging bundle through Restic."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile

# Python -I excludes the script directory from sys.path.
if __name__ == "__main__":
    sys.path.insert(0, str(Path(__file__).resolve().parent))
from executor import BASE, load, protected

RESTIC = "/usr/bin/restic"
PROJECT = re.compile(r"[a-z0-9][a-z0-9_-]{0,62}")
SNAPSHOT = re.compile(r"[a-f0-9]{64}")
R2 = re.compile(
    r"s3:https://[a-f0-9]{32}\.eu\.r2\.cloudflarestorage\.com/"
    r"[a-z0-9][a-z0-9-]{1,61}/[a-z0-9][a-z0-9_-]{0,62}"
)
FILES = ("database.dump", "n8n-state.tar")
MANIFEST = "manifest.json"
LIMIT = 4 * 1024**3
OPERATIONS = {"backup-run", "backup-verify", "restore-test"}


def bundle_manifest(directory, owner, complete=True):
    if complete and {entry.name for entry in directory.iterdir()} != {*FILES, MANIFEST}:
        raise ValueError("unexpected_bundle_contents")
    result = load(directory / MANIFEST, owner)
    if set(result) != {"schema", "files"} or result["schema"] != "imagine-n8n-bundle-v1":
        raise ValueError("invalid_manifest")
    if set(result["files"]) != set(FILES):
        raise ValueError("invalid_manifest_files")
    for item in result["files"].values():
        if set(item) != {"bytes", "sha256"} or type(item["bytes"]) is not int or not (
            0 < item["bytes"] <= LIMIT and SNAPSHOT.fullmatch(item["sha256"])
        ):
            raise ValueError("invalid_manifest_entry")
    return result["files"]


def check_payload(path, name, expected, owner):
    protected(path, owner)
    if path.stat().st_size != expected["bytes"]:
        raise ValueError("payload_size_mismatch")
    with path.open("rb") as stream:
        if name == "database.dump" and stream.read(5) != b"PGDMP":
            raise ValueError("expected_postgresql_custom_dump")
        stream.seek(0)
        if hashlib.file_digest(stream, "sha256").hexdigest() != expected["sha256"]:
            raise ValueError("payload_checksum_mismatch")
    if name == "n8n-state.tar":
        with tarfile.open(path, mode="r:") as archive:
            names = set()
            for item in archive:
                parts = item.name.split("/")
                if (len(names) >= 100000 or item.name in names
                        or item.issparse() or not (item.isdir() or item.isfile())
                        or any(part in {"", ".", ".."} for part in parts)):
                    raise ValueError("unsafe_state_archive")
                names.add(item.name)
            if not names:
                raise ValueError("empty_state_archive")


class Restic:
    def __init__(self, config, owner):
        self.owner = owner
        self.env = {"PATH": "/usr/bin:/bin", "LANG": "C"}
        if set(config) != {"repository", "password_file", "credentials_file"}:
            raise ValueError("invalid_backup_policy")
        repository = config["repository"]
        if not isinstance(repository, str) or not R2.fullmatch(repository):
            raise ValueError("expected_approved_r2_eu_repository")
        credentials = load(Path(config["credentials_file"]), owner)
        if set(credentials) != {"AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"} or any(
            not isinstance(value, str) or not re.fullmatch(r"[a-zA-Z0-9+/=_-]{1,256}", value)
            for value in credentials.values()
        ):
            raise ValueError("invalid_credential_binding")
        self.env.update(credentials)
        self.args = [RESTIC, "--no-cache", "--repo", repository, "--password-file",
                     str(protected(Path(config["password_file"]), owner))]

    def metadata(self, *args):
        result = subprocess.run(
            [*self.args, *args], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, env=self.env, check=True, timeout=120,
        )
        if len(result.stdout) > 2_000_000:
            raise ValueError("oversized_restic_metadata")
        return result.stdout

    def download(self, snapshot, path, destination, limit):
        with destination.open("xb") as stream:
            subprocess.run(
                ["/usr/bin/prlimit", f"--fsize={limit}:{limit}", "--",
                 *self.args, "dump", snapshot, str(path)], stdin=subprocess.DEVNULL,
                stdout=stream, stderr=subprocess.DEVNULL, env=self.env, check=True,
                timeout=120,
            )


def operate(operation, target, version, source, scratch, backend, owner):
    if operation not in OPERATIONS or not PROJECT.fullmatch(target):
        raise ValueError("operation_or_target_denied")
    tag = "imagine-staging-" + target
    if operation == "backup-run":
        if version != "daily":
            raise ValueError("unknown_backup_profile")
        files = bundle_manifest(source, owner)
        for name in FILES:
            check_payload(source / name, name, files[name], owner)
        records = map(json.loads, backend.metadata(
            "backup", "--json", "--tag", tag, "--", str(source),
        ).splitlines())
        summaries = [r for r in records if r.get("message_type") == "summary"]
        if len(summaries) != 1:
            raise ValueError("expected_one_snapshot")
        # Restic backup summaries can contain the abbreviated snapshot ID.
        version = summaries[0]["snapshot_id"]
        if not re.fullmatch(r"[a-f0-9]{8,64}", version):
            raise ValueError("invalid_snapshot_summary")
    elif not SNAPSHOT.fullmatch(version):
        raise ValueError("full_snapshot_id_required")

    snapshots = json.loads(backend.metadata("snapshots", "--json", version))
    if len(snapshots) != 1:
        raise ValueError("snapshot_not_unique")
    snapshot = snapshots[0]
    if (not SNAPSHOT.fullmatch(snapshot["id"]) or not snapshot["id"].startswith(version)
            or snapshot["paths"] != [str(source)] or tag not in snapshot.get("tags", [])):
        raise ValueError("snapshot_scope_mismatch")
    if operation == "backup-run":
        return snapshot["id"]

    # These operations read via Restic and materialize only the named files in
    # a fresh private directory. The tar is never extracted into a live volume.
    with tempfile.TemporaryDirectory(prefix="recovery-", dir=scratch) as temp:
        directory = Path(temp)
        backend.download(snapshot["id"], source / MANIFEST, directory / MANIFEST, 65536)
        manifest = bundle_manifest(directory, owner, complete=False)
        for name in FILES:
            backend.download(snapshot["id"], source / name, directory / name,
                             manifest[name]["bytes"])
            check_payload(directory / name, name, manifest[name], owner)
        if operation == "restore-test":
            subprocess.run(
                ["/usr/bin/pg_restore", "--list", str(directory / "database.dump")],
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, env={"PATH": "/usr/bin:/bin", "LANG": "C"},
                check=True, timeout=60,
            )
    return snapshot["id"]


def main():
    if len(sys.argv) != 4 or os.geteuid() != 0:
        return 2
    operation, target, version = sys.argv[1:]
    if operation not in OPERATIONS or not PROJECT.fullmatch(target):
        return 2
    os.umask(0o077)
    try:
        config = load(BASE / "backups" / (target + ".json"), 0)
        if set(config) != {"source", "scratch", "evidence", "restic"}:
            raise ValueError("invalid_backup_policy")
        source, scratch, evidence = (
            protected(Path(config[key]), 0, directory=True)
            for key in ("source", "scratch", "evidence")
        )
        directories = (source, scratch, evidence)
        if any(a == b or a in b.parents for i, a in enumerate(directories)
               for j, b in enumerate(directories) if i != j):
            raise ValueError("paths_must_be_distinct")
        for directory in (source, scratch, evidence):
            if directory.stat().st_mode & 0o077:
                raise ValueError("private_directory_required")
        if set(config["restic"]) != OPERATIONS:
            raise ValueError("expected_operation_bindings")
        repositories = {binding["repository"] for binding in config["restic"].values()}
        if len(repositories) != 1:
            raise ValueError("repository_mismatch")
        snapshot = operate(operation, target, version, source, scratch,
                           Restic(config["restic"][operation], 0), 0)
        # The executor discards stdout/stderr of handlers. Leave only a scoped
        # receipt in an administrator-owned directory.
        with (evidence / f"{snapshot}.{operation}.json").open("x") as stream:
            json.dump({"snapshot": snapshot, "operation": operation, "target": target}, stream)
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError, tarfile.TarError):
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
