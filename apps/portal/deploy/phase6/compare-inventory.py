#!/usr/bin/env python3
"""Compare bounded local inventory v2 snapshots; never authorize installation."""
import argparse
import datetime
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import sys


SNAPSHOT_SCHEMA = "performance.phase6.host-readonly.v2"
EXPECTED_SCHEMA = "imagine.performance.inventory.expected.v1"
RESULT_SCHEMA = "imagine.performance.inventory.comparison.v1"
PROJECT = "imagine-performance-p6-staging"
MODES = {"ci-rehearsal", "operational-candidate"}
MAX_SNAPSHOT = 4 * 1024 * 1024
MAX_MANIFEST = 64 * 1024
MAX_RESOURCES = 1024
HEX16 = re.compile(r"[a-f0-9]{16}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
NAME = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,254}\Z")
PROJECT_NAME = re.compile(r"[a-z0-9][a-z0-9_-]{0,127}\Z")
IMAGE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_./:@-]{0,511}\Z")
STATES = {"created", "running", "paused", "restarting", "removing", "exited", "dead"}
PROPOSAL_FIELDS = {"project", "port", "subnet", "receiver_ip", "gateway_candidate"}
CATEGORIES = ("compose_projects", "containers", "networks", "named_volumes")
RFC1918 = tuple(ipaddress.ip_network(value) for value in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))
STAMP = ("st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns")
ERRORS = {"arguments", "input", "input_limit", "input_changed", "path", "json", "schema", "manifest", "mode",
          "ci_only", "baseline_digest", "host_identity", "proposal", "time_order", "existing_target"}


class Refused(Exception):
    pass


def need(condition, code):
    if not condition:
        raise Refused(code)


def fields(value, expected, code="schema"):
    need(isinstance(value, dict) and set(value) == set(expected), code)


def integer(value, minimum=0, maximum=2**63 - 1):
    return type(value) is int and minimum <= value <= maximum


def matches(value, pattern):
    return isinstance(value, str) and pattern.fullmatch(value) is not None


def array(value, maximum=MAX_RESOURCES):
    need(isinstance(value, list) and len(value) <= maximum, "schema")


def strings(value, pattern=NAME, *, minimum=0, maximum=MAX_RESOURCES, code="schema"):
    need(isinstance(value, list) and minimum <= len(value) <= maximum
         and all(matches(item, pattern) for item in value) and len(set(value)) == len(value), code)


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=True, separators=(",", ":")).encode("ascii")


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


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


def stamp(metadata):
    return tuple(getattr(metadata, key) for key in STAMP)


def read_file(value, limit):
    need(isinstance(value, str) and 1 <= len(value) <= 4096 and "\x00" not in value, "path")
    path = Path(value)
    try:
        need(path.is_absolute() and str(path) == value and path.resolve(strict=True) == path, "path")
        before = path.lstat()
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, "input")
        need(0 < before.st_size <= limit, "input_limit")
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            need(stamp(before) == stamp(os.fstat(fd)), "input_changed")
            output = bytearray()
            while True:
                part = os.read(fd, min(65536, limit + 1 - len(output)))
                if not part:
                    break
                output.extend(part)
                need(len(output) <= limit, "input_limit")
            need(len(output) == before.st_size and stamp(before) == stamp(os.fstat(fd))
                 and stamp(before) == stamp(path.lstat()), "input_changed")
        finally:
            os.close(fd)
        return parse_json(bytes(output)), (path, stamp(before))
    except (OSError, RuntimeError):
        raise Refused("input") from None


def unchanged_inputs(records):
    try:
        for path, before in records:
            need(path.resolve(strict=True) == path and before == stamp(path.lstat()), "input_changed")
    except (OSError, RuntimeError):
        raise Refused("input_changed") from None


def timestamp(value, *, nanoseconds=False):
    fraction = "9" if nanoseconds else "6"
    pattern = r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1," + fraction + r"})?(?:Z|\+00:00)\Z"
    need(isinstance(value, str) and re.fullmatch(pattern, value), "schema")
    try:
        return datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise Refused("schema") from None


def ip(value, *, version=None, canonical_form=False, listener=False):
    need(isinstance(value, str) and 1 <= len(value) <= 128, "schema")
    if listener and value == "*":
        return None
    literal = value
    if listener and "%" in value:
        literal, separator, interface = value.partition("%")
        need(separator and re.fullmatch(r"[A-Za-z0-9_.:@-]{1,64}", interface), "schema")
    try:
        address = ipaddress.ip_address(literal)
        need(version is None or address.version == version, "schema")
        need(not canonical_form or str(address) == value, "schema")
        return address
    except ValueError:
        raise Refused("schema") from None


