#!/usr/bin/env python3
"""Read-only, offline comparison of an explicit expected materials package."""
import argparse
import base64
import datetime
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import selectors
import stat
import subprocess
import sys
import time
import uuid


SCHEMA = "imagine.performance.materials.expected.v1"
RESULT_SCHEMA = "imagine.performance.materials.preflight.v1"
MAX_FILE = 128 * 1024
MAX_COMMAND = 256 * 1024
MODES = {"ci-rehearsal", "operational-candidate"}
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
IDENTIFIER = re.compile(r"[A-Za-z0-9_-]{8,96}\Z")
DNS_NAME = re.compile(r"(?=.{1,253}\Z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\Z")
SYNTHETIC = re.compile(r"synthetic|phase[0-9]+(?:[\s._-]|$)|example\.(?:invalid|test|com|net|org)|\.invalid(?:$|[,/])|\.test(?:$|[,/])", re.I)
CI_COMPANIES = {"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"}
OPERATIONS = {"dashboard.read", "clients.read", "reports.read"}
PUBLIC_MODES = {0o400, 0o644}
PRIVATE_MODES = {0o400}
PORTAL_SECRET_MODES = {0o400, 0o600}
# The fixed files are those consumed by deploy/phase6/compose.yaml. No bootstrap
# owner directory, browser vault, CA key or recovery key is opened by this tool.
DIRECTORIES = {
    "portal-secrets": (33, {"portal_app_key": PORTAL_SECRET_MODES, "portal_db_password": PORTAL_SECRET_MODES,
                            "portal_authority_password": PORTAL_SECRET_MODES, "portal_service_identity": PORTAL_SECRET_MODES}),
    "portal-pki": (33, {"portal_db_ca.pem": PUBLIC_MODES, "performance_ca.pem": PUBLIC_MODES,
                        "portal_client.pem": PUBLIC_MODES, "portal_client.key": PRIVATE_MODES}),
    "web-pki": (101, {"tls_certificate": PUBLIC_MODES, "tls_private_key": PRIVATE_MODES, "access_passwords": PRIVATE_MODES}),
    "portal-pg": (999, {"bootstrap_password": PRIVATE_MODES, "server.pem": PUBLIC_MODES,
                        "server.key": PRIVATE_MODES, "pg_hba.conf": PUBLIC_MODES}),
    "performance-pg": (999, {"bootstrap_password": PRIVATE_MODES, "server.pem": PUBLIC_MODES,
                             "server.key": PRIVATE_MODES, "pg_hba.conf": PUBLIC_MODES}),
    "receiver-private": (33, {"db-password": PRIVATE_MODES, "service-keyring.json": PRIVATE_MODES, "db-ca.pem": PUBLIC_MODES}),
    "receiver-tls": (101, {"server.pem": PUBLIC_MODES, "server.key": PRIVATE_MODES,
                          "clients-ca.pem": PUBLIC_MODES, "clients.crl": PUBLIC_MODES}),
}
CA_FILES = {
    "portal_database": ("portal-pki", "portal_db_ca.pem"),
    "performance_database": ("receiver-private", "db-ca.pem"),
    "service_server": ("portal-pki", "performance_ca.pem"),
    "service_client": ("receiver-tls", "clients-ca.pem"),
}
CERT_FILES = {
    "portal_database": ("portal-pg", "server.pem", "server.key"),
    "performance_database": ("performance-pg", "server.pem", "server.key"),
    "service_server": ("receiver-tls", "server.pem", "server.key"),
    "service_client": ("portal-pki", "portal_client.pem", "portal_client.key"),
    "browser": ("web-pki", "tls_certificate", "tls_private_key"),
}
ERRORS = {"arguments", "manifest", "json", "mode", "ci_only", "path", "file_set", "file_type", "file_mode",
          "file_owner", "file_links", "file_limit", "file_changed", "input", "openssl", "command_limit", "pem",
          "public_pin", "ca", "certificate", "san", "eku", "key_pair", "key_reuse", "chain", "crl",
          "horizon", "identity", "credential", "credential_reuse", "basic_auth", "hba", "synthetic"}
