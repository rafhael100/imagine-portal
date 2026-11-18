#!/usr/bin/env python3
"""Synthetic, owned context canaries: prepare -> existing build/runtime -> verify -> cleanup.

Run only after the build receipt capture and remove canaries before its verify.
prepare rolls back its own partial creations; a global cleanup hook is enabled
only after prepare succeeds. State is private and separate from build metadata.
"""
import argparse
import hashlib
import importlib.util
import os
import pathlib
import re
import stat
import sys

sys.dont_write_bytecode = True
MODULE = pathlib.Path(__file__).resolve().parents[3] / "tools/performance-release/preflight.py"
spec = importlib.util.spec_from_file_location("context_preflight", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)

SCHEMA = "imagine.performance.build.context.canaries.v1"
STATE_SCHEMA = "imagine.performance.build.context.ownership.v1"
IDENTITY = {"dev", "ino", "mode", "uid", "gid"}
FILE_IDENTITY = IDENTITY | {"size", "sha256"}
PROJECTION = ('{"image_id":{{json .Image}},"reference":{{json .Config.Image}},'
              '"project":{{json (index .Config.Labels "com.docker.compose.project")}},'
              '"service":{{json (index .Config.Labels "com.docker.compose.service")}},'
              '"mounts":[{{range $i,$m:=.Mounts}}{{if $i}},{{end}}'
              '{"destination":{{json $m.Destination}},"type":{{json $m.Type}}}{{end}}]}')
PHP_PROJECTION = r'''
$spec = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
$root = '/var/www/html';
$absent = [];
foreach ($spec['negative'] as $label => $relative) {
    $path = $root.'/'.$relative;
    $ok = !file_exists($path) && !is_link($path);
    for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
        if (is_link($parent) || (file_exists($parent) &&
            (!is_dir($parent) || !is_readable($parent) || !is_executable($parent)))) $ok = false;
    }
    $absent[$label] = $ok;
}
$marker = $root.'/'.$spec['marker'];
$positive = is_file($marker) && !is_link($marker) &&
    hash_file('sha256', $marker) === $spec['marker_sha256'];
echo json_encode(['negative_absence' => $absent, 'positive_marker_matches' => $positive], JSON_THROW_ON_ERROR);
'''
ERRORS = p.ERRORS | {"ci_only", "collision", "state", "original_changed", "cleanup", "runtime", "runtime_canary", "runtime_mount", "prepare"}


def context(environ=None):
    env = dict(os.environ if environ is None else environ)
    p.need(env.get("GITHUB_ACTIONS") == "true" and env.get("ADMIN_PHASE13_BUILD_CI") == "1"
           and env.get("ADMIN_PHASE7_CI") == "1", "ci_only")
    for key in ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT"):
        p.need(isinstance(env.get(key), str) and re.fullmatch(r"[0-9]{1,24}", env[key]), "ci_only")
    p.need(isinstance(env.get("GITHUB_SHA"), str) and p.HEX40.fullmatch(env["GITHUB_SHA"]), "ci_only")
    stamp = env["GITHUB_RUN_ID"]+"-"+env["GITHUB_RUN_ATTEMPT"]
    project = "imagine-performance-p7-ci-"+stamp
    p.need(env.get("P7_PROJECT") == project and env.get("P6_PORTAL_IMAGE") == "p7-portal-ci", "ci_only")
    root = p.local_root(env.get("GITHUB_WORKSPACE", ""))
    app = p.local_root(str(root / "apps/portal"))
    temp = p.local_root(env.get("RUNNER_TEMP", ""))
    p.need(app != temp and app not in temp.parents, "path")
    for relative in ("deploy/staging", "storage/app"):
        p.local_root(str(app / relative))
    return {"env": env, "root": root, "app": app, "project": project, "stamp": stamp,
            "state": temp / ("phase13-context-"+stamp)}