def network(value):
    need(isinstance(value, str) and len(value) <= 128, "schema")
    try:
        subnet = ipaddress.ip_network(value, strict=True)
        need(str(subnet) == value, "schema")
        return subnet
    except ValueError:
        raise Refused("schema") from None


def binding(value):
    fields(value, {"host_ip", "host_port", "container_port", "protocol"})
    ip(value["host_ip"])
    need(integer(value["host_port"], 1, 65535) and integer(value["container_port"], 1, 65535)
         and value["protocol"] in {"tcp", "udp", "sctp"}, "schema")


def static_proposal(value):
    fields(value, PROPOSAL_FIELDS, "proposal")
    need(value["project"] == PROJECT and integer(value["port"], 1, 65535) and value["port"] not in {18081, 18090}, "proposal")
    subnet = network(value["subnet"])
    need(subnet.version == 4 and subnet.prefixlen <= 29 and any(subnet.subnet_of(parent) for parent in RFC1918), "proposal")
    receiver = ip(value["receiver_ip"], version=4, canonical_form=True)
    gateway = ip(value["gateway_candidate"], version=4, canonical_form=True)
    need(gateway == subnet.network_address + 1 and receiver in subnet
         and receiver not in (subnet.network_address, gateway, subnet.broadcast_address), "proposal")
    return subnet


def keyed(rows, key):
    result = {}
    for row in rows:
        need(row[key] not in result, "schema")
        result[row[key]] = row
    return result