STAMP = ("st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns")


class Refused(Exception):
    pass


def need(condition, code):
    if not condition:
        raise Refused(code)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        need(key not in result, "json")
        result[key] = value
    return result


def parse_json(raw):
    try:
        return json.loads(raw, object_pairs_hook=unique_object,
                          parse_constant=lambda _: (_ for _ in ()).throw(Refused("json")))
    except (ValueError, UnicodeError, RecursionError):
        raise Refused("json") from None


def canonical_path(value):
    need(isinstance(value, str) and 1 <= len(value) <= 4096 and "\x00" not in value, "path")
    path = Path(value)
    try:
        need(path.is_absolute() and str(path) == value and path.resolve(strict=True) == path, "path")
    except (OSError, RuntimeError):
        raise Refused("path") from None
    return path


def same_stat(a, b):
    return all(getattr(a, key) == getattr(b, key) for key in STAMP)


class Snapshot:
    """Pin read-only descriptors; reject changed or replaced paths before success."""
    def __init__(self):
        self.records = []

    def __enter__(self):
        return self

    def __exit__(self, *_):
        for _, fd, _ in reversed(self.records):
            os.close(fd)

    def open(self, value, *, directory=False, uid=None, modes=PUBLIC_MODES, parent_fd=None):
        path = canonical_path(value)
        try:
            before = path.lstat() if parent_fd is None else os.stat(path.name, dir_fd=parent_fd, follow_symlinks=False)
            # O_NONBLOCK prevents a substituted FIFO from blocking before fstat.
            flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
            if directory:
                flags |= os.O_DIRECTORY
            fd = os.open(path, flags) if parent_fd is None else os.open(path.name, flags, dir_fd=parent_fd)
            opened = os.fstat(fd)
            self.records.append((path, fd, opened))
            need(same_stat(before, opened), "file_changed")
            need(stat.S_ISDIR(opened.st_mode) if directory else stat.S_ISREG(opened.st_mode), "file_type")
            need(stat.S_IMODE(opened.st_mode) in modes, "file_mode")
            if uid is not None:
                need(opened.st_uid == uid and opened.st_gid == uid, "file_owner")
            else:
                need(opened.st_uid in {0, os.geteuid()}, "file_owner")
            if not directory:
                need(opened.st_nlink == 1, "file_links")
                need(0 < opened.st_size <= MAX_FILE, "file_limit")
            return fd
        except OSError:
            raise Refused("input") from None

    def raw(self, fd):
        try:
            # pread does not alter an inherited descriptor's offset for OpenSSL.
            raw = os.pread(fd, MAX_FILE + 1, 0)
            need(0 < len(raw) <= MAX_FILE, "file_limit")
            return raw
        except OSError:
            raise Refused("input") from None

    def finish(self):
        try:
            for path, fd, opened in self.records:
                need(canonical_path(str(path)) == path and same_stat(opened, os.fstat(fd))
                     and same_stat(opened, path.lstat()), "file_changed")
        except OSError:
            raise Refused("file_changed") from None


