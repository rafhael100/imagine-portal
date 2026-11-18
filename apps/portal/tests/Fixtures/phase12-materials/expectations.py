"""Synthetic expectation only; never derive operational approval from observations."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import time


DIRECTORIES = ("portal-secrets", "portal-pki", "web-pki", "portal-pg", "performance-pg", "receiver-private", "receiver-tls")
CERTS = {
    "portal_database": ("portal-pg", "server.pem", ["portal-db"], []),
    "performance_database": ("performance-pg", "server.pem", ["performance-db"], []),
    "service_server": ("receiver-tls", "server.pem", ["performance.internal"], []),
    "service_client": ("portal-pki", "portal_client.pem", ["portal-client"], []),
    "browser": ("web-pki", "tls_certificate", ["phase6.example.invalid"], ["127.0.0.1"]),
}
CA_FILES = {"portal_database": ("portal-pki", "portal_db_ca.pem"), "performance_database": ("receiver-private", "db-ca.pem"),
            "service_server": ("portal-pki", "performance_ca.pem"), "service_client": ("receiver-tls", "clients-ca.pem")}


def guard(environ):
    assert environ.get("GITHUB_ACTIONS") == "true" and environ.get("ADMIN_PHASE12_MATERIALS_CI") == "1"
    assert environ.get("ADMIN_PHASE7_CI") == "1"
    assert re.fullmatch(r"[0-9]{1,20}", environ.get("GITHUB_RUN_ID", ""))
    assert re.fullmatch(r"[0-9]{1,10}", environ.get("GITHUB_RUN_ATTEMPT", ""))


def cert_pin(path):
    # Existing Phase7 generator writes exactly one public certificate per file.
    der = ssl.PEM_cert_to_DER_cert(path.read_text("ascii"))
    return hashlib.sha256(der).hexdigest()


def crl_pin(path):
    pem = path.read_text("ascii").strip()
    assert pem.startswith("-----BEGIN X509 CRL-----\n") and pem.endswith("\n-----END X509 CRL-----")
    der = base64.b64decode("".join(pem.splitlines()[1:-1]), validate=True)
    return hashlib.sha256(der).hexdigest()


def expected(root, environ):
    """One explicit five-minute test horizon; does not extend any material."""
    guard(environ)
    root = Path(root)
    identity = json.loads((root / "portal-secrets/portal_service_identity").read_bytes())
    operations = ["dashboard.read", "clients.read", "reports.read"] if environ.get("ADMIN_PHASE8_NAVIGATION_CI") == "1" else ["dashboard.read"]
    assert identity["issuer"] == "portal_service" and identity["key_id"] == "phase7_key_01"
    assert identity["companies"] == ["aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"]
    assert identity["operations"] == operations and identity["active"] is True
    horizon = int(time.time()) + 300
    assert type(identity["expires_at"]) is int and identity["expires_at"] > horizon
    ca = {role: [cert_pin(root / directory / filename)] for role, (directory, filename) in CA_FILES.items()}
    ca["browser"] = [cert_pin(root / "ca.pem")]
    hba = [["local", "all", "all", "scram-sha-256"], ["hostssl", "all", "all", "0.0.0.0/0", "scram-sha-256"]]
    return {
        "schema": "imagine.performance.materials.expected.v1", "purpose": "ci-rehearsal",
        "project": "imagine-performance-p7-ci-" + environ["GITHUB_RUN_ID"] + "-" + environ["GITHUB_RUN_ATTEMPT"],
        "valid_until_epoch": horizon, "browser_host": "127.0.0.1",
        "directories": {name: str(root / name) for name in DIRECTORIES}, "browser_ca_file": str(root / "ca.pem"),
        "ca_sha256": ca,
        "certificates": {role: {"chain_sha256": [cert_pin(root / directory / filename)], "dns_sans": dns, "ip_sans": ips}
                         for role, (directory, filename, dns, ips) in CERTS.items()},
        "client_crl_sha256": crl_pin(root / "receiver-tls/clients.crl"),
        "identity": {key: identity[key] for key in ("issuer", "key_id", "companies", "operations", "expires_at")},
        "http_basic_users": ["smoke"], "hba": {"portal_database": hba, "performance_database": hba},
    }