def validate_snapshot(value):
    fields(value, {"schema", "at", "host_fingerprint", "cpu_count", "memory_bytes", "srv_available_bytes", "compose_projects",
                   "containers", "networks", "named_volumes", "proposal", "installation_authorized", "status"})
    need(value["schema"] == SNAPSHOT_SCHEMA and value["status"] == "NO_GO_PENDING_REVIEW_AND_APPROVAL"
         and value["installation_authorized"] is False, "schema")
    at = timestamp(value["at"])
    need(matches(value["host_fingerprint"], HEX16) and integer(value["cpu_count"], 1, 65536)
         and integer(value["srv_available_bytes"]), "schema")
    fields(value["memory_bytes"], {"MemTotal", "MemAvailable"})
    memory = value["memory_bytes"]
    need(integer(memory["MemTotal"], 1) and integer(memory["MemAvailable"], 0, memory["MemTotal"]), "schema")
    for category in ("compose_projects", "containers", "networks"):
        array(value[category])
    for item in value["compose_projects"]:
        fields(item, {"name", "status"})
        need(matches(item["name"], PROJECT_NAME) and (item["status"] is None
             or isinstance(item["status"], str) and re.fullmatch(r"[ -~]{1,256}", item["status"])), "schema")
    projects = keyed(value["compose_projects"], "name")
    for item in value["containers"]:
        fields(item, {"id", "name", "image", "status", "started_at", "restart_count", "project", "configured_bindings", "active_bindings"})
        need(matches(item["id"], HEX64) and matches(item["name"], NAME) and matches(item["image"], IMAGE)
             and isinstance(item["status"], str) and item["status"] in STATES and integer(item["restart_count"])
             and (item["project"] is None or item["project"] == "" or matches(item["project"], PROJECT_NAME)), "schema")
        timestamp(item["started_at"], nanoseconds=True)
        for name in ("configured_bindings", "active_bindings"):
            array(item[name], 256)
            for entry in item[name]:
                binding(entry)
    containers = keyed(value["containers"], "id")
    keyed(value["containers"], "name")
    for item in value["networks"]:
        fields(item, {"id", "name", "internal", "subnets", "gateways", "ipv4_endpoints"})
        need(matches(item["id"], HEX64) and matches(item["name"], NAME) and type(item["internal"]) is bool, "schema")
        for name, maximum in (("subnets", 128), ("gateways", 128), ("ipv4_endpoints", 4096)):
            array(item[name], maximum)
        for subnet in item["subnets"]:
            network(subnet)
        for address in item["gateways"]:
            ip(address, canonical_form=True)
        for address in item["ipv4_endpoints"]:
            ip(address, version=4, canonical_form=True)
    networks = keyed(value["networks"], "id")
    network_names = keyed(value["networks"], "name")
    strings(value["named_volumes"], maximum=4096)
    proposal = value["proposal"]
    fields(proposal, PROPOSAL_FIELDS | {"port_observed_unused", "port_conflicts", "receiver_ip_conflicts", "subnet_conflicts",
                                       "network_conflicts", "project_name_conflict", "reservation_made"})
    subnet = static_proposal({key: proposal[key] for key in PROPOSAL_FIELDS})
    need(type(proposal["port_observed_unused"]) is bool and type(proposal["project_name_conflict"]) is bool
         and proposal["reservation_made"] is False, "schema")
    for name in ("port_conflicts", "receiver_ip_conflicts", "subnet_conflicts", "network_conflicts"):
        array(proposal[name], 4096)
    for conflict in proposal["port_conflicts"]:
        need(isinstance(conflict, dict), "schema")
        if conflict.get("source") == "kernel_listener":
            fields(conflict, {"source", "host_ip", "host_port", "protocol"})
            ip(conflict["host_ip"], listener=True)
        else:
            fields(conflict, {"source", "container_id", "container", "status", "host_ip", "host_port", "container_port", "protocol"})
            need(conflict["source"] in {"configured_bindings", "active_bindings"}
                 and matches(conflict["container_id"], HEX64) and conflict["container_id"] in containers, "schema")
            item = containers[conflict["container_id"]]
            record = {key: conflict[key] for key in ("host_ip", "host_port", "container_port", "protocol")}
            binding(record)
            need(item["name"] == conflict["container"] and item["status"] == conflict["status"]
                 and record in item[conflict["source"]], "schema")
        need(type(conflict["host_port"]) is int and conflict["host_port"] == proposal["port"] and conflict["protocol"] == "tcp", "schema")
    need(proposal["port_observed_unused"] == (len(proposal["port_conflicts"]) == 0), "schema")
    for conflict in proposal["receiver_ip_conflicts"]:
        fields(conflict, {"network", "kind", "ip"})
        need(matches(conflict["network"], NAME) and conflict["network"] in network_names
             and conflict["kind"] in {"gateway", "endpoint"} and conflict["ip"] == proposal["receiver_ip"], "schema")
        actual = network_names[conflict["network"]]
        need(conflict["ip"] in actual["gateways" if conflict["kind"] == "gateway" else "ipv4_endpoints"], "schema")
    for conflict in proposal["network_conflicts"]:
        need(isinstance(conflict, dict), "schema")
        if conflict.get("source") == "docker_network":
            fields(conflict, {"source", "name", "subnet"})
            need(matches(conflict["name"], NAME) and conflict["name"] in network_names
                 and conflict["subnet"] in network_names[conflict["name"]]["subnets"], "schema")
        else:
            fields(conflict, {"source", "subnet", "table"})
            need(conflict["source"] == "host_route" and isinstance(conflict["table"], str)
                 and re.fullmatch(r"[ -~]{1,128}", conflict["table"]), "schema")
        current = network(conflict["subnet"])
        need(current.version == 4 and current.overlaps(subnet), "schema")
    need(proposal["subnet_conflicts"] == sorted({item["subnet"] for item in proposal["network_conflicts"]}), "schema")
    need(proposal["project_name_conflict"] == (PROJECT in projects or any(item["project"] == PROJECT for item in containers.values())), "schema")
    return {"at": at, "compose_projects": projects, "containers": containers, "networks": networks,
            "named_volumes": {name: name for name in value["named_volumes"]}}


def validate_manifest(value, mode, environ):
    fields(value, {"schema", "purpose", "project", "host_fingerprint", "before_sha256", "proposal", "additions"}, "manifest")
    need(mode in MODES and value["schema"] == EXPECTED_SCHEMA and value["purpose"] == mode, "mode")
    if mode == "ci-rehearsal":
        run, attempt = environ.get("GITHUB_RUN_ID", ""), environ.get("GITHUB_RUN_ATTEMPT", "")
        need(environ.get("GITHUB_ACTIONS") == "true" and environ.get("ADMIN_PHASE14_INVENTORY_CI") == "1"
             and re.fullmatch(r"[0-9]{1,20}", run) and re.fullmatch(r"[0-9]{1,10}", attempt)
             and value["project"] == "imagine-performance-p7-ci-" + run + "-" + attempt, "ci_only")
    else:
        need(value["project"] == PROJECT, "manifest")
    need(matches(value["host_fingerprint"], HEX16) and matches(value["before_sha256"], HEX64), "manifest")
    static_proposal(value["proposal"])
    fields(value["additions"], {"containers", "networks", "named_volumes"}, "manifest")
    for category, names in value["additions"].items():
        strings(names, minimum=1, maximum=32, code="manifest")
        separator = "-" if category == "containers" else "_"
        need(all(name.startswith(value["project"] + separator) and len(name) > len(value["project"]) + 1 for name in names), "manifest")