def openssl(args, *, data=b"", fds=()):
    """Fixed local executable, clean environment, bounded I/O; no shell or files written."""
    need(isinstance(data, bytes) and len(data) <= MAX_FILE, "file_limit")
    try:
        process = subprocess.Popen(["/usr/bin/openssl", *args], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.DEVNULL, pass_fds=tuple(fds), cwd="/",
                                   env={"PATH": "/usr/bin:/bin", "LC_ALL": "C", "LANG": "C", "OPENSSL_CONF": "/dev/null"})
    except OSError:
        raise Refused("openssl") from None
    output = bytearray()
    pending = memoryview(data)
    deadline = time.monotonic() + 10
    selector = selectors.DefaultSelector()
    try:
        selector.register(process.stdout, selectors.EVENT_READ)
        if pending:
            selector.register(process.stdin, selectors.EVENT_WRITE)
        else:
            process.stdin.close()
        while selector.get_map():
            remaining = deadline - time.monotonic()
            need(remaining > 0, "command_limit")
            for key, _ in selector.select(min(remaining, 0.25)):
                if key.fileobj is process.stdin:
                    try:
                        size = os.write(process.stdin.fileno(), pending[:4096])
                        pending = pending[size:]
                    except BrokenPipeError:
                        pending = pending[:0]
                    if not pending:
                        selector.unregister(process.stdin)
                        process.stdin.close()
                else:
                    chunk = os.read(process.stdout.fileno(), 16384)
                    if not chunk:
                        selector.unregister(process.stdout)
                    else:
                        need(len(output) + len(chunk) <= MAX_COMMAND, "command_limit")
                        output.extend(chunk)
        need(process.wait(timeout=max(0.01, deadline - time.monotonic())) == 0, "openssl")
        return bytes(output)
    except (OSError, subprocess.TimeoutExpired):
        raise Refused("openssl") from None
    finally:
        selector.close()
        if process.poll() is None:
            process.kill()
            process.wait()
        process.stdout.close()
        if not process.stdin.closed:
            process.stdin.close()


def pem_objects(raw, label, maximum=8):
    need(isinstance(raw, bytes) and len(raw) <= MAX_FILE, "pem")
    pattern = re.compile(rb"-----BEGIN " + label.encode() + rb"-----\r?\n([A-Za-z0-9+/=\r\n]+)-----END " + label.encode() + rb"-----")
    matches = list(pattern.finditer(raw))
    need(1 <= len(matches) <= maximum and not pattern.sub(b"", raw).strip(), "pem")
    objects = []
    try:
        for match in matches:
            der = base64.b64decode(re.sub(rb"\s", b"", match.group(1)), validate=True)
            need(0 < len(der) <= MAX_FILE, "pem")
            objects.append((match.group(0) + b"\n", der))
    except ValueError:
        raise Refused("pem") from None
    return objects


def text(raw, code):
    try:
        return raw.decode("ascii", "strict")
    except UnicodeError:
        raise Refused(code) from None


def certificate(pem):
    objects = pem_objects(pem, "CERTIFICATE", 1)
    details = text(openssl(["x509", "-noout", "-subject", "-issuer", "-nameopt", "RFC2253", "-ext",
                            "subjectAltName,extendedKeyUsage,basicConstraints,keyUsage"], data=pem), "certificate")
    extensions = {}
    current = None
    for line in details.splitlines():
        if line.startswith(("subject=", "issuer=")):
            continue
        header = re.fullmatch(r"X509v3 ([A-Za-z ]+):(?: critical)?", line.rstrip())
        if header:
            current = header.group(1)
            need(current not in extensions, "certificate")
            extensions[current] = []
        elif line.startswith(" ") and current:
            extensions[current].append(line.strip())
        elif line.strip():
            raise Refused("certificate")
    public = openssl(["x509", "-pubkey", "-noout"], data=pem)
    public = openssl(["pkey", "-pubin", "-outform", "DER"], data=public)
    return {"sha256": hashlib.sha256(objects[0][1]).hexdigest(), "extensions": {k: " ".join(v) for k, v in extensions.items()},
            "public": public, "names": details}


def list_of_strings(value, *, minimum=1, maximum=32):
    return (isinstance(value, list) and minimum <= len(value) <= maximum
            and all(isinstance(item, str) and 1 <= len(item) <= 256 for item in value) and len(set(value)) == len(value))


def epoch(value):
    return type(value) is int and 0 < value < 2**63


