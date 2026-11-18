#!/usr/bin/env python3
"""Real v2 collector projection over synthetic Docker responses; no host access."""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
DIRECTORY = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("inventory_exercise", DIRECTORY / "exercise_compare_inventory.py")
exercise = importlib.util.module_from_spec(spec)
spec.loader.exec_module(exercise)
p = exercise.p
spec = importlib.util.spec_from_file_location("inventory_collector", DIRECTORY.parents[2] / "deploy/phase6/readonly-inventory.py")
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)
ENV = {"GITHUB_ACTIONS": "true", "ADMIN_PHASE14_INVENTORY_CI": "1", "GITHUB_RUN_ID": "314", "GITHUB_RUN_ATTEMPT": "2"}


def identifier(value):
    return hashlib.sha256(value.encode()).hexdigest()


def synthetic_snapshots(project="imagine-performance-p7-ci-314-2"):
    containers = [
        {"id": "a" * 64, "name": "/existing-web-1", "image": "existing:observed", "status": "running",
         "started_at": "2026-11-17T10:00:00.123456789Z", "restart_count": 2, "project": "existing",
         "configured_bindings": {"8443/tcp": [{"HostIp": "127.0.0.1", "HostPort": "18081"}]}, "active_bindings": {}},
        {"id": "b" * 64, "name": "/unmanaged-worker", "image": "unmanaged:observed", "status": "running",
         "started_at": "2026-11-17T10:00:00Z", "restart_count": 0, "project": None, "configured_bindings": {}, "active_bindings": {}},
    ]
    networks = [
        {"id": "c" * 64, "name": "bridge", "internal": False, "ipam": [{"Subnet": "172.17.0.0/16", "Gateway": "172.17.0.1"}],
         "endpoints": {"b" * 64: {"IPv4Address": "172.17.0.2/16"}}},
        {"id": "d" * 64, "name": "existing_default", "internal": False, "ipam": [{"Subnet": "172.18.0.0/16", "Gateway": "172.18.0.1"}],
         "endpoints": {"a" * 64: {"IPv4Address": "172.18.0.2/16"}}},
    ]
    stacks = [{"Name": "existing", "Status": "running(1)"}]
    volumes = ["existing_data", "unmanaged_data"]
    listeners = "LISTEN 0 4096 127.0.0.1:18081 *:*\n"

    def command(args):
        if args == ["docker", "context", "show"]:
            return "default\n"
        if args == ["docker", "context", "inspect", "default", "--format", "{{json .Endpoints.docker.Host}}"]:
            return '"unix:///var/run/docker.sock"\n'
        if args == ["docker", "compose", "ls", "--all", "--format", "json"]:
            return json.dumps(stacks)
        if args == ["docker", "ps", "--all", "--quiet", "--no-trunc"]:
            return "\n".join(item["id"] for item in containers)
        if args == ["docker", "inspect", "--type", "container", "--format", collector.CONTAINER_FORMAT, *[item["id"] for item in containers]]:
            return "\n".join(json.dumps(item) for item in containers)
        if args == ["docker", "network", "ls", "--quiet", "--no-trunc"]:
            return "\n".join(item["id"] for item in networks)
        if args == ["docker", "network", "inspect", "--format", collector.NETWORK_FORMAT, *[item["id"] for item in networks]]:
            return "\n".join(json.dumps(item) for item in networks)
        if args == ["docker", "volume", "ls", "-q"]:
            return "\n".join(volumes)
        if args == ["ip", "-j", "-4", "route", "show", "table", "all"]:
            return '[{"dst":"default","gateway":"192.0.2.1"}]'
        if args == ["ss", "-H", "-ltn"]:
            return listeners
        raise AssertionError("unexpected collector command")

    host = {"host_fingerprint": "0123456789abcdef", "cpu_count": 8,
            "memory_bytes": {"MemTotal": 32 * 1024**3, "MemAvailable": 24 * 1024**3}, "srv_available_bytes": 100 * 1024**3}
    docker_environment = {name: "" for name in ("DOCKER_HOST", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH")}
    docker_environment["DOCKER_CONTEXT"] = "default"
    with patch.object(collector, "run", side_effect=command), patch.object(collector, "_host_resources", return_value=host), \
         patch.dict(os.environ, docker_environment):
        before = collector.inspect(18947, "10.87.6.0/28", "10.87.6.3")
        before["at"] = "2026-11-18T10:00:00+00:00"
        for service in exercise.SERVICES:
            bindings = {"8443/tcp": [{"HostIp": "127.0.0.1", "HostPort": "18947"}]} if service == "portal-web" else {}
            containers.append({"id": identifier(service), "name": "/" + project + "-" + service + "-1", "image": "fixture-" + service,
                               "status": "running", "started_at": "2026-11-18T10:00:30Z", "restart_count": 0, "project": project,
                               "configured_bindings": bindings, "active_bindings": bindings})
        for index, name in enumerate(exercise.NETWORKS):
            subnet = "10.87.6.0/28" if name == "service_link" else "10.88." + str(index) + ".0/24"
            gateway = "10.87.6.1" if name == "service_link" else "10.88." + str(index) + ".1"
            endpoints = {identifier("receiver"): {"IPv4Address": "10.87.6.3/28"}} if name == "service_link" else {}
            networks.append({"id": identifier("network-" + name), "name": project + "_" + name,
                             "internal": name != "ingress", "ipam": [{"Subnet": subnet, "Gateway": gateway}], "endpoints": endpoints})
        volumes.extend(project + "_" + name for name in exercise.VOLUMES)
        stacks.append({"Name": project, "Status": "running(6)"})
        listeners += "LISTEN 0 4096 127.0.0.1:18947 *:*\n"
        after = collector.inspect(18947, "10.87.6.0/28", "10.87.6.3")
        after["at"] = "2026-11-18T10:01:00+00:00"
    # Two serialized captures do not share the injected host-memory dict.
    return copy.deepcopy(before), copy.deepcopy(after)


class ComparisonTests(unittest.TestCase):
    def setUp(self):
        self.before, self.after = synthetic_snapshots()
        self.expected = exercise.expected_from_before(self.before, ENV)

    def compare(self, mode="ci-rehearsal", environ=None):
        return p.compare_values(self.before, self.after, self.expected, mode, ENV if environ is None else environ)

    def denied(self, reason, callback=None):
        with self.assertRaises(p.Refused) as caught:
            (self.compare if callback is None else callback)()
        self.assertEqual(str(caught.exception), reason)

    def changed(self, category, code):
        result = self.compare()
        self.assertEqual(result["status"], "DIFFERENCES_OBSERVED")
        self.assertTrue(any(item["category"] == category and item["code"] == code and item["count"] > 0 for item in result["differences"]))

    def test_collector_v2_positive_preserves_unmanaged_and_never_authorizes(self):
        result = self.compare()
        self.assertEqual(result["status"], "UNCHANGED_EXISTING_INVENTORY_OBSERVED")
        self.assertEqual(result["counts"]["containers"], {"before": 2, "after": 8, "added": 6})
        self.assertEqual(result["counts"]["networks"], {"before": 2, "after": 8, "added": 6})
        self.assertTrue(all(result[field] is False for field in p.base_result("ci-rehearsal", "x") if field.endswith(("_authorized", "_verified"))))
        self.assertFalse(self.after["proposal"]["port_observed_unused"])

    def test_operational_project_uses_same_collector_contract(self):
        self.before, self.after = synthetic_snapshots(p.PROJECT)
        self.expected = exercise.expected_from_before(self.before, ENV)
        self.expected.update(purpose="operational-candidate", project=p.PROJECT)
        self.expected["additions"] = {key: [name.replace("imagine-performance-p7-ci-314-2", p.PROJECT) for name in names]
                                      for key, names in self.expected["additions"].items()}
        self.assertEqual(self.compare("operational-candidate", {})["status"], "UNCHANGED_EXISTING_INVENTORY_OBSERVED")
        self.assertTrue(self.after["proposal"]["project_name_conflict"])

    def test_recreated_container_with_same_name_is_removed_and_unexpected(self):
        item = next(item for item in self.after["containers"] if item["id"] == "b" * 64)
        item["id"] = "e" * 64
        self.changed("containers", "removed")
        self.changed("containers", "unexpected_added")

    def test_all_observed_fields_of_existing_container_are_preserved(self):
        original = copy.deepcopy(self.after)
        changes = {"restart_count": 3, "started_at": "2026-11-18T10:00:31Z", "status": "paused", "image": "changed:tag",
                   "name": "renamed-unmanaged", "project": "other", "configured_bindings": [{"host_ip": "::", "host_port": 18082,
                   "container_port": 8443, "protocol": "tcp"}], "active_bindings": [{"host_ip": "127.0.0.1", "host_port": 18083,
                   "container_port": 8443, "protocol": "tcp"}]}
        for field, value in changes.items():
            self.after = copy.deepcopy(original)
            item = next(item for item in self.after["containers"] if item["id"] == "b" * 64)
            item[field] = value
            self.changed("containers", "changed")

    def test_extra_unmanaged_container_is_not_ignored(self):
        item = copy.deepcopy(next(item for item in self.after["containers"] if item["id"] == "b" * 64))
        item.update(id="e" * 64, name="unexpected-unmanaged")
        self.after["containers"].append(item)
        self.changed("containers", "unexpected_added")

    def test_expected_name_without_expected_project_is_refused(self):
        item = next(item for item in self.after["containers"] if item["name"].endswith("-receiver-1"))
        item["project"] = "unapproved"
        self.changed("containers", "wrong_project")

    def test_missing_expected_container_is_refused(self):
        self.after["containers"] = [item for item in self.after["containers"] if not item["name"].endswith("-receiver-1")]
        self.changed("containers", "expected_missing")

    def test_existing_network_recreated_or_endpoint_changed_is_refused(self):
        item = next(item for item in self.after["networks"] if item["id"] == "c" * 64)
        item["ipv4_endpoints"].append("172.17.0.3")
        self.changed("networks", "changed")
        item["id"] = "e" * 64
        self.changed("networks", "removed")

    def test_extra_network_or_volume_in_namespace_is_still_refused(self):
        item = copy.deepcopy(next(item for item in self.after["networks"] if item["id"] == "c" * 64))
        item.update(id="e" * 64, name=self.expected["project"] + "_unexpected")
        self.after["networks"].append(item)
        self.after["named_volumes"].append(self.expected["project"] + "_unexpected")
        self.changed("networks", "unexpected_added")
        self.changed("named_volumes", "unexpected_added")

    def test_preexisting_volume_and_compose_project_must_remain(self):
        self.after["named_volumes"].remove("unmanaged_data")
        self.after["compose_projects"] = [item for item in self.after["compose_projects"] if item["name"] != "existing"]
        self.changed("named_volumes", "removed")
        self.changed("compose_projects", "removed")

    def test_other_compose_project_is_not_an_allowed_addition(self):
        self.after["compose_projects"].append({"name": "unexpected", "status": "running(1)"})
        self.changed("compose_projects", "unexpected_added")

    def test_runtime_consumption_is_not_an_unapproved_capacity_policy(self):
        self.after["memory_bytes"]["MemAvailable"] -= 1024**3
        self.after["srv_available_bytes"] -= 1024**3
        self.assertEqual(self.compare()["status"], "UNCHANGED_EXISTING_INVENTORY_OBSERVED")

    def test_host_baseline_and_time_binding(self):
        original = copy.deepcopy(self.after)
        self.after["host_fingerprint"] = "fedcba9876543210"
        self.denied("host_identity")
        self.after = copy.deepcopy(original)
        self.after["at"] = self.before["at"]
        self.denied("time_order")
        self.after = original
        self.expected["before_sha256"] = "0" * 64
        self.denied("baseline_digest")

    def test_proposal_cannot_silently_switch(self):
        self.expected["proposal"]["port"] = 18948
        self.denied("proposal")

    def test_schema_rejects_uncollected_fields_and_incomplete_resources(self):
        self.after["containers"][0]["environment"] = {"SECRET": "SYNTHETIC_DO_NOT_ECHO"}
        self.denied("schema")
        del self.after["containers"][0]["environment"]
        del self.after["networks"][0]["internal"]
        self.denied("schema")

    def test_duplicate_resource_identity_and_nonfinite_json(self):
        self.after["containers"].append(copy.deepcopy(self.after["containers"][0]))
        self.denied("schema")
        for raw in (b'{"a":1,"a":2}', b'{"a":NaN}', b'{"a":Infinity}'):
            self.denied("json", lambda: p.parse_json(raw))

    def test_ci_guard_is_explicit_and_cannot_flip_to_operational(self):
        self.denied("ci_only", lambda: self.compare(environ={}))
        self.expected["purpose"] = "operational-candidate"
        self.denied("manifest", lambda: self.compare("operational-candidate", {}))

    def test_real_cli_fixture_and_input_redaction(self):
        with tempfile.TemporaryDirectory(prefix="phase14-input-") as directory:
            root = Path(directory)
            before, after = root / "before.json", root / "after.json"
            before.write_bytes(p.canonical(self.before))
            after.write_bytes(p.canonical(self.after))
            env = dict(os.environ, **ENV)
            completed = subprocess.run([sys.executable, "-B", str(DIRECTORY / "exercise_compare_inventory.py"),
                                       "--before", str(before), "--after", str(after)], stdin=subprocess.DEVNULL,
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20, env=env)
            self.assertEqual(completed.returncode, 0)
            result = json.loads(completed.stdout)
            self.assertEqual(result["status"], "INVENTORY_COMPARISON_EXERCISE_PASS")
            self.assertNotIn(b"unmanaged-worker", completed.stdout + completed.stderr)
            self.assertEqual(before.read_bytes(), p.canonical(self.before))
            linked = root / "linked.json"
            linked.symlink_to(before)
            self.denied("path", lambda: p.read_file(str(linked), p.MAX_SNAPSHOT))
            after.write_bytes(b"x" * (p.MAX_SNAPSHOT + 1))
            self.denied("input_limit", lambda: p.read_file(str(after), p.MAX_SNAPSHOT))

    def test_cli_argument_errors_are_fixed(self):
        out, error = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(error):
            code = p.main(["--secret=SYNTHETIC_DO_NOT_ECHO"])
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(out.getvalue())["reason"], "arguments")
        self.assertNotIn("SYNTHETIC_DO_NOT_ECHO", out.getvalue() + error.getvalue())


if __name__ == "__main__":
    unittest.main(verbosity=1)