def base_result(mode, status):
    return {"schema": RESULT_SCHEMA, "mode": mode if mode in MODES else "invalid", "status": status,
            "installation_authorized": False, "deployment_authorized": False, "human_approval_verified": False,
            "input_authenticity_verified": False, "continuous_availability_verified": False,
            "volume_contents_verified": False, "volume_identity_verified": False, "uncollected_configuration_verified": False,
            "network_volume_ownership_verified": False}


def compare_values(before, after, expected, mode, environ):
    validate_manifest(expected, mode, environ)
    old, new = validate_snapshot(before), validate_snapshot(after)
    need(digest(before) == expected["before_sha256"], "baseline_digest")
    need(before["host_fingerprint"] == after["host_fingerprint"] == expected["host_fingerprint"], "host_identity")
    need({key: before["proposal"][key] for key in PROPOSAL_FIELDS} == expected["proposal"]
         and {key: after["proposal"][key] for key in PROPOSAL_FIELDS} == expected["proposal"], "proposal")
    need(old["at"] < new["at"], "time_order")
    project = expected["project"]
    need(project not in old["compose_projects"] and not any(item["project"] == project for item in old["containers"].values()), "existing_target")
    for category in ("containers", "networks", "named_volumes"):
        names = set(old[category]) if category == "named_volumes" else {item["name"] for item in old[category].values()}
        need(not names.intersection(expected["additions"][category]), "existing_target")
    differences, counts = [], {}
    def difference(category, code, count):
        if count:
            differences.append({"category": category, "code": code, "count": count})
    for category in CATEGORIES:
        original, current = old[category], new[category]
        removed, added = set(original) - set(current), set(current) - set(original)
        counts[category] = {"before": len(original), "after": len(current), "added": len(added)}
        difference(category, "removed", len(removed))
        difference(category, "changed", sum(original[key] != current[key] for key in set(original) & set(current)))
        observed = set(added) if category in {"compose_projects", "named_volumes"} else {current[key]["name"] for key in added}
        wanted = {project} if category == "compose_projects" else set(expected["additions"][category])
        difference(category, "unexpected_added", len(observed - wanted))
        difference(category, "expected_missing", len(wanted - observed))
        if category == "containers":
            difference(category, "wrong_project", sum(current[key]["project"] != project for key in added))
    result = base_result(mode, "DIFFERENCES_OBSERVED" if differences else "UNCHANGED_EXISTING_INVENTORY_OBSERVED")
    result.update({"counts": counts, "differences": differences, "synthetic_rehearsal": mode == "ci-rehearsal",
                   "before_sha256": digest(before), "after_sha256": digest(after), "expected_manifest_sha256": digest(expected)})
    return result


def compare(before_path, after_path, manifest_path, mode, *, environ=None):
    expected, expected_record = read_file(manifest_path, MAX_MANIFEST)
    before, before_record = read_file(before_path, MAX_SNAPSHOT)
    after, after_record = read_file(after_path, MAX_SNAPSHOT)
    result = compare_values(before, after, expected, mode, os.environ if environ is None else environ)
    unchanged_inputs((expected_record, before_record, after_record))
    return result


class Parser(argparse.ArgumentParser):
    def error(self, _message):
        raise Refused("arguments")


def main(argv=None):
    mode = "invalid"
    try:
        parser = Parser(description=__doc__, allow_abbrev=False)
        parser.add_argument("--mode", required=True, choices=sorted(MODES))
        for name in ("before", "after", "manifest"):
            parser.add_argument("--" + name, required=True)
        args = parser.parse_args(argv)
        mode = args.mode
        result = compare(args.before, args.after, args.manifest, mode)
        code = 0 if result["status"] == "UNCHANGED_EXISTING_INVENTORY_OBSERVED" else 1
    except (Refused, OSError, ValueError, TypeError, KeyError, OverflowError, RecursionError) as error:
        result = base_result(mode, "REFUSED")
        result["reason"] = str(error) if isinstance(error, Refused) and str(error) in ERRORS else "input"
        code = 1
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return code


if __name__ == "__main__":
    sys.exit(main())