def valid_scope(identity):
    need(isinstance(identity, dict) and set(identity) == {"issuer", "key_id", "expires_at", "companies", "operations"}, "identity")
    need(all(isinstance(identity[key], str) and IDENTIFIER.fullmatch(identity[key]) for key in ("issuer", "key_id")), "identity")
    need(epoch(identity["expires_at"]) and list_of_strings(identity["companies"], maximum=16)
         and list_of_strings(identity["operations"], maximum=3) and set(identity["operations"]) <= OPERATIONS, "identity")
    try:
        need(all(str(uuid.UUID(value)) == value and uuid.UUID(value).int != 0 for value in identity["companies"]), "identity")
    except ValueError:
        raise Refused("identity") from None


def hba_records(raw):
    records = []
    for line in text(raw, "hba").splitlines():
        fields = line.split("#", 1)[0].split()
        if not fields:
            continue
        need(len(records) < 64 and fields[0] in {"local", "hostssl"}, "hba")
        need(len(fields) == (4 if fields[0] == "local" else 5) and fields[-1] == "scram-sha-256", "hba")
        need(all(re.fullmatch(r"[a-z_][a-z0-9_]{0,62}", field) for field in fields[1:3]), "hba")
        if fields[0] == "hostssl":
            try:
                need(str(ipaddress.ip_network(fields[3], strict=True)) == fields[3], "hba")
            except ValueError:
                raise Refused("hba") from None
        records.append(fields)
    need(any(r[0] == "local" for r in records) and any(r[0] == "hostssl" for r in records), "hba")
    return records


