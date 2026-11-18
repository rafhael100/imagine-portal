#!/usr/bin/env python3
"""Root-only disposable CI rehearsal, using the existing Phase7 generator output."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
import expectations

MODULE = Path(__file__).resolve().parents[3] / "tools/performance-release/materials_preflight.py"
spec = importlib.util.spec_from_file_location("materials_preflight", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)
STAGE = "guard"


def write(path, raw):
    before = path.stat()
    path.chmod(0o600)
    path.write_bytes(raw)
    os.chown(path, before.st_uid, before.st_gid)
    path.chmod(stat.S_IMODE(before.st_mode))


def manifest_file(directory, manifest):
    path = directory / "expected.json"
    path.write_text(json.dumps(manifest, sort_keys=True))
    path.chmod(0o600)
    return path


def assert_denied(path, expected, *, mode="ci-rehearsal", environ=None):
    try:
        p.verify(str(path), mode, environ=os.environ if environ is None else environ)
    except p.Refused as error:
        assert str(error) in expected
    else:
        raise AssertionError("expected fixed refusal")


def clone(source, destination):
    for name in expectations.DIRECTORIES:
        src, dest = source / name, destination / name
        dest.mkdir(mode=0o700)
        uid = src.stat().st_uid
        os.chown(dest, uid, uid)
        for item in src.iterdir():
            target = dest / item.name
            shutil.copyfile(item, target)
            metadata = item.stat()
            os.chown(target, metadata.st_uid, metadata.st_gid)
            target.chmod(stat.S_IMODE(metadata.st_mode))
    shutil.copyfile(source / "ca.pem", destination / "ca.pem")
    (destination / "ca.pem").chmod(0o644)


def run():
    global STAGE
    expectations.guard(os.environ)
    assert os.geteuid() == 0
    source = p.canonical_path(os.environ["P6_CI_MATERIAL"])
    assert source.name == "phase7-" + os.environ["GITHUB_RUN_ID"] + "-" + os.environ["GITHUB_RUN_ATTEMPT"]
    before = {str(path): path.stat() for name in expectations.DIRECTORIES for path in [source / name, *(source / name).iterdir()]}
    passed = []
    STAGE = "baseline"
    with tempfile.TemporaryDirectory(prefix="phase12-materials-ci-") as temporary:
        work = Path(temporary)
        manifest = expectations.expected(source, os.environ)
        original = manifest_file(work, manifest)
        original_bytes = original.read_bytes()
        outcome = p.verify(str(original), "ci-rehearsal")
        assert outcome["status"] == "MATERIALS_MATCH_OBSERVED" and outcome["synthetic_rehearsal"] is True
        assert all(outcome[key] is False for key in ("deployment_authorized", "human_approval_verified", "custody_verified",
                   "sql_grants_verified", "live_tls_verified", "nominal_enrollment_verified"))
        assert original.read_bytes() == original_bytes
        passed.append("original_generated_package_and_no_authorization")

        def exercise(name, mutate, reasons):
            global STAGE
            STAGE = name
            with tempfile.TemporaryDirectory(prefix="case-", dir=work) as case_dir:
                case = Path(case_dir)
                clone(source, case)
                expected = copy.deepcopy(manifest)
                expected["directories"] = {name: str(case / name) for name in expectations.DIRECTORIES}
                expected["browser_ca_file"] = str(case / "ca.pem")
                mutate(case, expected)
                path = manifest_file(case, expected)
                assert_denied(path, reasons)
                passed.append(name)

        def absent(root, _):
            (root / "portal-pki/portal_client.key").unlink()
        exercise("missing_required_key", absent, {"file_set"})

        def extra_owner(root, _):
            target = root / "receiver-private/owner-password"
            target.write_text("SYNTHETIC_UNEXPECTED_OWNER_FILE")
            os.chown(target, 33, 33)
            target.chmod(0o400)
        exercise("extra_runtime_owner_file", extra_owner, {"file_set"})

        def link(root, _):
            target = root / "portal-pki/portal_client.key"
            target.unlink()
            target.symlink_to(root / "receiver-tls/server.key")
        exercise("symbolic_link", link, {"path"})

        def hardlink(root, _):
            os.link(root / "portal-pki/portal_client.key", root / "linked-key")
        exercise("hard_link", hardlink, {"file_links"})

        def fifo(root, _):
            target = root / "portal-pki/portal_client.key"
            target.unlink()
            os.mkfifo(target, 0o400)
            os.chown(target, 33, 33)
        exercise("special_file_nonblocking_refusal", fifo, {"file_type"})

        exercise("private_mode", lambda root, _: (root / "portal-secrets/portal_app_key").chmod(0o644), {"file_mode"})
        exercise("wrong_uid", lambda root, _: os.chown(root / "portal-pki/portal_client.key", 101, 33), {"file_owner"})
        exercise("wrong_gid", lambda root, _: os.chown(root / "portal-pki/portal_client.key", 33, 101), {"file_owner"})
        exercise("directory_mode", lambda root, _: (root / "portal-pki").chmod(0o755), {"file_mode"})
        exercise("inert_placeholder_only", lambda root, _: write(root / "portal-secrets/phase7_owner_password", b"SYNTHETIC_ACTIVE_OWNER_NOT_ALLOWED"), {"credential"})

        def mismatch(root, _):
            write(root / "portal-pki/portal_client.key", (root / "receiver-tls/server.key").read_bytes())
        exercise("certificate_key_pair", mismatch, {"key_pair"})
        exercise("public_fingerprint", lambda _, expected: expected["certificates"]["browser"].update(chain_sha256=["0" * 64]), {"public_pin"})
        exercise("explicit_san", lambda _, expected: expected["certificates"]["service_client"].update(dns_sans=["different-client"]), {"san"})

        def keyring_secret(root, _):
            path = root / "receiver-private/service-keyring.json"
            value = json.loads(path.read_bytes())
            value["portal_service"]["phase7_key_01"]["secret"] = "SYNTHETIC_DIFFERENT_SHARED_SECRET_0123456789"
            write(path, json.dumps(value).encode())
        exercise("s2s_secret_coherence", keyring_secret, {"identity"})
        exercise("scope_expected_by_owner", lambda _, expected: expected["identity"].update(companies=["cccccccc-cccc-4ccc-8ccc-cccccccccccc"]), {"identity"})

        def duplicate(root, _):
            write(root / "receiver-private/service-keyring.json", b'{"portal_service":{},"portal_service":{}}')
        exercise("duplicate_json_key", duplicate, {"json"})

        def reused(root, _):
            write(root / "portal-secrets/portal_authority_password", (root / "portal-secrets/portal_db_password").read_bytes())
        exercise("distinct_sql_credentials", reused, {"credential_reuse"})
        exercise("plaintext_basic_auth", lambda root, _: write(root / "web-pki/access_passwords", b"smoke:SYNTHETIC_PLAINTEXT_PASSWORD\n"), {"basic_auth"})
        exercise("hostssl_only", lambda root, _: write(root / "portal-pg/pg_hba.conf", b"local all all scram-sha-256\nhost all all 0.0.0.0/0 scram-sha-256\n"), {"hba"})
        exercise("no_trust_hba", lambda root, _: write(root / "portal-pg/pg_hba.conf", b"local all all trust\nhostssl all all 0.0.0.0/0 scram-sha-256\n"), {"hba"})
        exercise("horizon_cannot_extend_identity", lambda _, expected: expected.update(valid_until_epoch=expected["identity"]["expires_at"] + 1), {"horizon"})

        STAGE = "ci_guard"
        environment = dict(os.environ)
        environment.pop("ADMIN_PHASE12_MATERIALS_CI", None)
        assert_denied(original, {"ci_only"}, environ=environment)
        passed.append(STAGE)
        STAGE = "mode_flip_is_not_operational"
        changed = copy.deepcopy(manifest)
        changed["purpose"] = "operational-candidate"
        changed["project"] = "imagine-performance-p6-staging"
        assert_denied(manifest_file(work, changed), {"synthetic"}, mode="operational-candidate")
        passed.append(STAGE)
        STAGE = "no_material_changes"
        assert all(p.same_stat(metadata, Path(path).stat()) for path, metadata in before.items())
        passed.append(STAGE)
    return passed


if __name__ == "__main__":
    report = {"schema": "performance.phase12.materials.exercise.v1", "source": "synthetic",
              "deployment_authorized": False, "custody_verified": False, "sql_grants_verified": False,
              "nominal_enrollment_verified": False, "vps_modified": False}
    try:
        checks = run()
        report.update(status="MATERIALS_EXERCISE_PASS", checks=checks, checks_count=len(checks))
        code = 0
    except Exception as error:
        # Fixture failures must not serialize exceptions containing material.
        report.update(status="MATERIALS_EXERCISE_REFUSED", stage=STAGE,
                      reason=str(error) if isinstance(error, p.Refused) and str(error) in p.ERRORS else "exercise")
        code = 1
    print(json.dumps(report, sort_keys=True, separators=(",", ":")))
    sys.exit(code)
