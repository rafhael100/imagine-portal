#!/usr/bin/env python3
"""Create one explicitly synthetic expectation, then exercise the real read-only CLI."""
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import sys

sys.dont_write_bytecode = True
MODULE = pathlib.Path(__file__).resolve().parents[3] / "tools/performance-release/preflight.py"
spec = importlib.util.spec_from_file_location("release_preflight", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


def main():
    env = dict(os.environ)
    p.need(env.get("GITHUB_ACTIONS") == "true" and env.get("ADMIN_PHASE11_RELEASE_CI") == "1", "ci_only")
    p.need(re.fullmatch(r"[0-9]+", env.get("GITHUB_RUN_ID", "")) and re.fullmatch(r"[0-9]+", env.get("GITHUB_RUN_ATTEMPT", "")), "ci_only")
    portal = p.local_root(env["GITHUB_WORKSPACE"])
    engine = p.local_root(env["P6_ENGINE_SOURCE"])
    p.need(engine == portal / "engine", "ci_only")
    project = "imagine-performance-p7-ci-"+env["GITHUB_RUN_ID"]+"-"+env["GITHUB_RUN_ATTEMPT"]
    p.need(env.get("P7_PROJECT") == project, "ci_only")
    base = p.local_root(env["RUNNER_TEMP"])
    private = base / ("phase11-release-"+env["GITHUB_RUN_ID"]+"-"+env["GITHUB_RUN_ATTEMPT"])
    evidence = base / "phase11-release-evidence"
    os.umask(0o077)
    private.mkdir(mode=0o700)
    expected_path = private / "synthetic-expected.json"
    try:
        p.check_context(p.run_read, portal, env)
        config = p.observe_config(project, portal, p.run_read, env)
        p.need(set(config.get("services", {})) == p.SERVICES, "services")
        observed = p.observe_images([service["image"] for service in config["services"].values()], portal, p.run_read, env)
        manifest = {"schema": p.SCHEMA, "purpose": "ci-rehearsal", "project": project,
                    "portal_sha": p.git_read(p.run_read, portal, ["rev-parse", "HEAD"], env),
                    "engine_sha": p.git_read(p.run_read, engine, ["rev-parse", "HEAD"], env),
                    "files": {}, "rendered_compose_sha256": p.digest(config),
                    "images": {name: {"reference": service["image"], "image_id": observed[service["image"]]["image_id"]}
                               for name, service in config["services"].items()}}
        for name, checkout in [("portal", portal), ("engine", engine)]:
            manifest["files"][name] = {relative: hashlib.sha256(p.read_file(checkout / relative)).hexdigest()
                                       for relative in p.file_sets(portal, engine)[name]}
        with expected_path.open("xb") as stream:
            stream.write(p.canonical(manifest))
        before = hashlib.sha256(expected_path.read_bytes()).hexdigest()
        # The CLI independently re-observes Git, the fixed Compose and images.
        # No expected state is regenerated inside the verifier.
        raw = p.run_read([sys.executable, str(MODULE), "--mode", "ci-rehearsal", "--manifest", str(expected_path),
                          "--portal-root", str(portal), "--engine-root", str(engine)], cwd=portal, env=env,
                         accepted_exit_codes=(0, 1))
        result = p.parse_json(raw)
        if result.get("status") == "CANDIDATE_REFUSED":
            reason = result.get("reason")
            raise p.Refused(reason if reason in p.ERRORS else "input")
        p.need(result.get("status") == "CANDIDATE_MATCH_OBSERVED" and result.get("deployment_authorized") is False
               and result.get("registry_release_published") is False and result.get("human_approval_verified") is False,
               "manifest")
        p.need(hashlib.sha256(expected_path.read_bytes()).hexdigest() == before, "manifest")
        checks = ["explicit_synthetic_expectation_created_once_outside_verifier", "expected_manifest_unchanged",
                  "fresh_default_local_docker_context_verified", "fresh_exact_six_service_compose_observed",
                  "critical_source_config_and_sql_hashes_match", "relevant_tracked_and_untracked_source_clean",
                  "already_available_image_ids_match", "ci_result_does_not_assert_registry_release_or_human_approval",
                  "deployment_authorization_remains_false"]
        evidence.mkdir(mode=0o700)
        (evidence / "checks.json").write_text(json.dumps({"schema": "imagine.performance.phase11.ci.v1", "checks": checks,
                                                         "preflight": result, "source": "synthetic_ci",
                                                         "live_vps_contacted": False, "new_resources_created_by_preflight": False}, indent=2)+"\n")
        print("PHASE11_RELEASE_PREFLIGHT_PASS: "+str(len(checks))+" checks; existing disposable CI images only")
    finally:
        if expected_path.is_file():
            expected_path.unlink()
        private.rmdir()


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, p.Refused) and str(error) in p.ERRORS else "input"
        print("PHASE11_RELEASE_PREFLIGHT_DENIED stage="+code, file=sys.stderr)
        sys.exit(1)
