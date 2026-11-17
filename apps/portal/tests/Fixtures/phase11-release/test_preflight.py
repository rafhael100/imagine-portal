#!/usr/bin/env python3
"""Bounded, offline negative cases. Docker/Git reads are injected only here."""
import hashlib
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
MODULE = pathlib.Path(__file__).resolve().parents[3] / "tools/performance-release/preflight.py"
spec = importlib.util.spec_from_file_location("release_preflight", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class Fixture:
    def __init__(self, root):
        self.portal, self.engine = root / "portal", root / "engine"
        self.portal.mkdir(); self.engine.mkdir()
        self.env = {"GITHUB_ACTIONS": "true", "ADMIN_PHASE11_RELEASE_CI": "1", "GITHUB_RUN_ID": "123", "GITHUB_RUN_ATTEMPT": "1"}
        self.project = "imagine-performance-p7-ci-123-1"
        self.sha = {self.portal: "a"*40, self.engine: "b"*40}
        self.dirty = ""
        self.context = "unix:///var/run/docker.sock"
        self.calls = []
        for checkout, files in [(self.portal, p.PORTAL_FILES | {"apps/portal/database/migrations/2026_11_16_000020_synthetic.php"}), (self.engine, p.ENGINE_FILES)]:
            for relative in files:
                path = checkout / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("synthetic controlled fixture\n")
        private = root / "private"
        private.mkdir()
        networks = {name: {"name": self.project+"_"+name, "internal": name != "ingress"} for name in p.NETWORKS}
        networks["service_link"]["ipam"] = {"config": [{"subnet": "10.87.6.0/28"}]}
        self.config = {"name": self.project, "networks": networks,
                       "volumes": {name: {"name": self.project+"_"+name} for name in p.VOLUMES}, "services": {}}
        service_networks = {"portal": ["portal_front", "portal_storage", "service_link"], "portal-web": ["ingress", "portal_front"],
                            "portal-db": ["portal_storage"], "receiver": ["performance_front", "performance_storage"],
                            "performance-web": ["performance_front", "service_link"], "performance-db": ["performance_storage"]}
        mounts = {
            "portal": [("/run/secrets", "bind"), ("/run/pki", "bind"), ("/var/www/html/storage", "portal_storage"), ("/var/www/html/bootstrap/cache", "portal_bootstrap")],
            "portal-web": [("/run/secrets", "bind"), ("/etc/nginx/nginx.conf", "bind")],
            "portal-db": [("/run/pg", "bind"), ("/var/lib/postgresql", "portal_pg")],
            "receiver": [("/run/private", "bind")],
            "performance-web": [("/etc/nginx/nginx.conf", "bind"), ("/run/tls", "bind")],
            "performance-db": [("/run/pg", "bind"), ("/var/lib/postgresql", "performance_pg")],
        }
        self.images = {}
        for index, name in enumerate(sorted(p.SERVICES)):
            reference = "fixture-"+name
            self.images[reference] = {"image_id": "sha256:"+str(index+1)*64, "repo_digests": [],
                                      "user": "101:101" if name == "portal-web" else ""}
            service = {"image": reference, "cap_drop": ["ALL"], "security_opt": ["no-new-privileges:true"], "restart": "no",
                       "read_only": name not in {"portal-db", "performance-db"}, "networks": {network: {} for network in service_networks[name]},
                       "volumes": [], "mem_limit": 128*1024*1024}
            if name != "portal-web":
                service["user"] = "999:999" if name.endswith("-db") else "101:101" if name == "performance-web" else "33:33"
            for target, source in mounts[name]:
                item = {"target": target, "type": "bind" if source == "bind" else "volume", "source": str(private) if source == "bind" else source}
                if source == "bind":
                    item["read_only"] = True
                if target == "/etc/nginx/nginx.conf":
                    item["source"] = str(self.portal / "apps/portal/deploy/phase6/portal-nginx-main.conf" if name == "portal-web" else self.engine / "deploy/private-admin/nginx.conf")
                service["volumes"].append(item)
            self.config["services"][name] = service
        self.config["services"]["portal-web"]["ports"] = [{"host_ip": "127.0.0.1", "published": "18947", "target": 8443, "protocol": "tcp"}]
        self.config["services"]["performance-web"]["networks"]["service_link"]["ipv4_address"] = "10.87.6.3"
        self.config["services"]["portal"]["environment"] = {"APP_ENV": "staging", "APP_DEBUG": "false", "PORTAL_ADMIN_PROVISION_ENABLED": "false",
                                                               "PERFORMANCE_ADMIN_MODE": "private_staging", "PERFORMANCE_PRIVATE_ADDRESS": "10.87.6.3"}
        self.manifest = {"schema": p.SCHEMA, "purpose": "ci-rehearsal", "portal_sha": self.sha[self.portal], "engine_sha": self.sha[self.engine],
                         "project": self.project, "files": {}, "rendered_compose_sha256": p.digest(self.config),
                         "images": {name: {"reference": service["image"], "image_id": self.images[service["image"]]["image_id"]} for name, service in self.config["services"].items()}}
        for name, checkout in [("portal", self.portal), ("engine", self.engine)]:
            self.manifest["files"][name] = {relative: hashlib.sha256((checkout / relative).read_bytes()).hexdigest() for relative in p.file_sets(self.portal, self.engine)[name]}
        self.path = root / "expected.json"
        self.write()

    def write(self):
        self.path.write_bytes(p.canonical(self.manifest))

    def runner(self, args, *, cwd, env, limit):
        self.calls.append(args)
        if args[0] == "git":
            if "--show-toplevel" in args: return str(cwd)+"\n"
            if "rev-parse" in args: return self.sha[cwd]+"\n"
            if "status" in args: return self.dirty
            if "ls-files" in args: return "\n".join(args[args.index("--")+1:])+"\n"
        if args == ["docker", "context", "show"]: return "default\n"
        if args[:3] == ["docker", "context", "inspect"]: return json.dumps(self.context)
        if args[:2] == ["docker", "compose"]:
            if args[-3:] != ["config", "--format", "json"]: raise AssertionError("mutation command refused")
            return json.dumps(self.config)
        if args[:3] == ["docker", "image", "inspect"]: return json.dumps(self.images[args[-1]])
        raise AssertionError("unexpected command")

    def verify(self, mode="ci-rehearsal"):
        return p.verify(str(self.path), str(self.portal), str(self.engine), mode, runner=self.runner, environ=self.env)

    def operational(self):
        self.manifest["purpose"] = "operational-candidate"
        self.manifest["project"] = self.config["name"] = "imagine-performance-p6-staging"
        for kind in ("volumes", "networks"):
            for name in self.config[kind]: self.config[kind][name]["name"] = self.manifest["project"]+"_"+name
        for name, expected in self.manifest["images"].items():
            old = expected["reference"]
            registry_digest = hashlib.sha256(("registry-"+name).encode()).hexdigest()
            reference = "example.invalid/"+name+"@sha256:"+registry_digest
            self.images[reference] = self.images.pop(old)
            self.images[reference]["repo_digests"] = [reference]
            expected["reference"] = self.config["services"][name]["image"] = reference
        self.manifest["rendered_compose_sha256"] = p.digest(self.config)
        self.write()


class PreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="phase11-release-")
        self.f = Fixture(pathlib.Path(self.temp.name))

    def tearDown(self): self.temp.cleanup()

    def denied(self, code, mode="ci-rehearsal"):
        with self.assertRaises(p.Refused) as caught: self.f.verify(mode)
        self.assertEqual(str(caught.exception), code)

    def test_positive_ci_keeps_expected_manifest_and_never_authorizes(self):
        before = self.f.path.read_bytes()
        result = self.f.verify()
        self.assertEqual(result["status"], "CANDIDATE_MATCH_OBSERVED")
        self.assertFalse(result["deployment_authorized"] or result["registry_release_published"] or result["build_provenance_verified"])
        self.assertEqual(self.f.path.read_bytes(), before)
        self.assertTrue(any(args[:3] == ["docker", "image", "inspect"] for args in self.f.calls))

    def test_positive_operational_format_with_injected_observation_is_not_approval(self):
        self.f.operational()
        result = self.f.verify("operational-candidate")
        self.assertEqual(result["image_reference_kind"], "registry_digest")
        self.assertFalse(result["deployment_authorized"] or result["human_approval_verified"])

    def test_operational_rejects_mutable_tag(self):
        self.f.operational()
        self.f.manifest["images"]["portal"]["reference"] = "portal:latest"
        self.f.write(); self.denied("image_reference", "operational-candidate")

    def test_operational_requires_exact_repo_digest(self):
        self.f.operational()
        self.f.images[self.f.manifest["images"]["portal"]["reference"]]["repo_digests"] = []
        self.denied("image_digest", "operational-candidate")

    def test_observed_image_id_cannot_change(self):
        self.f.images["fixture-portal"]["image_id"] = "sha256:"+"f"*64
        self.denied("image_identity")

    def test_portal_web_cannot_inherit_root_image_user(self):
        self.f.images["fixture-portal-web"]["user"] = "0:0"
        self.denied("isolation")
        self.f.images["fixture-portal-web"]["user"] = "101:101"
        self.f.config["services"]["portal-web"]["user"] = "0:0"
        self.f.manifest["rendered_compose_sha256"] = p.digest(self.f.config)
        self.f.write()
        self.denied("isolation")

    def test_sql_bytes_cannot_change(self):
        (self.f.engine / "contracts/admin/phase2/004-private-service-delegations.sql").write_text("changed")
        self.denied("file_digest")

    def test_source_sha_cannot_change(self):
        self.f.sha[self.f.engine] = "c"*40
        self.denied("source_sha")

    def test_tracked_or_untracked_application_source_is_refused(self):
        for value in (" M apps/portal/app/Example.php\n", "?? apps/portal/app/Injected.php\n"):
            self.f.dirty = value
            self.denied("source_dirty")

    def test_rendered_resource_change_is_refused(self):
        self.f.config["services"]["portal"]["mem_limit"] += 1
        self.denied("config_digest")

    def test_extra_service_is_refused(self):
        self.f.config["services"]["extra"] = {}
        self.denied("services")

    def test_other_engine_checkout_bind_is_refused(self):
        self.f.config["services"]["performance-web"]["volumes"][0]["source"] = str(self.f.portal / "apps/portal/deploy/phase6/portal-nginx-main.conf")
        self.denied("binding")

    def test_public_origin_listener_is_refused(self):
        self.f.config["services"]["portal-web"]["ports"][0]["host_ip"] = "0.0.0.0"
        self.denied("isolation")

    def test_remote_docker_environment_or_context_is_refused(self):
        for key, value in [("DOCKER_HOST", "ssh://unapproved.invalid"), ("DOCKER_TLS", "1")]:
            self.f.env[key] = value
            self.denied("context")
            del self.f.env[key]
        self.f.context = "tcp://unapproved.invalid:2375"
        self.denied("context")

    def test_duplicate_manifest_key_is_refused(self):
        self.f.path.write_text('{"schema":"one","schema":"two"}')
        self.denied("json")

    def test_critical_file_omission_is_refused(self):
        del self.f.manifest["files"]["portal"][p.COMPOSE]
        self.f.write(); self.denied("file_set")

    def test_ci_mode_requires_ci_guard(self):
        del self.f.env["ADMIN_PHASE11_RELEASE_CI"]
        self.denied("ci_only")

    def test_critical_file_symlink_is_refused(self):
        path = self.f.portal / p.COMPOSE
        path.unlink(); path.symlink_to(self.f.engine / "deploy/private-admin/nginx.conf")
        self.denied("path")


if __name__ == "__main__":
    unittest.main(verbosity=1)
