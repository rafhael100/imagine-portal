#!/usr/bin/env python3
"""Create development-only files without printing or accepting secret values."""
import base64
import json
import os
from pathlib import Path
import secrets
import sys

if os.geteuid() != 0 or len(sys.argv) != 2:
    raise SystemExit("Run as root with one new absolute private directory.")
target = Path(sys.argv[1])
if not target.is_absolute() or target.exists() or target.is_symlink():
    raise SystemExit("A new absolute private directory is required; existing secrets are never replaced.")
# Do not follow symbolic ancestors or accept writable non-sticky ancestors.
for parent in target.parents:
    stat = parent.lstat()
    if parent.is_symlink() or not parent.is_dir() or (stat.st_mode & 0o022 and not stat.st_mode & 0o1000):
        raise SystemExit("Protected real ancestor directories required.")
os.umask(0o077)
target.mkdir(mode=0o700)
values = {
    "origin": "https://portal-dev.imaginecomunica.com.br",
    "host": "127.0.0.1", "port": 5432, "database": "portal_identity_dev",
    "identity_password": secrets.token_hex(24),
    "envelope_password": secrets.token_hex(24),
    "proof_key": secrets.token_hex(32),
    "totp_key": base64.b64encode(secrets.token_bytes(32)).decode("ascii"),
    "scope_pepper": secrets.token_hex(32),
}
contents = {
    "runtime.json": json.dumps(values, separators=(",", ":")),
    "database-admin": secrets.token_hex(32),
    "app-key": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii"),
}
for name, content in contents.items():
    path = target / name
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, "w") as stream:
        stream.write(content)
    os.chown(path, 82, 82)
print("Created private development files; no credential values emitted.")