def layout(ctx):
    name = "phase13-context-"+ctx["stamp"]
    directories = {"nested": ctx["app"] / "deploy/staging" / name,
                   "storage": ctx["app"] / "storage/app" / name}
    files = {"composer_auth": ctx["app"] / "auth.json",
             "storage_private": directories["storage"] / "private.txt",
             "nested_env": directories["nested"] / ".env",
             "nested_env_variant": directories["nested"] / ".env.canary",
             "nested_auth": directories["nested"] / "auth.json",
             **{"nested_"+extension: directories["nested"] / ("synthetic."+extension)
                for extension in ("pem", "key", "p12", "pfx", "htpasswd")},
             "public_marker": directories["nested"] / "public-marker.txt"}
    contents = {label: ("IMAGINE_PHASE13_SYNTHETIC "+label+" "+ctx["stamp"]+"\n").encode() for label in files}
    for label in ("composer_auth", "nested_auth"):
        contents[label] = p.canonical({"http-basic": {"phase13.invalid":
                                        {"username": "synthetic", "password": ctx["stamp"]}}})+b"\n"
    return directories, files, contents


def identity(info):
    return {"dev": info.st_dev, "ino": info.st_ino, "mode": stat.S_IMODE(info.st_mode),
            "uid": info.st_uid, "gid": info.st_gid}


def make_directory(path, mode, owned, label):
    path.mkdir(mode=mode)
    owned[label] = {"path": path, "identity": identity(path.lstat())}
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fchmod(descriptor, mode)
    finally:
        owned[label]["identity"] = identity(os.fstat(descriptor))
        os.close(descriptor)


def make_file(path, content, owned, label, mode=0o600):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    written = 0
    owned[label] = {"path": path, "identity": identity(os.fstat(descriptor))}
    try:
        os.fchmod(descriptor, mode)
        while written < len(content):
            count = os.write(descriptor, content[written:])
            p.need(count > 0, "prepare")
            written += count
    finally:
        owned[label]["identity"] = {**identity(os.fstat(descriptor)), "size": written,
                                    "sha256": hashlib.sha256(content[:written]).hexdigest()}
        os.close(descriptor)


def matches(path, expected, *, directory=False):
    info = path.lstat()
    p.need(not stat.S_ISLNK(info.st_mode) and identity(info) == {key: expected[key] for key in IDENTITY}, "original_changed")
    if directory:
        p.need(stat.S_ISDIR(info.st_mode) and path.resolve(strict=True) == path, "original_changed")
    else:
        p.need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size == expected["size"], "original_changed")
        p.need(hashlib.sha256(p.read_file(path, limit=16384)).hexdigest() == expected["sha256"], "original_changed")


def remove_files(owned):
    success = True
    for record in reversed(list(owned.values())):
        path = record["path"]
        try:
            if not path.exists() and not path.is_symlink():
                continue
            matches(path, record["identity"])
            path.unlink()
        except (OSError, RuntimeError, p.Refused):
            success = False
    return success


def remove_directories(owned):
    success = True
    for record in reversed(list(owned.values())):
        path = record["path"]
        try:
            if not path.exists() and not path.is_symlink():
                continue
            matches(path, record["identity"], directory=True)
            path.rmdir()
        except (OSError, RuntimeError, p.Refused):
            success = False
    return success


def prepare(*, environ=None):
    ctx = context(environ)
    directories, files, contents = layout(ctx)
    owned_files, owned_directories = {}, {}
    try:
        make_directory(ctx["state"], 0o700, owned_directories, "state")
        make_file(files["composer_auth"], contents["composer_auth"], owned_files, "composer_auth")
        for label, path in directories.items():
            make_directory(path, 0o755 if label == "nested" else 0o700, owned_directories, label)
        for label, path in files.items():
            if label != "composer_auth":
                make_file(path, contents[label], owned_files, label, mode=0o644 if label == "public_marker" else 0o600)
        state = {"schema": STATE_SCHEMA, "stamp": ctx["stamp"], "portal_sha": ctx["env"]["GITHUB_SHA"],
                 "files": {label: owned_files[label]["identity"] for label in files},
                 "directories": {label: record["identity"] for label, record in owned_directories.items()}}
        make_file(ctx["state"] / "ownership.json", p.canonical(state)+b"\n", owned_files, "state")
        return {"schema": SCHEMA, "status": "SYNTHETIC_CANARIES_PREPARED", "canary_file_count": len(files),
                "preexisting_files_overwritten": False, "deployment_authorized": False}
    except BaseException as error:
        files_removed = remove_files(owned_files)
        directories_removed = remove_directories(owned_directories)
        if not (files_removed and directories_removed):
            raise p.Refused("cleanup") from None
        if isinstance(error, FileExistsError):
            raise p.Refused("collision") from None
        raise