def validate_manifest(manifest, mode, environ, now):
    need(isinstance(manifest, dict) and set(manifest) == {"schema", "purpose", "project", "valid_until_epoch", "browser_host",
         "directories", "browser_ca_file", "ca_sha256", "certificates", "client_crl_sha256", "identity", "http_basic_users", "hba"}, "manifest")
    need(mode in MODES and manifest["schema"] == SCHEMA and manifest["purpose"] == mode, "mode")
    if mode == "ci-rehearsal":
        need(environ.get("GITHUB_ACTIONS") == "true" and environ.get("ADMIN_PHASE12_MATERIALS_CI") == "1"
             and environ.get("ADMIN_PHASE7_CI") == "1", "ci_only")
        run, attempt = environ.get("GITHUB_RUN_ID", ""), environ.get("GITHUB_RUN_ATTEMPT", "")
        need(re.fullmatch(r"[0-9]{1,20}", run) and re.fullmatch(r"[0-9]{1,10}", attempt)
             and manifest["project"] == "imagine-performance-p7-ci-" + run + "-" + attempt, "ci_only")
    else:
        need(manifest["project"] == "imagine-performance-p6-staging", "manifest")
    need(epoch(manifest["valid_until_epoch"]) and manifest["valid_until_epoch"] > now, "horizon")
    need(isinstance(manifest["directories"], dict) and set(manifest["directories"]) == set(DIRECTORIES), "manifest")
    values = list(manifest["directories"].values())
    need(all(isinstance(value, str) for value in values) and len(set(values)) == len(values), "path")
    paths = [canonical_path(value) for value in values]
    need(all(a not in b.parents for a in paths for b in paths if a != b), "path")
    need(isinstance(manifest["ca_sha256"], dict) and set(manifest["ca_sha256"]) == set(CERT_FILES), "manifest")
    for pins in manifest["ca_sha256"].values():
        need(list_of_strings(pins, maximum=8) and all(HEX64.fullmatch(pin) for pin in pins), "manifest")
    need(isinstance(manifest["certificates"], dict) and set(manifest["certificates"]) == set(CERT_FILES), "manifest")
    for item in manifest["certificates"].values():
        need(isinstance(item, dict) and set(item) == {"chain_sha256", "dns_sans", "ip_sans"}, "manifest")
        need(list_of_strings(item["chain_sha256"], maximum=8) and all(HEX64.fullmatch(pin) for pin in item["chain_sha256"]), "manifest")
        need(list_of_strings(item["dns_sans"], minimum=0, maximum=8) and all(DNS_NAME.fullmatch(name) for name in item["dns_sans"]), "san")
        need(list_of_strings(item["ip_sans"], minimum=0, maximum=8) and 1 <= len(item["dns_sans"]) + len(item["ip_sans"]) <= 8, "san")
        try:
            need(all(str(ipaddress.ip_address(address)) == address for address in item["ip_sans"]), "san")
        except ValueError:
            raise Refused("san") from None
    for name, host in (("portal_database", "portal-db"), ("performance_database", "performance-db"), ("service_server", "performance.internal")):
        need(manifest["certificates"][name]["dns_sans"] == [host] and not manifest["certificates"][name]["ip_sans"], "san")
    browser = manifest["browser_host"]
    need(isinstance(browser, str) and (browser in manifest["certificates"]["browser"]["dns_sans"]
                                      or browser in manifest["certificates"]["browser"]["ip_sans"]), "san")
    need(isinstance(manifest["client_crl_sha256"], str) and HEX64.fullmatch(manifest["client_crl_sha256"]), "manifest")
    valid_scope(manifest["identity"])
    need(manifest["identity"]["expires_at"] > manifest["valid_until_epoch"], "horizon")
    need(list_of_strings(manifest["http_basic_users"], maximum=16) and all(re.fullmatch(r"[A-Za-z0-9_.@-]{1,96}", user)
         for user in manifest["http_basic_users"]), "manifest")
    need(isinstance(manifest["hba"], dict) and set(manifest["hba"]) == {"portal_database", "performance_database"}, "manifest")
    for records in manifest["hba"].values():
        need(isinstance(records, list) and 1 <= len(records) <= 64 and all(isinstance(row, list)
             and 1 <= len(row) <= 5 and all(isinstance(field, str) and len(field) <= 128 for field in row) for row in records), "hba")
        need(hba_records("\n".join(" ".join(row) for row in records).encode()) == records, "hba")
    if mode == "operational-candidate":
        names = [browser, manifest["identity"]["issuer"], manifest["identity"]["key_id"], *manifest["http_basic_users"]]
        names += [name for item in manifest["certificates"].values() for name in item["dns_sans"]]
        need(not any(SYNTHETIC.search(name) or name == "smoke" for name in names)
             and not (set(manifest["identity"]["companies"]) & CI_COMPANIES), "synthetic")


def check_credentials(files, expected):
    identity = parse_json(files[("portal-secrets", "portal_service_identity")][1])
    need(isinstance(identity, dict) and set(identity) == set(expected) | {"active", "secret"}, "identity")
    need(identity["active"] is True and type(identity["expires_at"]) is int
         and all(identity[key] == value for key, value in expected.items()), "identity")
    secret = identity["secret"]
    need(isinstance(secret, str) and re.fullmatch(r"[!-~]{32,256}", secret), "credential")
    keyring = parse_json(files[("receiver-private", "service-keyring.json")][1])
    need(isinstance(keyring, dict) and set(keyring) == {expected["issuer"]}, "identity")
    ring = keyring[expected["issuer"]]
    need(isinstance(ring, dict) and set(ring) == {expected["key_id"]}, "identity")
    entry = ring[expected["key_id"]]
    need(isinstance(entry, dict) and set(entry) == {"active", "secret", "expires_at"} and entry["active"] is True
         and type(entry["expires_at"]) is int and entry["expires_at"] == expected["expires_at"]
         and isinstance(entry["secret"], str), "identity")
    need(hmac.compare_digest(secret.encode(), entry["secret"].encode()), "identity")
    app = files[("portal-secrets", "portal_app_key")][1]
    need(app.startswith(b"base64:") and app.strip() == app, "credential")
    try:
        app_key = base64.b64decode(app[7:], validate=True)
    except ValueError:
        raise Refused("credential") from None
    need(len(app_key) == 32 and base64.b64encode(app_key) == app[7:], "credential")
    secrets = [app_key, secret.encode()]
    for key in (("portal-secrets", "portal_db_password"), ("portal-secrets", "portal_authority_password"),
                ("portal-pg", "bootstrap_password"), ("performance-pg", "bootstrap_password"), ("receiver-private", "db-password")):
        value = files[key][1]
        need(re.fullmatch(rb"[!-~]{16,4096}", value), "credential")
        secrets.append(value)
    need(not any(hmac.compare_digest(value, other) for index, value in enumerate(secrets) for other in secrets[index+1:]), "credential_reuse")


