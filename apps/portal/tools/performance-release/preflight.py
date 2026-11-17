#!/usr/bin/env python3
"""Compare an explicit expected candidate with fresh local, read-only observations."""
import argparse
import hashlib
import ipaddress
import json
import os
import pathlib
import re
import selectors
import stat
import subprocess
import sys
import time


SCHEMA = "imagine.performance.release.candidate.v1"
RESULT_SCHEMA = "imagine.performance.release.preflight.v1"
COMPOSE = "apps/portal/deploy/phase6/compose.yaml"
SERVICES = {"portal", "portal-web", "portal-db", "receiver", "performance-web", "performance-db"}
NETWORKS = {"ingress", "portal_front", "portal_storage", "performance_front", "performance_storage", "service_link"}
VOLUMES = {"portal_storage", "portal_bootstrap", "portal_pg", "performance_pg"}
HEX40 = re.compile(r"[a-f0-9]{40}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
IMAGE_ID = re.compile(r"sha256:[a-f0-9]{64}\Z")
IMAGE_REF = re.compile(r"[a-z0-9][a-z0-9._:/-]{0,254}(?:@sha256:[a-f0-9]{64})?\Z")
REGISTRY_REF = re.compile(r"[a-z0-9][a-z0-9._:/-]{0,254}@sha256:[a-f0-9]{64}\Z")
PORTAL_FILES = {
    COMPOSE, "apps/portal/deploy/phase6/portal-nginx-main.conf",
    "apps/portal/deploy/staging/Dockerfile.php", "apps/portal/deploy/staging/Dockerfile.nginx",
    "apps/portal/deploy/staging/grant-runtime.sql", "apps/portal/.dockerignore",
    "apps/portal/composer.json", "apps/portal/composer.lock",
}
ENGINE_FILES = {
    "deploy/private-admin/Dockerfile", "deploy/private-admin/nginx.conf", "deploy/private-admin/read.php",
    "contracts/admin/phase2/002-service-inbox.sql", "contracts/admin/phase2/003-service-inbox-native-rls.sql",
    "contracts/admin/phase2/004-private-service-delegations.sql",
}
IMAGE_PROJECTION = '{"image_id":{{json .Id}},"repo_digests":{{json .RepoDigests}},"user":{{json .Config.User}}}'
MAX_INPUT = 1024 * 1024
MAX_FILE = 4 * 1024 * 1024
MAX_COMMAND = 4 * 1024 * 1024
ERRORS = {
    "arguments", "input", "json", "manifest", "mode", "ci_only", "path", "source_sha", "source_dirty",
    "source_tracking", "file_set", "file_digest", "context", "command", "command_limit", "config",
    "config_digest", "services", "isolation", "binding", "image_reference", "image_identity", "image_digest",
}


class Refused(Exception):
    pass


def need(condition, code):
    if not condition:
        raise Refused(code)


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


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


def read_file(path, limit=MAX_FILE):
    try:
        path = pathlib.Path(path)
        need(path.is_absolute() and path.resolve(strict=True) == path, "path")
        before = path.lstat()
        need(stat.S_ISREG(before.st_mode) and before.st_size <= limit, "input")
        with path.open("rb") as stream:
            opened = os.fstat(stream.fileno())
            raw = stream.read(limit + 1)
            after = os.fstat(stream.fileno())
        fields = ("st_dev", "st_ino", "st_mode", "st_size", "st_mtime_ns", "st_ctime_ns")
        need(len(raw) <= limit and all(getattr(before, field) == getattr(opened, field) ==
             getattr(after, field) == getattr(path.lstat(), field) for field in fields), "input")
        return raw
    except (OSError, RuntimeError):
        raise Refused("input") from None


def local_root(value):
    path = pathlib.Path(value)
    try:
        need(path.is_absolute() and path.resolve(strict=True) == path and path.is_dir(), "path")
    except (OSError, RuntimeError):
        raise Refused("path") from None
    return path


def run_read(args, *, cwd, env, limit=MAX_COMMAND, accepted_exit_codes=(0,)):
    """No shell, stdin, stderr output, network request, mutation or unbounded capture."""
    try:
        process = subprocess.Popen(args, cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    except OSError:
        raise Refused("command") from None
    selector = selectors.DefaultSelector()
    output = bytearray()
    deadline = time.monotonic() + 20
    try:
        selector.register(process.stdout, selectors.EVENT_READ)
        while selector.get_map():
            remaining = deadline - time.monotonic()
            need(remaining > 0, "command_limit")
            for key, _ in selector.select(min(remaining, 0.5)):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                need(len(output) + len(chunk) <= limit, "command_limit")
                output.extend(chunk)
        need(process.wait(timeout=max(0.01, deadline-time.monotonic())) in accepted_exit_codes, "command")
        return bytes(output).decode("utf-8", "strict")
    except (OSError, UnicodeError, subprocess.TimeoutExpired):
        raise Refused("command") from None
    finally:
        selector.close()
        if process.poll() is None:
            process.kill()
            process.wait()
        if process.stdout is not None:
            process.stdout.close()


def file_sets(portal, engine):
    migrations = portal / "apps/portal/database/migrations"
    paths = sorted(migrations.glob("*.php"))
    need(1 <= len(paths) <= 128, "file_set")
    need(all(re.fullmatch(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php", path.name) for path in paths), "file_set")
    return {"portal": sorted(PORTAL_FILES | {str(path.relative_to(portal)) for path in paths}),
            "engine": sorted(ENGINE_FILES)}


def validate_manifest(manifest, mode):
    need(isinstance(manifest, dict) and set(manifest) == {
        "schema", "purpose", "portal_sha", "engine_sha", "files", "project",
        "rendered_compose_sha256", "images"}, "manifest")
    need(manifest["schema"] == SCHEMA and manifest["purpose"] == mode, "mode")
    need(mode in {"ci-rehearsal", "operational-candidate"}, "mode")
    for field in ("portal_sha", "engine_sha"):
        need(isinstance(manifest[field], str) and HEX40.fullmatch(manifest[field]), "manifest")
    need(isinstance(manifest["rendered_compose_sha256"], str) and HEX64.fullmatch(manifest["rendered_compose_sha256"]), "manifest")
    need(isinstance(manifest["files"], dict) and set(manifest["files"]) == {"portal", "engine"}, "manifest")
    for group in manifest["files"].values():
        need(isinstance(group, dict) and 1 <= len(group) <= 160, "manifest")
        need(all(isinstance(path, str) and len(path) <= 256 and isinstance(value, str) and HEX64.fullmatch(value)
                 for path, value in group.items()), "manifest")
    need(isinstance(manifest["project"], str), "manifest")
    if mode == "ci-rehearsal":
        need(re.fullmatch(r"imagine-performance-p7-ci-[0-9]+-[0-9]+", manifest["project"]), "manifest")
    else:
        need(manifest["project"] == "imagine-performance-p6-staging", "manifest")
    need(isinstance(manifest["images"], dict) and set(manifest["images"]) == SERVICES, "manifest")
    for image in manifest["images"].values():
        need(isinstance(image, dict) and set(image) == {"reference", "image_id"}, "manifest")
        need(isinstance(image["reference"], str) and IMAGE_REF.fullmatch(image["reference"]), "image_reference")
        need(isinstance(image["image_id"], str) and IMAGE_ID.fullmatch(image["image_id"]), "manifest")
        if mode == "operational-candidate":
            need(REGISTRY_REF.fullmatch(image["reference"]), "image_reference")


def git_read(runner, root, args, env):
    return runner(["git", "--no-optional-locks", "-c", "core.fsmonitor=false", "-c", "core.untrackedCache=false", *args],
                  cwd=root, env=env, limit=256 * 1024).strip()


def check_sources(manifest, portal, engine, runner, env):
    expected_sets = file_sets(portal, engine)
    for name, root, scope in [("portal", portal, ["apps/portal"]),
                              ("engine", engine, ["src", "deploy/private-admin", "contracts/admin/phase2"])]:
        need(git_read(runner, root, ["rev-parse", "--show-toplevel"], env) == str(root), "path")
        need(git_read(runner, root, ["rev-parse", "HEAD"], env) == manifest[name+"_sha"], "source_sha")
        need(not git_read(runner, root, ["status", "--porcelain=v1", "--untracked-files=all", "--", *scope], env), "source_dirty")
        need(set(manifest["files"][name]) == set(expected_sets[name]), "file_set")
        tracked = git_read(runner, root, ["ls-files", "--", *expected_sets[name]], env).splitlines()
        need(set(tracked) == set(expected_sets[name]), "source_tracking")
        for relative in expected_sets[name]:
            actual = hashlib.sha256(read_file(root / relative)).hexdigest()
            need(actual == manifest["files"][name][relative], "file_digest")


def check_context(runner, root, env):
    need(not any(env.get(key) for key in ["DOCKER_HOST", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"]), "context")
    need(env.get("DOCKER_CONTEXT", "default") in ("", "default"), "context")
    need(runner(["docker", "context", "show"], cwd=root, env=env, limit=4096).strip() == "default", "context")
    endpoint = parse_json(runner(["docker", "context", "inspect", "default", "--format", "{{json .Endpoints.docker.Host}}"],
                                 cwd=root, env=env, limit=4096))
    need(endpoint == "unix:///var/run/docker.sock", "context")


def observe_config(project, portal, runner, env):
    raw = runner(["docker", "compose", "--project-directory", str(portal / "apps/portal/deploy/phase6"),
                  "--env-file", "/dev/null", "-p", project, "-f", str(portal / COMPOSE), "config", "--format", "json"],
                 cwd=portal, env=env, limit=MAX_COMMAND)
    config = parse_json(raw)
    need(isinstance(config, dict), "config")
    return config


def check_config(config, manifest, portal, engine):
    need(config.get("name") == manifest["project"], "config")
    services = config.get("services")
    need(isinstance(services, dict) and set(services) == SERVICES, "services")
    need(isinstance(config.get("networks"), dict) and set(config["networks"]) == NETWORKS, "isolation")
    need(isinstance(config.get("volumes"), dict) and set(config["volumes"]) == VOLUMES, "isolation")
    for kind in ("networks", "volumes"):
        for name, definition in config[kind].items():
            need(isinstance(definition, dict) and definition.get("name") == manifest["project"]+"_"+name,
                 "isolation")
            need(not definition.get("external") and not definition.get("driver_opts") and
                 definition.get("driver", "bridge" if kind == "networks" else "local") == ("bridge" if kind == "networks" else "local"), "isolation")
    for name, definition in config["networks"].items():
        need(bool(definition.get("internal", False)) == (name != "ingress"), "isolation")
    subnet_config = config["networks"]["service_link"].get("ipam", {}).get("config", [])
    need(isinstance(subnet_config, list) and len(subnet_config) == 1, "isolation")
    try:
        subnet = ipaddress.ip_network(subnet_config[0]["subnet"], strict=True)
        need(subnet.version == 4 and subnet.prefixlen <= 29 and any(subnet.subnet_of(ipaddress.ip_network(cidr))
             for cidr in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")), "isolation")
    except (ValueError, KeyError, TypeError):
        raise Refused("isolation") from None
    required_networks = {"portal": {"portal_front", "portal_storage", "service_link"},
                         "portal-web": {"ingress", "portal_front"}, "portal-db": {"portal_storage"},
                         "receiver": {"performance_front", "performance_storage"},
                         "performance-web": {"performance_front", "service_link"}, "performance-db": {"performance_storage"}}
    users = {"portal": "33:33", "receiver": "33:33", "performance-web": "101:101",
             "portal-db": "999:999", "performance-db": "999:999"}
    targets = {
        "portal": {"/run/secrets": "bind", "/run/pki": "bind", "/var/www/html/storage": "portal_storage", "/var/www/html/bootstrap/cache": "portal_bootstrap"},
        "portal-web": {"/run/secrets": "bind", "/etc/nginx/nginx.conf": "bind"},
        "portal-db": {"/run/pg": "bind", "/var/lib/postgresql": "portal_pg"},
        "receiver": {"/run/private": "bind"},
        "performance-web": {"/etc/nginx/nginx.conf": "bind", "/run/tls": "bind"},
        "performance-db": {"/run/pg": "bind", "/var/lib/postgresql": "performance_pg"},
    }
    for name, service in services.items():
        need(isinstance(service, dict) and service.get("image") == manifest["images"][name]["reference"], "image_reference")
        need(not any(service.get(key) for key in ("build", "privileged", "network_mode", "pid", "ipc", "devices", "cap_add", "volumes_from")), "isolation")
        need(service.get("cap_drop") == ["ALL"] and service.get("security_opt") == ["no-new-privileges:true"], "isolation")
        need(bool(service.get("read_only", False)) == (name not in {"portal-db", "performance-db"}) and service.get("restart") == "no", "isolation")
        need(set(service.get("networks", {})) == required_networks[name], "isolation")
        if name in users:
            need(service.get("user") == users[name], "isolation")
        elif service.get("user"):
            need(service["user"] == "101:101", "isolation")
        mounts = service.get("volumes", [])
        need(isinstance(mounts, list) and len(mounts) == len(targets[name]), "binding")
        need(all(isinstance(mount, dict) for mount in mounts) and {mount.get("target") for mount in mounts} == set(targets[name]), "binding")
        for mount in mounts:
            source = mount.get("source")
            expected_type = targets[name][mount["target"]]
            if expected_type == "bind":
                need(mount.get("type") == "bind" and mount.get("read_only") is True and isinstance(source, str) and source.startswith("/"), "binding")
                if mount["target"] == "/etc/nginx/nginx.conf":
                    expected = portal / "apps/portal/deploy/phase6/portal-nginx-main.conf" if name == "portal-web" else engine / "deploy/private-admin/nginx.conf"
                    need(source == str(expected), "binding")
                else:
                    need(pathlib.Path(source).is_dir() and pathlib.Path(source).resolve() == pathlib.Path(source), "binding")
            else:
                need(mount.get("type") == "volume" and source == expected_type and not mount.get("read_only"), "binding")
        ports = service.get("ports", [])
        if name == "portal-web":
            need(isinstance(ports, list) and len(ports) == 1 and ports[0].get("host_ip") == "127.0.0.1" and
                 ports[0].get("target") == 8443 and ports[0].get("protocol", "tcp") == "tcp", "isolation")
            published = str(ports[0].get("published", ""))
            need(published.isdigit() and 1024 <= int(published) <= 65535 and int(published) not in {18081, 18090}, "isolation")
        else:
            need(not ports, "isolation")
    portal_env = services["portal"].get("environment", {})
    need(portal_env.get("APP_ENV") == "staging" and portal_env.get("APP_DEBUG") == "false" and
         portal_env.get("PORTAL_ADMIN_PROVISION_ENABLED") == "false" and portal_env.get("PERFORMANCE_ADMIN_MODE") == "private_staging", "config")
    try:
        receiver = ipaddress.ip_address(services["performance-web"]["networks"]["service_link"]["ipv4_address"])
        need(receiver in subnet and receiver not in {subnet.network_address, subnet.broadcast_address, subnet.network_address+1}
             and str(receiver) == portal_env.get("PERFORMANCE_PRIVATE_ADDRESS"), "isolation")
    except (ValueError, KeyError, TypeError):
        raise Refused("isolation") from None
    need(digest(config) == manifest["rendered_compose_sha256"], "config_digest")


def observe_images(references, root, runner, env):
    result = {}
    for reference in sorted(set(references)):
        need(isinstance(reference, str) and IMAGE_REF.fullmatch(reference), "image_reference")
        observed = parse_json(runner(["docker", "image", "inspect", "--format", IMAGE_PROJECTION, reference],
                                    cwd=root, env=env, limit=65536))
        need(isinstance(observed, dict) and set(observed) == {"image_id", "repo_digests", "user"}, "image_identity")
        need(isinstance(observed["image_id"], str) and IMAGE_ID.fullmatch(observed["image_id"]), "image_identity")
        need(isinstance(observed["user"], str) and len(observed["user"]) <= 64, "image_identity")
        values = observed["repo_digests"] or []
        need(isinstance(values, list) and len(values) <= 64 and all(isinstance(value, str) and len(value) <= 512 for value in values), "image_digest")
        result[reference] = {"image_id": observed["image_id"], "repo_digests": values, "user": observed["user"]}
    return result


def verify(manifest_path, portal_root, engine_root, mode, *, runner=run_read, environ=None):
    env = dict(os.environ if environ is None else environ)
    manifest = parse_json(read_file(pathlib.Path(manifest_path), MAX_INPUT))
    validate_manifest(manifest, mode)
    if mode == "ci-rehearsal":
        need(env.get("GITHUB_ACTIONS") == "true" and env.get("ADMIN_PHASE11_RELEASE_CI") == "1", "ci_only")
        need(manifest["project"] == "imagine-performance-p7-ci-"+env.get("GITHUB_RUN_ID", "")+"-"+env.get("GITHUB_RUN_ATTEMPT", ""), "ci_only")
    portal, engine = local_root(portal_root), local_root(engine_root)
    check_sources(manifest, portal, engine, runner, env)
    check_context(runner, portal, env)
    config = observe_config(manifest["project"], portal, runner, env)
    check_config(config, manifest, portal, engine)
    images = observe_images([image["reference"] for image in manifest["images"].values()], portal, runner, env)
    for expected in manifest["images"].values():
        observed = images[expected["reference"]]
        need(observed["image_id"] == expected["image_id"], "image_identity")
        if mode == "operational-candidate":
            need(expected["reference"] in observed["repo_digests"], "image_digest")
    # The Portal web Dockerfile supplies USER 101:101. Other service identities
    # are explicit Compose overrides and must not be inferred from base images.
    web_user = (config["services"]["portal-web"].get("user") or
                images[manifest["images"]["portal-web"]["reference"]]["user"])
    need(web_user == "101:101", "isolation")
    # Re-observe source state/config after image inspection; this is still a
    # bounded observation, never a reservation or distributed atomic snapshot.
    check_sources(manifest, portal, engine, runner, env)
    need(digest(observe_config(manifest["project"], portal, runner, env)) == manifest["rendered_compose_sha256"], "config_digest")
    return {"schema": RESULT_SCHEMA, "status": "CANDIDATE_MATCH_OBSERVED", "mode": mode,
            "expected_manifest_sha256": digest(manifest), "portal_sha": manifest["portal_sha"], "engine_sha": manifest["engine_sha"],
            "rendered_compose_sha256": manifest["rendered_compose_sha256"], "service_count": len(SERVICES),
            "critical_file_count": sum(len(group) for group in manifest["files"].values()),
            "image_reference_kind": "local_ci_rehearsal" if mode == "ci-rehearsal" else "registry_digest",
            "registry_release_published": False, "build_provenance_verified": False,
            "human_approval_verified": False, "host_readiness_verified": False, "deployment_authorized": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=["ci-rehearsal", "operational-candidate"], required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--portal-root", required=True)
    parser.add_argument("--engine-root", required=True)
    args = parser.parse_args()
    try:
        result = verify(args.manifest, args.portal_root, args.engine_root, args.mode)
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception as error:
        code = str(error) if isinstance(error, Refused) and str(error) in ERRORS else "input"
        print(json.dumps({"schema": RESULT_SCHEMA, "status": "CANDIDATE_REFUSED", "reason": code,
                          "deployment_authorized": False, "host_readiness_verified": False}, sort_keys=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