def load_state(ctx):
    p.local_root(str(ctx["state"]))
    p.need(stat.S_IMODE(ctx["state"].stat().st_mode) == 0o700
           and {path.name for path in ctx["state"].iterdir()} == {"ownership.json"}, "state")
    path = ctx["state"] / "ownership.json"
    raw = p.read_file(path, limit=16384)
    state = p.parse_json(raw)
    directories, files, contents = layout(ctx)
    p.need(isinstance(state, dict) and set(state) == {"schema", "stamp", "portal_sha", "files", "directories"}, "state")
    p.need(state["schema"] == STATE_SCHEMA and state["stamp"] == ctx["stamp"]
           and state["portal_sha"] == ctx["env"]["GITHUB_SHA"], "state")
    p.need(isinstance(state["files"], dict) and set(state["files"]) == set(files)
           and isinstance(state["directories"], dict) and set(state["directories"]) == {"state", *directories}, "state")
    for kind, fields in (("files", FILE_IDENTITY), ("directories", IDENTITY)):
        for label, record in state[kind].items():
            p.need(isinstance(record, dict) and set(record) == fields, "state")
            p.need(all(type(record[key]) is int and record[key] >= 0 for key in fields-{"sha256"}), "state")
            mode = (0o644 if label == "public_marker" else 0o600) if kind == "files" else (0o755 if label == "nested" else 0o700)
            p.need(record["ino"] > 0 and record["mode"] == mode and record["uid"] == os.geteuid()
                   and record["gid"] == os.getegid(), "state")
            if kind == "files":
                p.need(record["size"] == len(contents[label])
                       and record["sha256"] == hashlib.sha256(contents[label]).hexdigest(), "state")
    matches(ctx["state"], state["directories"]["state"], directory=True)
    journal = {"path": path, "identity": {**identity(path.lstat()), "size": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}}
    return state, directories, files, contents, journal


def cleanup(*, environ=None):
    ctx = context(environ)
    if not ctx["state"].exists() and not ctx["state"].is_symlink():
        return {"schema": SCHEMA, "status": "NO_OWNED_CANARIES", "deployment_authorized": False}
    state, directories, files, _, journal = load_state(ctx)
    owned_files = {label: {"path": path, "identity": state["files"][label]} for label, path in files.items()}
    owned_dirs = {label: {"path": path, "identity": state["directories"][label]} for label, path in directories.items()}
    files_removed = remove_files(owned_files)
    directories_removed = remove_directories(owned_dirs)
    p.need(files_removed and directories_removed, "cleanup")
    p.need(remove_files({"state": journal}), "cleanup")
    p.need(remove_directories({"state": {"path": ctx["state"], "identity": state["directories"]["state"]}}), "cleanup")
    return {"schema": SCHEMA, "status": "OWNED_CANARIES_REMOVED", "canary_file_count": len(files),
            "preexisting_files_removed": False, "deployment_authorized": False}


