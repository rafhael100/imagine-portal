#!/usr/bin/env python3
"""Read-only host snapshot. Never reserves resources or authorizes installation."""
import argparse
import datetime
import hashlib
import ipaddress
import json
import os
import pathlib
import subprocess
import sys


PROJECT = "imagine-performance-p6-staging"
STAGE = "candidate"
PROTECTED_PORTS = {18081, 18090}
RFC1918 = tuple(ipaddress.ip_network(cidr) for cidr in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))
# Select fields in Docker itself. Never request Config.Env, commands, credentials,
# arbitrary labels, mount sources, or raw inspect output.
CONTAINER_FORMAT = ('{"id":{{json .Id}},"name":{{json .Name}},"image":{{json .Config.Image}},'
                    '"status":{{json .State.Status}},"started_at":{{json .State.StartedAt}},'
                    '"restart_count":{{json .RestartCount}},'
                    '"project":{{json (index .Config.Labels "com.docker.compose.project")}},'
                    '"configured_bindings":{{json .HostConfig.PortBindings}},'
                    '"active_bindings":{{json .NetworkSettings.Ports}}}')
NETWORK_FORMAT = ('{"id":{{json .Id}},"name":{{json .Name}},"internal":{{json .Internal}},'
                  '"ipam":{{json .IPAM.Config}},"endpoints":{{json .Containers}}}')
DOCKER_CONTEXT_FORMAT = "{{json .Endpoints.docker.Host}}"


def run(args):
    return subprocess.run(args, capture_output=True, text=True, timeout=15, check=True).stdout