def check_basic(raw, users):
    observed = []
    hashes = [r"\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}",
              r"\$(?:apr1|1)\$[./A-Za-z0-9]{1,8}\$[./A-Za-z0-9]{22}",
              r"\$5\$(?:rounds=[1-9][0-9]{3,8}\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{43}",
              r"\$6\$(?:rounds=[1-9][0-9]{3,8}\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}",
              r"\{SHA\}[A-Za-z0-9+/]{27}="]
    for line in text(raw, "basic_auth").splitlines():
        need(line.count(":") == 1, "basic_auth")
        user, hashed = line.split(":")
        need(user in users and user not in observed and any(re.fullmatch(pattern, hashed) for pattern in hashes), "basic_auth")
        observed.append(user)
    need(set(observed) == set(users), "basic_auth")


def check_san(info, expected):
    value = info["extensions"].get("Subject Alternative Name", "")
    names, addresses = [], []
    for item in value.split(", "):
        if item.startswith("DNS:"):
            name = item[4:]
            need(DNS_NAME.fullmatch(name), "san")
            names.append(name)
        elif item.startswith("IP Address:"):
            try:
                addresses.append(str(ipaddress.ip_address(item[11:])))
            except ValueError:
                raise Refused("san") from None
        else:
            raise Refused("san")
    need(len(set(names)) == len(names) and len(set(addresses)) == len(addresses)
         and set(names) == set(expected["dns_sans"]) and set(addresses) == set(expected["ip_sans"]), "san")


def verify_chain(pem, ca_fd, epoch_value, *, purpose="any", host=None, crl_fd=None, chain_fd=None):
    args = ["verify", "-no-CAfile", "-no-CApath", "-no-CAstore", "-CAfile", "/proc/self/fd/" + str(ca_fd),
            "-check_ss_sig", "-verify_depth", "2" if purpose == "sslclient" else "8", "-purpose", purpose, "-attime", str(epoch_value)]
    fds = [ca_fd]
    if host is not None:
        try:
            ipaddress.ip_address(host)
            flag = "-verify_ip"
        except ValueError:
            flag = "-verify_hostname"
        args += [flag, host]
    if chain_fd is not None:
        args += ["-untrusted", "/proc/self/fd/" + str(chain_fd)]
        fds.append(chain_fd)
    if crl_fd is not None:
        args += ["-CRLfile", "/proc/self/fd/" + str(crl_fd), "-crl_check_all"]
        fds.append(crl_fd)
    try:
        openssl(args, data=pem, fds=fds)
    except Refused:
        raise Refused("crl" if crl_fd is not None else "chain") from None


def crl_time(value):
    match = re.fullmatch(r"([A-Z][a-z]{2}) +([0-9]{1,2}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) ([0-9]{4}) GMT", value)
    need(match is not None, "crl")
    months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
    try:
        month, day, hour, minute, second, year = match.groups()
        return int(datetime.datetime(int(year), months.index(month) + 1, int(day), int(hour), int(minute),
                                     int(second), tzinfo=datetime.timezone.utc).timestamp())
    except (ValueError, OverflowError):
        raise Refused("crl") from None


