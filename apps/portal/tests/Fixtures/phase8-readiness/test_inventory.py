"""Synthetic host conflicts; never inspect or mutate the runner's Docker host."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("inventory", Path(__file__).resolve().parents[3] / "deploy/phase6/readonly-inventory.py")
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)
CONTAINER = "a" * 64
NETWORK = "b" * 64


class InventoryTest(unittest.TestCase):
    def setUp(self):
        self.stacks = []
        self.containers = []
        self.networks = []
        self.routes = [{"dst": "default", "gateway": "192.0.2.1"}]
        self.listeners = ""
        self.commands = []
        self.run_mock = patch.object(inventory, "run", side_effect=self.command)
        self.host_mock = patch.object(inventory, "_host_resources", return_value={"host_fingerprint": "synthetic", "cpu_count": 2,
                                      "memory_bytes": {"MemTotal": 100, "MemAvailable": 50}, "srv_available_bytes": 1000})
        self.run_mock.start()
        self.host_mock.start()
        self.addCleanup(self.run_mock.stop)
        self.addCleanup(self.host_mock.stop)

    def command(self, args):
        self.commands.append(args)
        key = tuple(args)
        responses = {
            ("docker", "compose", "ls", "--all", "--format", "json"): json.dumps(self.stacks),
            ("docker", "ps", "--all", "--quiet", "--no-trunc"): "\n".join(c["id"] for c in self.containers),
            ("docker", "network", "ls", "--quiet", "--no-trunc"): "\n".join(n["id"] for n in self.networks),
            ("ip", "-j", "-4", "route", "show", "table", "all"): json.dumps(self.routes),
            ("ss", "-H", "-ltn"): self.listeners,
            ("docker", "volume", "ls", "-q"): "another_project_data\n",
        }
        if key in responses:
            return responses[key]
        if args == ["docker", "inspect", "--type", "container", "--format", inventory.CONTAINER_FORMAT, *[c["id"] for c in self.containers]]:
            return "\n".join(json.dumps(c) for c in self.containers)
        if args == ["docker", "network", "inspect", "--format", inventory.NETWORK_FORMAT, *[n["id"] for n in self.networks]]:
            return "\n".join(json.dumps(n) for n in self.networks)
        self.fail("Unexpected command; inventory must be read-only: " + repr(args))

    def container(self, **changes):
        data = {"id": CONTAINER, "name": "/other-web", "image": "other@sha256:synthetic", "status": "exited",
                "started_at": "2026-11-17T00:00:00Z", "restart_count": 3, "project": "other_project",
                "configured_bindings": {"443/tcp": [{"HostIp": "", "HostPort": "18092"}]}, "active_bindings": {}}
        data.update(changes)
        self.containers = [data]

    def network(self, **changes):
        data = {"id": NETWORK, "name": "other_network", "internal": False,
                "ipam": [{"Subnet": "10.87.6.0/24", "Gateway": "10.87.6.1"}],
                "endpoints": {CONTAINER: {"IPv4Address": "10.87.6.3/24", "Name": "other-web"}}}
        data.update(changes)
        self.networks = [data]

    def test_empty_snapshot_stays_no_go_and_never_reserves(self):
        result = inventory.inspect()
        self.assertTrue(result["proposal"]["port_observed_unused"])
        self.assertEqual(result["proposal"]["subnet_conflicts"], [])
        self.assertFalse(result["proposal"]["reservation_made"])
        self.assertFalse(result["installation_authorized"])
        self.assertEqual(result["status"], "NO_GO_PENDING_REVIEW_AND_APPROVAL")

    def test_stopped_container_declared_port_blocks_even_without_listener(self):
        self.container()
        result = inventory.inspect()
        self.assertFalse(result["proposal"]["port_observed_unused"])
        self.assertEqual(result["proposal"]["port_conflicts"][0]["source"], "configured_bindings")
        snapshot = result["containers"][0]
        self.assertEqual((snapshot["id"], snapshot["status"], snapshot["started_at"], snapshot["restart_count"]),
                         (CONTAINER, "exited", "2026-11-17T00:00:00Z", 3))

    def test_restarting_and_dynamic_active_port_are_not_missed(self):
        self.container(status="restarting", configured_bindings={"443/tcp": [{"HostIp": "", "HostPort": "0"}]},
                       active_bindings={"443/tcp": [{"HostIp": "::", "HostPort": "18092"}]})
        result = inventory.inspect()
        self.assertFalse(result["proposal"]["port_observed_unused"])
        self.assertEqual(result["proposal"]["port_conflicts"][0]["source"], "active_bindings")

    def test_ipv4_ipv6_and_wildcard_listeners_are_conservative_conflicts(self):
        for address in ("127.0.0.1", "0.0.0.0", "[::]", "[::1]", "*", "192.0.2.10"):
            with self.subTest(address=address):
                self.listeners = f"LISTEN 0 4096 {address}:18092 *:*\n"
                self.assertFalse(inventory.inspect()["proposal"]["port_observed_unused"])

    def test_udp_binding_does_not_claim_tcp_port(self):
        self.container(configured_bindings={"443/udp": [{"HostIp": "", "HostPort": "18092"}]})
        self.assertTrue(inventory.inspect()["proposal"]["port_observed_unused"])

    def test_iproute2_scoped_ipv4_and_ipv6_listeners_are_not_parse_failures(self):
        for address in ("127.0.0.53%lo", "[fe80::1%eth0]", "[::ffff:127.0.0.1%lo]"):
            self.listeners = f"LISTEN 0 4096 {address}:18092 *:*\n"
            with self.subTest(address=address):
                self.assertFalse(inventory.inspect()["proposal"]["port_observed_unused"])

    def test_stopped_projects_and_orphan_label_both_detect_project_collision(self):
        self.stacks = [{"Name": inventory.PROJECT, "Status": "exited(6)", "ConfigFiles": "not emitted"}]
        self.assertTrue(inventory.inspect()["proposal"]["project_name_conflict"])
        self.stacks = []
        self.container(project=inventory.PROJECT)
        self.assertTrue(inventory.inspect()["proposal"]["project_name_conflict"])

    def test_network_route_and_receiver_endpoint_conflicts_reported(self):
        self.network()
        self.routes.append({"dst": "10.87.0.0/16", "table": 200})
        result = inventory.inspect()["proposal"]
        self.assertEqual(result["subnet_conflicts"], ["10.87.0.0/16", "10.87.6.0/24"])
        self.assertEqual({c["source"] for c in result["network_conflicts"]}, {"docker_network", "host_route"})
        self.assertEqual(result["receiver_ip_conflicts"], [{"network": "other_network", "kind": "endpoint", "ip": "10.87.6.3"}])

    def test_existing_gateway_cannot_be_used_as_receiver(self):
        self.network(ipam=[{"Subnet": "10.87.6.0/24", "Gateway": "10.87.6.3"}], endpoints={})
        self.assertEqual(inventory.inspect()["proposal"]["receiver_ip_conflicts"][0]["kind"], "gateway")

    def test_invalid_candidates_fail_before_any_host_command(self):
        cases = [{"port": p} for p in (True, 0, -1, 65536, 18081, 18090, "18092")]
        cases += [{"subnet": n} for n in ("127.0.0.0/8", "169.254.0.0/16", "192.0.2.0/24", "100.64.0.0/10", "10.0.0.0/7", "fc00::/7", "10.87.6.1/28")]
        cases += [{"receiver_ip": ip} for ip in ("10.87.6.0", "10.87.6.1", "10.87.6.15", "10.87.7.3", "::1")]
        cases += [{"subnet": "10.87.6.0/30", "receiver_ip": "10.87.6.2"}]
        for case in cases:
            with self.subTest(case=case), self.assertRaises(ValueError):
                inventory.inspect(**case)
        self.assertEqual(self.commands, [])

    def test_read_projections_never_expose_environment_or_arbitrary_labels(self):
        self.container(unrequested_secret="sentinel-do-not-emit")
        self.network()
        output = json.dumps(inventory.inspect())
        self.assertNotIn("sentinel-do-not-emit", output)
        self.assertNotIn("Config.Env", " ".join(" ".join(args) for args in self.commands))
        self.assertNotIn("{{json .}}", " ".join(" ".join(args) for args in self.commands))

    def test_missing_resources_malformed_output_and_command_errors_fail_closed(self):
        for bad in ("not-json", "{}", "null"):
            with self.subTest(bad=bad), patch.object(inventory, "run", return_value=bad), self.assertRaises(ValueError):
                inventory.inspect()
        with patch.object(inventory, "run", side_effect=subprocess.CalledProcessError(1, ["docker"])), self.assertRaises(subprocess.CalledProcessError):
            inventory.inspect()
        self.container()
        original = self.command
        def missing(args):
            return "" if args[:2] == ["docker", "inspect"] else original(args)
        with patch.object(inventory, "run", side_effect=missing), self.assertRaisesRegex(ValueError, "changed during read"):
            inventory.inspect()

    def test_malformed_listener_cannot_be_silently_declared_unused(self):
        for line in ("bad output", "LISTEN 0 4096 localhost:18092 *:*", "LISTEN 0 4096 [::]:unknown *:*", "LISTEN 0 4096 127.0.0.53%:18092 *:*"):
            self.listeners = line
            with self.subTest(line=line), self.assertRaises(ValueError):
                inventory.inspect()


if __name__ == "__main__":
    unittest.main(verbosity=2)
