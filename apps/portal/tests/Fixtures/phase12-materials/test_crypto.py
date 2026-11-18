#!/usr/bin/env python3
"""Offline crypto/diagnostic negatives; all generated keys are disposable tests."""
import contextlib
import copy
import datetime
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from expectations import cert_pin, crl_pin

MODULE = Path(__file__).resolve().parents[3] / "tools/performance-release/materials_preflight.py"
spec = importlib.util.spec_from_file_location("materials_preflight", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


def generate(root, args):
    subprocess.run(["/usr/bin/openssl", *args], cwd=root, check=True, timeout=15,
                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                   env={"PATH": "/usr/bin:/bin", "LC_ALL": "C", "OPENSSL_CONF": "/dev/null"})


class CryptoFixture:
    def __init__(self, root):
        self.root = root
        generate(root, ["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=SYNTHETIC Phase12 crypto CA",
                        "-addext", "basicConstraints=critical,CA:TRUE", "-keyout", "ca.key", "-out", "ca.pem"])
        self.specs = {
            "portal_database": ("portal-db", "DNS:portal-db", "serverAuth"),
            "performance_database": ("performance-db", "DNS:performance-db", "serverAuth"),
            "service_server": ("performance.internal", "DNS:performance.internal", "serverAuth"),
            "service_client": ("portal-client", "DNS:portal-client", "clientAuth"),
            "browser": ("phase6.example.invalid", "IP:127.0.0.1,DNS:phase6.example.invalid", "serverAuth"),
        }
        for role, (cn, san, eku) in self.specs.items():
            generate(root, ["req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=" + cn,
                            "-keyout", role + ".key", "-out", role + ".csr"])
            self.sign(role, san=san, eku=eku)
        (root / "index").write_text("")
        (root / "crlnumber").write_text("1000\n")
        (root / "crl.cnf").write_text("[ca]\ndefault_ca=fixture\n[fixture]\ndatabase=index\ncertificate=ca.pem\n"
                                     "private_key=ca.key\ndefault_md=sha256\ndefault_crl_days=1\ncrlnumber=crlnumber\n")
        self.crl()

    def sign(self, role, *, san, eku, extra=""):
        (self.root / "ext").write_text(("subjectAltName=" + san + "\n" if san else "")
                                       + ("extendedKeyUsage=" + eku + "\n" if eku else "") + extra)
        generate(self.root, ["x509", "-req", "-in", role + ".csr", "-CA", "ca.pem", "-CAkey", "ca.key",
                             "-CAcreateserial", "-days", "1", "-extfile", "ext", "-out", role + ".pem"])

    def crl(self, extra=()):
        generate(self.root, ["ca", "-gencrl", "-batch", "-config", "crl.cnf", "-out", "clients.crl", *extra])

    def expected(self):
        expected = {"valid_until_epoch": int(time.time()) + 300, "browser_host": "127.0.0.1",
                    "ca_sha256": {role: [cert_pin(self.root / "ca.pem")] for role in self.specs},
                    "client_crl_sha256": crl_pin(self.root / "clients.crl"), "certificates": {}}
        for role, (_, san, _) in self.specs.items():
            expected["certificates"][role] = {"chain_sha256": [cert_pin(self.root / (role + ".pem"))],
                 "dns_sans": [item[4:] for item in san.split(",") if item.startswith("DNS:")],
                 "ip_sans": [item[3:] for item in san.split(",") if item.startswith("IP:")]}
        return expected

    def check(self, *, expected=None, mode="ci-rehearsal"):
        files = {}
        descriptors = []
        def put(key, path):
            fd = os.open(path, os.O_RDONLY)
            descriptors.append(fd)
            files[key] = (fd, path.read_bytes())
        try:
            for role, key in p.CA_FILES.items():
                put(key, self.root / "ca.pem")
            put(("browser", "ca"), self.root / "ca.pem")
            put(("receiver-tls", "clients.crl"), self.root / "clients.crl")
            for role, (directory, certificate, key) in p.CERT_FILES.items():
                put((directory, certificate), self.root / (role + ".pem"))
                put((directory, key), self.root / (role + ".key"))
            p.check_pki(files, self.expected() if expected is None else expected, int(time.time()), mode)
        finally:
            for fd in descriptors:
                os.close(fd)


class CryptoTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base = tempfile.TemporaryDirectory(prefix="phase12-crypto-")
        cls.template = CryptoFixture(Path(cls.base.name))

    @classmethod
    def tearDownClass(cls):
        cls.base.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="phase12-case-")
        self.f = copy.copy(self.template)
        self.f.root = Path(self.temp.name)
        for source in self.template.root.iterdir():
            shutil.copyfile(source, self.f.root / source.name)

    def tearDown(self):
        self.temp.cleanup()

    def denied(self, code, callback=None):
        with self.assertRaises(p.Refused) as caught:
            (self.f.check if callback is None else callback)()
        self.assertEqual(str(caught.exception), code)

    def test_real_crypto_and_explicit_profile(self):
        self.f.check()

    def test_known_synthetic_ca_cannot_be_operational_after_renaming_identity(self):
        self.denied("synthetic", lambda: self.f.check(mode="operational-candidate"))

    def test_cn_match_does_not_replace_explicit_san(self):
        self.f.sign("service_server", san=None, eku="serverAuth")
        self.denied("san")

    def test_server_purpose_does_not_replace_explicit_eku(self):
        self.f.sign("service_server", san="DNS:performance.internal", eku=None)
        self.denied("eku")

    def test_client_eku_cannot_be_used_for_server(self):
        self.f.sign("service_server", san="DNS:performance.internal", eku="clientAuth")
        self.denied("eku")

    def test_leaf_ca_privilege_is_refused(self):
        self.f.sign("service_server", san="DNS:performance.internal", eku="serverAuth", extra="basicConstraints=critical,CA:TRUE\n")
        self.denied("certificate")

    def test_different_private_key_is_refused(self):
        shutil.copyfile(self.f.root / "browser.key", self.f.root / "service_server.key")
        self.denied("key_pair")

    def test_same_public_fingerprint_cannot_hide_extra_pem(self):
        path = self.f.root / "service_server.pem"
        expected = self.f.expected()
        path.write_bytes(path.read_bytes() + b"SYNTHETIC_UNEXPECTED_TRAILER")
        self.denied("pem", lambda: self.f.check(expected=expected))

    def test_crl_signature_is_verified_even_with_updated_public_pin(self):
        generate(self.f.root, ["crl", "-in", "clients.crl", "-badsig", "-out", "bad.crl"])
        shutil.copyfile(self.f.root / "bad.crl", self.f.root / "clients.crl")
        self.denied("crl")

    def test_revoked_client_is_refused(self):
        generate(self.f.root, ["ca", "-batch", "-config", "crl.cnf", "-revoke", "service_client.pem"])
        self.f.crl()
        self.denied("crl")

    def test_client_intermediate_requires_issuer_crl_and_root(self):
        generate(self.f.root, ["req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=SYNTHETIC intermediate",
                               "-keyout", "intermediate.key", "-out", "intermediate.csr"])
        (self.f.root / "intermediate.ext").write_text("basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n")
        generate(self.f.root, ["x509", "-req", "-in", "intermediate.csr", "-CA", "ca.pem", "-CAkey", "ca.key",
                    "-CAcreateserial", "-days", "1", "-extfile", "intermediate.ext", "-out", "intermediate.pem"])
        (self.f.root / "client.ext").write_text("subjectAltName=DNS:portal-client\nextendedKeyUsage=clientAuth\n")
        generate(self.f.root, ["x509", "-req", "-in", "service_client.csr", "-CA", "intermediate.pem", "-CAkey", "intermediate.key",
                    "-CAcreateserial", "-days", "1", "-extfile", "client.ext", "-out", "chained-client.pem"])
        client = (self.f.root / "chained-client.pem").read_bytes()
        (self.f.root / "chain.pem").write_bytes(client + (self.f.root / "intermediate.pem").read_bytes())
        # Leaf CRL is valid. Only the root-issued CRL that covers the intermediate
        # is missing; the former leaf-only check would accept this package.
        (self.f.root / "intermediate.index").write_text("")
        (self.f.root / "intermediate.crlnumber").write_text("1000\n")
        (self.f.root / "intermediate.cnf").write_text("[ca]\ndefault_ca=fixture\n[fixture]\ndatabase=intermediate.index\n"
            "certificate=intermediate.pem\nprivate_key=intermediate.key\ndefault_md=sha256\ndefault_crl_days=1\ncrlnumber=intermediate.crlnumber\n")
        generate(self.f.root, ["ca", "-gencrl", "-batch", "-config", "intermediate.cnf", "-out", "intermediate.crl"])
        descriptors = [os.open(self.f.root / name, os.O_RDONLY) for name in ("ca.pem", "chain.pem", "intermediate.crl", "intermediate.pem")]
        root_fd, chain_fd, crl_fd, intermediate_fd = descriptors
        try:
            p.verify_chain(client, root_fd, int(time.time()), purpose="sslclient", chain_fd=chain_fd)
            p.openssl(["verify", "-no-CAfile", "-no-CApath", "-no-CAstore", "-CAfile", "/proc/self/fd/" + str(root_fd),
                       "-untrusted", "/proc/self/fd/" + str(chain_fd), "-CRLfile", "/proc/self/fd/" + str(crl_fd),
                       "-crl_check", "-purpose", "sslclient", "-verify_depth", "2"], data=client, fds=[root_fd, chain_fd, crl_fd])
            self.denied("crl", lambda: p.verify_chain(client, root_fd, int(time.time()), purpose="sslclient", chain_fd=chain_fd, crl_fd=crl_fd))
            self.denied("chain", lambda: p.verify_chain(client, intermediate_fd, int(time.time()), purpose="sslclient", chain_fd=chain_fd))
        finally:
            for fd in descriptors:
                os.close(fd)

    def test_expired_and_future_crl_are_refused(self):
        now = datetime.datetime.now(datetime.timezone.utc)
        stamp = lambda delta: (now + datetime.timedelta(seconds=delta)).strftime("%Y%m%d%H%M%SZ")
        for start, end in ((-600, -300), (300, 900), (-300, 30)):
            self.f.crl(["-crl_lastupdate", stamp(start), "-crl_nextupdate", stamp(end)])
            self.denied("crl")

    def test_certificates_must_cover_now_and_the_explicit_horizon(self):
        fd = os.open(self.f.root / "ca.pem", os.O_RDONLY)
        try:
            for instant in (int(time.time()) - 3600, int(time.time()) + 172800):
                self.denied("chain", lambda: p.verify_chain((self.f.root / "service_server.pem").read_bytes(), fd,
                    instant, purpose="sslserver", host="performance.internal"))
        finally:
            os.close(fd)

    def test_pinned_leaf_cannot_act_as_trusted_ca(self):
        shutil.copyfile(self.f.root / "browser.pem", self.f.root / "ca.pem")
        self.denied("ca")

    def test_wrong_ca_even_when_new_pin_is_explicit(self):
        generate(self.f.root, ["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=SYNTHETIC other CA",
                    "-addext", "basicConstraints=critical,CA:TRUE", "-keyout", "other.key", "-out", "ca.pem"])
        self.denied("crl")

    def test_metadata_mode_owner_links_and_changes_are_refused(self):
        path = self.f.root / "metadata"
        path.write_text("SYNTHETIC_METADATA_ONLY")
        path.chmod(0o400)
        with p.Snapshot() as snapshot:
            with self.assertRaises(p.Refused) as caught:
                snapshot.open(str(path), uid=999 if os.geteuid() != 999 else 33, modes={0o400})
            self.assertEqual(str(caught.exception), "file_owner")
        with p.Snapshot() as snapshot:
            snapshot.open(str(path), uid=os.geteuid(), modes={0o400})
            path.chmod(0o600)
            with self.assertRaises(p.Refused) as caught:
                snapshot.finish()
            self.assertEqual(str(caught.exception), "file_changed")
        os.link(path, self.f.root / "hardlinked")
        with p.Snapshot() as snapshot:
            with self.assertRaises(p.Refused) as caught:
                snapshot.open(str(path), uid=os.geteuid(), modes={0o600})
            self.assertEqual(str(caught.exception), "file_links")

    def test_cli_and_openssl_failure_never_echo_supplied_material(self):
        sentinel = "SYNTHETIC_SENSITIVE_SENTINEL_DO_NOT_ECHO"
        stdout, stderr = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            code = p.main(["--unexpected", sentinel])
            self.denied("openssl", lambda: p.openssl(["pkey", "-noout"], data=sentinel.encode()))
        self.assertEqual(code, 1)
        self.assertNotIn(sentinel, stdout.getvalue() + stderr.getvalue())
        self.assertEqual(json.loads(stdout.getvalue())["reason"], "arguments")


if __name__ == "__main__":
    unittest.main(verbosity=1)
