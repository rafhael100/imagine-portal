"""Ephemeral CI enrollment; never operational PKI or a host installer."""
import hashlib
import json
import os
from pathlib import Path
import runpy
import secrets
import ssl
import time

assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("ADMIN_PHASE7_CI") == "1"
assert os.environ.get("ADMIN_PHASE6_SMOKE_CI") == "1"
runpy.run_path("apps/portal/tests/Fixtures/phase6/materials.py")
p = Path(os.environ["P6_CI_MATERIAL"])

def write(directory, name, value, uid=33):
    target = p / directory / name
    target.write_text(value)
    os.chown(target, uid, uid)
    target.chmod(0o400)

for name in ("portal-bootstrap", "receiver-bootstrap"):
    target = p / name
    target.mkdir(mode=0o700)
    os.chown(target, 33, 33)

companies = ["aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"]
secret = secrets.token_hex(32)
expiry = int(time.time()) + 1800
identity = {"issuer": "portal_service", "key_id": "phase7_key_01", "active": True,
            "secret": secret, "expires_at": expiry, "companies": companies,
            "operations": ["dashboard.read"]}
write("portal-secrets", "portal_service_identity", json.dumps(identity))
write("receiver-private", "service-keyring.json", json.dumps({"portal_service": {
    "phase7_key_01": {"active": True, "secret": secret, "expires_at": expiry}}}))
fingerprint = hashlib.sha256(ssl.PEM_cert_to_DER_cert((p / "client.pem").read_text())).hexdigest()
write("receiver-bootstrap", "certificate-sha256", fingerprint)
for target, source in [("portal-bootstrap", "portal-pg"), ("receiver-bootstrap", "performance-pg")]:
    write(target, "owner-password", (p / source / "bootstrap_password").read_text())
# Existing mountpoint under the read-only secrets directory. This harmless
# marker is replaced by the owner file only on the disposable preparation run.
write("portal-secrets", "phase7_owner_password", "CI_PLACEHOLDER_NOT_AN_OWNER_PASSWORD")

# Only this disposable one-shot receives owner passwords. The regular services
# never mount either bootstrap directory or this browser vault.
vault = {"users": [{"email": f"phase7-{index}@example.test", "password": secrets.token_hex(24),
                    "secret": secrets.token_hex(20), "company": company}
                   for index, company in enumerate(companies)]}
access = (p / "curl-auth").read_text().strip().split('"')[1].split(":", 1)
vault["httpCredentials"] = {"username": access[0], "password": access[1]}
write("portal-bootstrap", "browser-vault.json", json.dumps(vault))

