#!/usr/bin/env python3
"""Create disposable TLS fixture material without altering any trust store."""

import os
from pathlib import Path
import secrets
import subprocess
import sys


def require_laboratory() -> Path:
    if not (
        os.environ.get("APP_ENV") == "testing"
        and os.environ.get("PORTAL_GSC_HTTPS_TRANSPORT_LAB") == "1"
        and os.environ.get("GITHUB_ACTIONS") == "true"
        and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"
    ):
        raise RuntimeError("Disposable TLS laboratory required")
    root = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
    if not root.is_dir():
        raise RuntimeError("Temporary laboratory directory required")
    return root


def openssl(*args: str) -> None:
    # Capture OpenSSL diagnostics: neither keys nor detailed command failures
    # become CI artifacts or logs. The caller reports a fixed failure label.
    subprocess.run(
        ["openssl", *args], check=True, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30,
    )


def root_certificate(directory: Path, name: str) -> None:
    openssl(
        "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-sha256", "-days", "1",
        "-set_serial", "0x" + secrets.token_hex(16),
        "-subj", "/CN=Synthetic GSC TLS Laboratory " + name,
        "-addext", "basicConstraints=critical,CA:TRUE,pathlen:0",
        "-addext", "keyUsage=critical,keyCertSign,cRLSign",
        "-addext", "subjectKeyIdentifier=hash",
        "-keyout", str(directory / (name + ".key")),
        "-out", str(directory / (name + ".pem")),
    )


def leaf_certificate(directory: Path, name: str, hostname: str) -> None:
    key = directory / (name + ".key")
    csr = directory / (name + ".csr")
    extensions = directory / (name + ".extensions")
    extensions.write_text(
        "basicConstraints=critical,CA:FALSE\n"
        "keyUsage=critical,digitalSignature,keyEncipherment\n"
        "extendedKeyUsage=serverAuth\n"
        "subjectAltName=DNS:" + hostname + "\n",
        encoding="ascii",
    )
    openssl(
        "req", "-new", "-newkey", "rsa:2048", "-nodes", "-sha256",
        "-subj", "/CN=" + hostname, "-keyout", str(key), "-out", str(csr),
    )
    openssl(
        "x509", "-req", "-in", str(csr), "-CA", str(directory / "ca.pem"),
        "-CAkey", str(directory / "ca.key"),
        "-set_serial", "0x" + secrets.token_hex(16),
        "-days", "1", "-sha256", "-extfile", str(extensions),
        "-out", str(directory / (name + ".pem")),
    )
    csr.unlink()
    extensions.unlink()


def main() -> None:
    root = require_laboratory()
    if len(sys.argv) != 2:
        raise RuntimeError("One disposable output directory required")
    directory = Path(sys.argv[1])
    if directory.is_symlink() or directory.exists():
        raise RuntimeError("Output directory must be new")
    directory = directory.resolve()
    if directory.parent != root or not directory.name.startswith("gsc-https-"):
        raise RuntimeError("Output must be a named direct child of RUNNER_TEMP")
    os.umask(0o077)
    directory.mkdir(mode=0o700)
    root_certificate(directory, "ca")
    root_certificate(directory, "wrong-ca")
    leaf_certificate(directory, "token", "oauth2.googleapis.com")
    leaf_certificate(directory, "sites", "www.googleapis.com")
    leaf_certificate(directory, "wrong-host", "wrong.example.invalid")
    # Issuing material is no longer needed; only leaf private keys and public
    # CA certificates survive until the workflow's explicit cleanup.
    (directory / "ca.key").unlink()
    (directory / "wrong-ca.key").unlink()
    for path in directory.iterdir():
        path.chmod(0o600)
    print("GSC TLS fixture certificates created in the disposable directory")


if __name__ == "__main__":
    try:
        main()
    except (Exception, KeyboardInterrupt):
        print("GSC TLS fixture certificate preparation failed", file=sys.stderr)
        sys.exit(1)