def verify(*, environ=None, runner=p.run_read):
    ctx = context(environ)
    state, directories, files, contents, _ = load_state(ctx)
    for label, path in directories.items():
        matches(path, state["directories"][label], directory=True)
    for label, path in files.items():
        matches(path, state["files"][label])
    p.check_context(runner, ctx["root"], ctx["env"])
    compose = ["docker", "compose", "--project-directory", str(ctx["app"] / "deploy/phase6"),
               "--env-file", "/dev/null", "-p", ctx["project"], "-f", str(ctx["root"] / p.COMPOSE)]
    container = runner([*compose, "ps", "-q", "portal"], cwd=ctx["root"], env=ctx["env"], limit=4096).strip()
    p.need(p.HEX64.fullmatch(container), "runtime")
    actual = p.parse_json(runner(["docker", "container", "inspect", "--format", PROJECTION, container],
                                 cwd=ctx["root"], env=ctx["env"], limit=16384))
    p.need(isinstance(actual, dict) and set(actual) == {"image_id", "reference", "project", "service", "mounts"}, "runtime")
    p.need(actual["reference"] == "p7-portal-ci" and actual["project"] == ctx["project"] and actual["service"] == "portal"
           and isinstance(actual["image_id"], str) and p.IMAGE_ID.fullmatch(actual["image_id"]), "runtime")
    p.need(isinstance(actual["mounts"], list) and len(actual["mounts"]) <= 32, "runtime_mount")
    image_paths = ["/var/www/html/"+str(path.relative_to(ctx["app"])) for label, path in files.items() if label != "storage_private"]
    storage_volume = False
    for mount in actual["mounts"]:
        p.need(isinstance(mount, dict) and set(mount) == {"destination", "type"}
               and isinstance(mount["destination"], str) and len(mount["destination"]) <= 256
               and mount["type"] in {"bind", "volume", "tmpfs"}, "runtime_mount")
        destination = mount["destination"].rstrip("/") or "/"
        p.need(destination.startswith("/") and str(pathlib.PurePosixPath(destination)) == destination
               and ".." not in pathlib.PurePosixPath(destination).parts, "runtime_mount")
        p.need(not any(path == destination or path.startswith(destination.rstrip("/")+"/") for path in image_paths), "runtime_mount")
        storage_volume |= destination == "/var/www/html/storage" and mount["type"] == "volume"
    p.need(storage_volume, "runtime_mount")
    negative = {label: str(path.relative_to(ctx["app"])) for label, path in files.items() if label != "public_marker"}
    request = {"negative": negative, "marker": str(files["public_marker"].relative_to(ctx["app"])),
               "marker_sha256": hashlib.sha256(contents["public_marker"]).hexdigest()}
    observed = p.parse_json(runner([*compose, "exec", "-T", "--user", "33:33", "portal", "php", "-n", "-r", PHP_PROJECTION,
                                    "--", p.canonical(request).decode()], cwd=ctx["root"], env=ctx["env"], limit=4096))
    p.need(isinstance(observed, dict) and set(observed) == {"negative_absence", "positive_marker_matches"}
           and observed["positive_marker_matches"] is True and isinstance(observed["negative_absence"], dict)
           and set(observed["negative_absence"]) == set(negative)
           and all(value is True for value in observed["negative_absence"].values()), "runtime_canary")
    for label, path in files.items():
        matches(path, state["files"][label])
    return {"schema": SCHEMA, "status": "FOCAL_CONTEXT_EXCLUSIONS_OBSERVED", "source": "synthetic",
            "portal_sha": ctx["env"]["GITHUB_SHA"], "runtime_image_id": actual["image_id"],
            "dockerignore_sha256": hashlib.sha256(p.read_file(ctx["app"] / ".dockerignore")).hexdigest(),
            "host_original_canaries_unchanged": True, "positive_image_marker_matches": True,
            "image_paths_not_masked_by_mounts": True,
            "excluded_image_file_canaries": sorted(set(negative)-{"storage_private"}),
            "storage_private_runtime_absent": True, "storage_image_layers_verified": False,
            "canary_file_count": len(files), "preexisting_files_overwritten": False,
            "build_context_content_verified": False, "embedded_source_content_verified": False,
            "vps_modified": False, "deployment_authorized": False}


class Parser(argparse.ArgumentParser):
    def error(self, message):
        raise p.Refused("arguments")


def main():
    try:
        parser = Parser(description=__doc__)
        parser.add_argument("action", choices=("prepare", "verify", "cleanup"))
        action = parser.parse_args().action
        result = {"prepare": prepare, "verify": verify, "cleanup": cleanup}[action]()
        sys.stdout.buffer.write(p.canonical(result)+b"\n")
        return 0
    except (p.Refused, OSError, ValueError, TypeError, KeyError, RuntimeError) as error:
        reason = str(error) if isinstance(error, p.Refused) and str(error) in ERRORS else "input"
        sys.stderr.write("phase13 context canaries refused: "+reason+"\n")
        sys.stdout.buffer.write(p.canonical({"schema": SCHEMA, "status": "REFUSED", "reason": reason,
                                           "deployment_authorized": False})+b"\n")
        return 2


if __name__ == "__main__":
    sys.exit(main())
