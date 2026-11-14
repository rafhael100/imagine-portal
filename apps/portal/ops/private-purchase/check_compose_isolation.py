#!/usr/bin/env python3
"""Fail-closed, read-only guard for the private purchase preview Compose topology.

Consumes docker compose config --format json, never starts containers and never
licenses a public reverse proxy or operational customer accounts.
"""
from __future__ import annotations

import argparse
import json
import sys


def check(config: dict) -> list[str]:
    errors: list[str] = []
    services = config.get("services", {})
    if set(services) != {"database", "application", "gateway"}:
        errors.append("unexpected service set")
        return errors
    networks = config.get("networks", {})
    private = networks.get("private", {})
    if private.get("internal") is not True:
        errors.append("private network must be internal")
    for name in ("database", "application"):
        svc = services[name]
        if svc.get("ports") or svc.get("expose") and name == "database":
            errors.append(f"{name} must not publish or expose database ports")
        if set(svc.get("networks", {})) != {"private"}:
            errors.append(f"{name} must connect only to private network")
        if svc.get("privileged") is True or svc.get("network_mode") == "host":
            errors.append(f"{name} cannot be privileged or use host networking")
        if "ALL" not in svc.get("cap_drop", []):
            errors.append(f"{name} must drop Linux capabilities")
    app = services["application"]
    env = app.get("environment", {})
    if isinstance(env, list):
        env = dict(item.split("=", 1) for item in env if "=" in item)
    for key, expected in {
        "APP_DEBUG": "false",
        "APP_ENV": "staging",
        "PORTAL_DEVELOPMENT_AUTH_ENABLED": "false",
        "PORTAL_PURCHASE_PREVIEW_ENABLED": "1",
    }.items():
        if str(env.get(key, "")).lower() != expected:
            errors.append(f"application {key} must remain {expected}")
    if app.get("read_only") is not True:
        errors.append("application filesystem must be read-only")
    gateway = services["gateway"]
    ports = gateway.get("ports", [])
    if len(ports) != 1:
        errors.append("gateway must have exactly one loopback port")
    else:
        port = ports[0]
        if (str(port.get("host_ip", "")) != "127.0.0.1"
                or str(port.get("published", "")) != "18083"
                or str(port.get("target", "")) != "8080"
                or port.get("protocol", "tcp") != "tcp"):
            errors.append("gateway published port must be 127.0.0.1:18083:8080/tcp")
    if set(gateway.get("networks", {})) != {"private", "host_access"}:
        errors.append("gateway networks must be private and host_access only")
    if gateway.get("read_only") is not True or "ALL" not in gateway.get("cap_drop", []):
        errors.append("gateway requires read-only root and dropped capabilities")
    for name in services:
        mounts = services[name].get("volumes", [])
        for mount in mounts:
            if isinstance(mount, dict) and mount.get("type") == "bind":
                source = str(mount.get("source", ""))
                if source in ("/", "/var/run/docker.sock") or source.startswith("/proc/"):
                    errors.append(f"{name} has forbidden host bind")
    return errors


def self_test() -> None:
    def service(**kwargs):
        return {"networks": {"private": None}, "cap_drop": ["ALL"], **kwargs}
    sample = {
        "services": {
            "database": service(),
            "application": service(read_only=True, environment={
                "APP_DEBUG": "false", "APP_ENV": "staging",
                "PORTAL_DEVELOPMENT_AUTH_ENABLED": "false",
                "PORTAL_PURCHASE_PREVIEW_ENABLED": "1"}),
            "gateway": service(read_only=True,
                networks={"private": None, "host_access": None},
                ports=[{"host_ip": "127.0.0.1", "published": "18083",
                        "target": 8080, "protocol": "tcp"}]),
        },
        "networks": {"private": {"internal": True}},
    }
    assert not check(sample), check(sample)
    for mutation in (
        lambda c: c["services"]["gateway"]["ports"][0].update(host_ip="0.0.0.0"),
        lambda c: c["services"]["application"]["environment"].update(APP_DEBUG="true"),
        lambda c: c["services"]["database"].update(ports=[{"published": "5432"}]),
        lambda c: c["networks"]["private"].update(internal=False),
        lambda c: c["services"]["application"].update(network_mode="host"),
    ):
        clone = json.loads(json.dumps(sample))
        mutation(clone)
        assert check(clone), "unsafe topology escaped the release gate"
    print("PASS offline topology gate rejects public ports, debug and network escapes")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--self-test", action="store_true")
    group.add_argument("--config-json", help="Read normalized docker compose JSON from this file")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    with open(args.config_json, encoding="utf-8") as source:
        config = json.load(source)
    errors = check(config)
    if errors:
        for error in errors:
            print("NO-GO:", error, file=sys.stderr)
        return 1
    print("PASS loopback-only topology; this is not authorization for deployment")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