def _docker_context():
    # Host listeners, routes and /proc below belong to this machine. Reject
    # inherited endpoint/TLS overrides before any Docker command can contact
    # a daemon, then inspect only the local context configuration.
    if any(os.environ.get(key) for key in ("DOCKER_HOST", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH")):
        raise ValueError("Local default Docker context required")
    if os.environ.get("DOCKER_CONTEXT", "default") not in ("", "default"):
        raise ValueError("Local default Docker context required")
    if run(["docker", "context", "show"]).strip() != "default":
        raise ValueError("Local default Docker context required")
    endpoint = json.loads(run(["docker", "context", "inspect", "default", "--format", DOCKER_CONTEXT_FORMAT]))
    if endpoint != "unix:///var/run/docker.sock":
        raise ValueError("Local default Docker socket required")


def _json_rows(raw):
    rows = [json.loads(line) for line in raw.splitlines() if line.strip()]
    if any(not isinstance(row, dict) for row in rows):
        raise ValueError("Malformed inventory")
    return rows


def _ids(args):
    ids = run(args).splitlines()
    if len(ids) != len(set(ids)) or any(len(value) != 64 or any(c not in "0123456789abcdef" for c in value) for value in ids):
        raise ValueError("Incomplete resource identities")
    return ids


def _bindings(raw):
    if raw is None:
        return []
    if not isinstance(raw, dict):
        raise ValueError("Malformed port bindings")
    bindings = []
    for target, values in raw.items():
        target_port, protocol = target.rsplit("/", 1)
        if not target_port.isdigit() or not 1 <= int(target_port) <= 65535 or protocol not in ("tcp", "udp", "sctp"):
            raise ValueError("Malformed port binding target")
        if values is None:
            continue
        if not isinstance(values, list):
            raise ValueError("Malformed port bindings")
        for value in values:
            address = value["HostIp"]
            host_port = value["HostPort"]
            if not isinstance(address, str) or not isinstance(host_port, str):
                raise ValueError("Malformed port binding address")
            if address:
                ipaddress.ip_address(address)
            # Empty/zero means dynamic allocation; active bindings record the
            # actual assignment. This snapshot cannot reserve a future port.
            if host_port in ("", "0"):
                continue
            if not host_port.isdigit() or not 1 <= int(host_port) <= 65535:
                raise ValueError("Malformed port binding number")
            bindings.append({"host_ip": address or "0.0.0.0", "host_port": int(host_port),
                             "container_port": int(target_port), "protocol": protocol})
    return sorted(bindings, key=lambda b: (b["host_port"], b["host_ip"], b["protocol"], b["container_port"]))


def _containers():
    ids = _ids(["docker", "ps", "--all", "--quiet", "--no-trunc"])
    if not ids:
        return []
    rows = _json_rows(run(["docker", "inspect", "--type", "container", "--format", CONTAINER_FORMAT, *ids]))
    if len(rows) != len(ids) or {row["id"] for row in rows} != set(ids):
        raise ValueError("Container inventory changed during read")
    result = []
    for row in rows:
        if not isinstance(row["restart_count"], int) or row["restart_count"] < 0:
            raise ValueError("Malformed restart counter")
        result.append({"id": row["id"], "name": row["name"].lstrip("/"), "image": row["image"],
                       "status": row["status"], "started_at": row["started_at"], "restart_count": row["restart_count"],
                       "project": row["project"], "configured_bindings": _bindings(row["configured_bindings"]),
                       "active_bindings": _bindings(row["active_bindings"])})
    return sorted(result, key=lambda c: c["id"])


def _networks():
    ids = _ids(["docker", "network", "ls", "--quiet", "--no-trunc"])
    if not ids:
        return []
    rows = _json_rows(run(["docker", "network", "inspect", "--format", NETWORK_FORMAT, *ids]))
    if len(rows) != len(ids) or {row["id"] for row in rows} != set(ids):
        raise ValueError("Network inventory changed during read")
    result = []
    for row in rows:
        subnets, gateways, endpoints = [], [], []
        for config in row["ipam"] or []:
            if config.get("Subnet"):
                subnets.append(str(ipaddress.ip_network(config["Subnet"], strict=False)))
            if config.get("Gateway"):
                gateways.append(str(ipaddress.ip_address(config["Gateway"])))
        for endpoint in (row["endpoints"] or {}).values():
            if endpoint.get("IPv4Address"):
                endpoints.append(str(ipaddress.ip_interface(endpoint["IPv4Address"]).ip))
        result.append({"id": row["id"], "name": row["name"], "internal": row["internal"],
                       "subnets": sorted(subnets), "gateways": sorted(gateways), "ipv4_endpoints": sorted(endpoints)})
    return sorted(result, key=lambda n: n["id"])


def _listeners():
    result = []
    for line in run(["ss", "-H", "-ltn"]).splitlines():
        columns = line.split()
        if len(columns) < 5 or columns[0] != "LISTEN":
            raise ValueError("Malformed listener inventory")
        address, port = columns[3].rsplit(":", 1)
        address = address.strip("[]")
        if address != "*":
            # iproute2 can annotate IPv4 listeners with an interface too
            # (e.g. systemd-resolved's 127.0.0.53%lo). Python only accepts
            # scoped literals for IPv6; validate the IP and interface separately.
            literal, separator, interface = address.partition("%")
            if separator and (not interface or any(not (c.isalnum() or c in "_.:@-") for c in interface)):
                raise ValueError("Malformed listener interface")
            ipaddress.ip_address(literal)
        if not port.isdigit() or not 1 <= int(port) <= 65535:
            raise ValueError("Malformed listener port")
        result.append({"host_ip": address, "host_port": int(port), "protocol": "tcp"})
    return result


def _host_resources():
    memory = {line.split(":")[0]: int(line.split()[1]) * 1024
              for line in pathlib.Path("/proc/meminfo").read_text().splitlines()
              if line.startswith(("MemTotal:", "MemAvailable:"))}
    if set(memory) != {"MemTotal", "MemAvailable"}:
        raise ValueError("Incomplete memory inventory")
    disk = os.statvfs("/srv")
    return {"host_fingerprint": hashlib.sha256(pathlib.Path("/etc/machine-id").read_bytes()).hexdigest()[:16],
            "cpu_count": os.cpu_count(), "memory_bytes": memory, "srv_available_bytes": disk.f_bavail * disk.f_frsize}


def inspect(port=18092, subnet="10.87.6.0/28", receiver_ip="10.87.6.3"):
    global STAGE
    STAGE = "candidate"
    if type(port) is not int or not 1 <= port <= 65535 or port in PROTECTED_PORTS:
        raise ValueError("Invalid or protected candidate port")
    proposal = ipaddress.ip_network(subnet, strict=True)
    if proposal.version != 4 or proposal.prefixlen > 29 or not any(proposal.subnet_of(private) for private in RFC1918):
        raise ValueError("RFC1918 candidate network required")
    receiver = ipaddress.ip_address(receiver_ip)
    gateway = proposal.network_address + 1
    if receiver.version != 4 or receiver not in proposal or receiver in (proposal.network_address, gateway, proposal.broadcast_address):
        raise ValueError("Receiver pin is not an available candidate host")

    STAGE = "docker_context"
    _docker_context()
    STAGE = "compose_projects"
    stacks = json.loads(run(["docker", "compose", "ls", "--all", "--format", "json"]))
    if not isinstance(stacks, list) or any(not isinstance(s, dict) or not isinstance(s.get("Name"), str) for s in stacks):
        raise ValueError("Malformed Compose inventory")
    STAGE = "containers"
    containers = _containers()
    STAGE = "networks"
    networks = _networks()
    STAGE = "routes"
    routes = json.loads(run(["ip", "-j", "-4", "route", "show", "table", "all"]))
    if not isinstance(routes, list) or any(not isinstance(route, dict) for route in routes):
        raise ValueError("Malformed route inventory")

    conflicts = []
    receiver_conflicts = []
    for network in networks:
        for cidr in network["subnets"]:
            current = ipaddress.ip_network(cidr)
            if current.version == 4 and proposal.overlaps(current):
                conflicts.append({"source": "docker_network", "name": network["name"], "subnet": str(current)})
        for field, kind in (("gateways", "gateway"), ("ipv4_endpoints", "endpoint")):
            if str(receiver) in network[field]:
                receiver_conflicts.append({"network": network["name"], "kind": kind, "ip": str(receiver)})
    for route in routes:
        if route.get("dst") not in (None, "default"):
            current = ipaddress.ip_network(route["dst"], strict=False)
            if current.version == 4 and proposal.overlaps(current):
                conflicts.append({"source": "host_route", "subnet": str(current), "table": str(route.get("table", "main"))})

    STAGE = "listeners"
    port_conflicts = [{"source": "kernel_listener", **listener} for listener in _listeners() if listener["host_port"] == port]
    for container in containers:
        for field in ("configured_bindings", "active_bindings"):
            for binding in container[field]:
                if binding["host_port"] == port and binding["protocol"] == "tcp":
                    port_conflicts.append({"source": field, "container_id": container["id"], "container": container["name"],
                                           "status": container["status"], **binding})

    STAGE = "host_resources"
    host = _host_resources()
    STAGE = "named_volumes"
    volumes = run(["docker", "volume", "ls", "-q"]).splitlines()
    return {"schema": "performance.phase6.host-readonly.v2", "at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            **host, "compose_projects": [{"name": s["Name"], "status": s.get("Status")} for s in stacks],
            "containers": containers, "networks": networks, "named_volumes": volumes,
            "proposal": {"project": PROJECT, "port": port, "port_observed_unused": not port_conflicts,
                         "port_conflicts": port_conflicts, "subnet": str(proposal), "receiver_ip": str(receiver),
                         "gateway_candidate": str(gateway), "receiver_ip_conflicts": receiver_conflicts,
                         "subnet_conflicts": sorted({c["subnet"] for c in conflicts}), "network_conflicts": conflicts,
                         "project_name_conflict": any(s["Name"] == PROJECT for s in stacks) or any(c["project"] == PROJECT for c in containers),
                         "reservation_made": False},
            "installation_authorized": False, "status": "NO_GO_PENDING_REVIEW_AND_APPROVAL"}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=18092)
    parser.add_argument("--subnet", default="10.87.6.0/28")
    parser.add_argument("--receiver-ip", default="10.87.6.3")
    args = parser.parse_args()
    try:
        print(json.dumps(inspect(args.port, args.subnet, args.receiver_ip), sort_keys=True))
    except Exception:
        # Fixed stage names aid diagnosis without exposing subprocess output,
        # environment, arbitrary paths, or exception messages.
        print(json.dumps({"status": "blocked", "error": "readonly_inventory_unavailable", "stage": STAGE, "installation_authorized": False}))
        sys.exit(2)