def check_pki(files, manifest, now, mode):
    ca_public = []
    for role in CERT_FILES:
        fd, raw = files[CA_FILES[role]] if role != "browser" else files[("browser", "ca")]
        objects = pem_objects(raw, "CERTIFICATE")
        infos = [certificate(pem) for pem, _ in objects]
        need([info["sha256"] for info in infos] == manifest["ca_sha256"][role], "public_pin")
        for (pem, _), info in zip(objects, infos):
            need(re.fullmatch(r"CA:TRUE(?:, pathlen:[0-9]+)?", info["extensions"].get("Basic Constraints", "")), "ca")
            usage = info["extensions"].get("Key Usage")
            need(usage is None or "Certificate Sign" in usage.split(", "), "ca")
            if mode == "operational-candidate":
                need(not SYNTHETIC.search(info["names"]), "synthetic")
            for instant in (now, manifest["valid_until_epoch"]):
                verify_chain(pem, fd, instant)
            ca_public.append(info["public"])
    crl_fd, crl_raw = files[("receiver-tls", "clients.crl")]
    crl = pem_objects(crl_raw, "X509 CRL", 1)[0]
    need(hashlib.sha256(crl[1]).hexdigest() == manifest["client_crl_sha256"], "public_pin")
    crl_dates = text(openssl(["crl", "-noout", "-lastupdate", "-nextupdate"], data=crl[0]), "crl").splitlines()
    need(len(crl_dates) == 2 and crl_dates[0].startswith("lastUpdate=") and crl_dates[1].startswith("nextUpdate="), "crl")
    need(crl_time(crl_dates[0][11:]) <= now and crl_time(crl_dates[1][11:]) > manifest["valid_until_epoch"], "crl")
    client_ca_fd = files[CA_FILES["service_client"]][0]
    try:
        openssl(["crl", "-noout", "-verify", "-CAfile", "/proc/self/fd/" + str(client_ca_fd),
                 "-no-CAfile", "-no-CApath", "-no-CAstore"], data=crl[0], fds=[client_ca_fd])
    except Refused:
        raise Refused("crl") from None
    leaf_public = []
    for role, (directory, cert_name, key_name) in CERT_FILES.items():
        cert_fd, raw = files[(directory, cert_name)]
        objects = pem_objects(raw, "CERTIFICATE")
        infos = [certificate(pem) for pem, _ in objects]
        expected = manifest["certificates"][role]
        need([info["sha256"] for info in infos] == expected["chain_sha256"], "public_pin")
        leaf = infos[0]
        need(leaf["extensions"].get("Basic Constraints") in {None, "CA:FALSE"}, "certificate")
        check_san(leaf, expected)
        usage = "TLS Web Client Authentication" if role == "service_client" else "TLS Web Server Authentication"
        need(leaf["extensions"].get("Extended Key Usage") == usage, "eku")
        if mode == "operational-candidate":
            need(not any(SYNTHETIC.search(info["names"]) for info in infos), "synthetic")
        key_raw = files[(directory, key_name)][1]
        # Only one unencrypted private key. No passphrase prompt/provider URI.
        label = re.match(rb"-----BEGIN (PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY)-----", key_raw)
        need(label is not None, "pem")
        pem_objects(key_raw, label.group(1).decode(), 1)
        try:
            openssl(["pkey", "-check", "-noout", "-passin", "pass:"], data=key_raw)
            public = openssl(["pkey", "-pubout", "-outform", "DER", "-passin", "pass:"], data=key_raw)
        except Refused:
            raise Refused("key_pair") from None
        need(hmac.compare_digest(public, leaf["public"]), "key_pair")
        need(public not in ca_public and public not in leaf_public, "key_reuse")
        leaf_public.append(public)
        ca_fd = files[CA_FILES[role]][0] if role != "browser" else files[("browser", "ca")][0]
        host = None if role == "service_client" else manifest["browser_host"] if role == "browser" else expected["dns_sans"][0]
        for instant in (now, manifest["valid_until_epoch"]):
            verify_chain(objects[0][0], ca_fd, instant, purpose="sslclient" if role == "service_client" else "sslserver",
                         host=host, crl_fd=crl_fd if role == "service_client" else None, chain_fd=cert_fd)


def result(mode, status, reason=None):
    value = {"schema": RESULT_SCHEMA, "mode": mode if mode in MODES else "invalid", "status": status,
             "deployment_authorized": False, "human_approval_verified": False, "custody_verified": False,
             "sql_grants_verified": False, "live_tls_verified": False, "nominal_enrollment_verified": False}
    if reason is not None:
        value["reason"] = reason if reason in ERRORS else "input"
    return value


def verify(manifest_path, mode, *, environ=None):
    environ = os.environ if environ is None else environ
    now = int(time.time())
    with Snapshot() as snapshot:
        manifest_fd = snapshot.open(manifest_path, modes={0o400, 0o600, 0o644})
        manifest = parse_json(snapshot.raw(manifest_fd))
        validate_manifest(manifest, mode, environ, now)
        files = {}
        for directory, (uid, specs) in DIRECTORIES.items():
            path = manifest["directories"][directory]
            directory_fd = snapshot.open(path, directory=True, uid=uid, modes={0o700})
            wanted = dict(specs)
            if mode == "ci-rehearsal" and directory == "portal-secrets":
                wanted["phase7_owner_password"] = PRIVATE_MODES
            need(set(os.listdir(directory_fd)) == set(wanted), "file_set")
            for name, modes in wanted.items():
                fd = snapshot.open(str(Path(path) / name), uid=uid, modes=modes, parent_fd=directory_fd)
                files[(directory, name)] = (fd, snapshot.raw(fd))
        if mode == "ci-rehearsal":
            need(files[("portal-secrets", "phase7_owner_password")][1] == b"CI_PLACEHOLDER_NOT_AN_OWNER_PASSWORD", "credential")
        browser_ca = canonical_path(manifest["browser_ca_file"])
        need(not any(Path(value) in browser_ca.parents for value in manifest["directories"].values()), "path")
        fd = snapshot.open(str(browser_ca), modes=PUBLIC_MODES)
        files[("browser", "ca")] = (fd, snapshot.raw(fd))
        check_credentials(files, manifest["identity"])
        check_basic(files[("web-pki", "access_passwords")][1], manifest["http_basic_users"])
        for role, directory in (("portal_database", "portal-pg"), ("performance_database", "performance-pg")):
            need(hba_records(files[(directory, "pg_hba.conf")][1]) == manifest["hba"][role], "hba")
        check_pki(files, manifest, now, mode)
        snapshot.finish()
    value = result(mode, "MATERIALS_MATCH_OBSERVED")
    value.update({"directories_checked": 7, "leaf_certificates_checked": 5, "client_crls_checked": 1,
                  "valid_until_epoch": manifest["valid_until_epoch"], "synthetic_rehearsal": mode == "ci-rehearsal"})
    return value


class Parser(argparse.ArgumentParser):
    def error(self, _message):
        raise Refused("arguments")


def main(argv=None):
    mode = "invalid"
    try:
        parser = Parser(description=__doc__, allow_abbrev=False)
        parser.add_argument("--mode", choices=sorted(MODES), required=True)
        parser.add_argument("--manifest", required=True)
        args = parser.parse_args(argv)
        mode = args.mode
        value = verify(args.manifest, mode)
        code = 0
    except (Refused, OSError, ValueError, TypeError, KeyError, OverflowError, RecursionError) as error:
        value = result(mode, "REFUSED", str(error) if isinstance(error, Refused) else "input")
        code = 1
    print(json.dumps(value, sort_keys=True, separators=(",", ":")))
    return code


if __name__ == "__main__":
    sys.exit(main())
