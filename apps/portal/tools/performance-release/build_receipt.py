#!/usr/bin/env python3
"""A bounded CI observation of local build metadata, sources and running images.

This is a receipt, not a signature, attestation verifier or release approval.
Only fixed projections are emitted. Raw BuildKit metadata stays private.
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
_spec = importlib.util.spec_from_file_location("release_preflight", pathlib.Path(__file__).with_name("preflight.py"))
p = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(p)

INPUT_SCHEMA = "imagine.performance.build.inputs.v1"
RESULT_SCHEMA = "imagine.performance.build.receipt.v1"
ENGINE_PIN = "79369806d85f251544e17a42a978710ec8e18af3"
SERVICES = ("portal", "portal-web", "receiver")
REFERENCES = {"portal": "p7-portal-ci", "portal-web": "p7-web-ci", "receiver": "p7-receiver-ci"}
REFERENCE_ENV = {"portal": "P6_PORTAL_IMAGE", "portal-web": "P6_PORTAL_WEB_IMAGE", "receiver": "P6_RECEIVER_IMAGE"}
DOCKERFILES = {"portal": ("portal", "apps/portal/deploy/staging/Dockerfile.php"),
               "portal-web": ("portal", "apps/portal/deploy/staging/Dockerfile.nginx"),
               "receiver": ("engine", "deploy/private-admin/Dockerfile")}
BASES = {"portal": ("php", "composer"), "portal-web": ("nginx",), "receiver": ("php",)}
TREE_SPECS = {"portal": {"repository": "HEAD^{tree}", "application": "HEAD:apps/portal"},
              "engine": {"repository": "HEAD^{tree}", "src": "HEAD:src", "private_admin": "HEAD:deploy/private-admin"}}
CONTAINER_PROJECTION = ('{"image_id":{{json .Image}},"reference":{{json .Config.Image}},'
                        '"project":{{json (index .Config.Labels "com.docker.compose.project")}},'
                        '"service":{{json (index .Config.Labels "com.docker.compose.service")}}}')
BASE_URI = re.compile(r"pkg:docker/(?:(?:docker\.io|index\.docker\.io)/)?(?:library/)?"
                      r"(php|composer|nginx)@[^?\s#]{1,128}"
                      r"(?:\?platform=linux(?:%2[Ff]|/)[a-z0-9._-]{1,32}(?:(?:%2[Ff]|/)[a-z0-9._-]{1,16})?)?\Z")
ERRORS = p.ERRORS | {"source_tree", "inputs", "metadata", "metadata_identity", "materials", "runtime", "private_dir"}


def ci_context(portal, engine, env):
    p.need(env.get("GITHUB_ACTIONS") == "true" and env.get("ADMIN_PHASE13_BUILD_CI") == "1"
           and env.get("ADMIN_PHASE7_CI") == "1", "ci_only")
    for key in ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT"):
        p.need(isinstance(env.get(key), str) and re.fullmatch(r"[0-9]{1,24}", env[key]), "ci_only")
    project = "imagine-performance-p7-ci-"+env["GITHUB_RUN_ID"]+"-"+env["GITHUB_RUN_ATTEMPT"]
    p.need(env.get("P7_PROJECT") == project and env.get("GITHUB_WORKSPACE") == str(portal)
           and engine == portal / "engine", "ci_only")
    p.need(isinstance(env.get("GITHUB_SHA"), str) and p.HEX40.fullmatch(env["GITHUB_SHA"]), "source_sha")
    p.need(all(env.get(REFERENCE_ENV[name]) == REFERENCES[name] for name in SERVICES), "image_reference")
    return project


def private_directory(value, env):
    p.need(isinstance(env.get("RUNNER_TEMP"), str), "private_dir")
    temp = p.local_root(env["RUNNER_TEMP"])
    directory = p.local_root(value)
    expected = temp / ("phase13-build-"+env["GITHUB_RUN_ID"]+"-"+env["GITHUB_RUN_ATTEMPT"])
    p.need(directory == expected and stat.S_IMODE(directory.stat().st_mode) == 0o700, "private_dir")
    return directory


def observe_trees(portal, engine, runner, env):
    trees = {}
    for name, root in (("portal", portal), ("engine", engine)):
        trees[name] = {}
        for label, revision in TREE_SPECS[name].items():
            value = p.git_read(runner, root, ["rev-parse", revision], env)
            p.need(p.HEX40.fullmatch(value), "source_tree")
            trees[name][label] = value
    return trees


def capture(portal_root, engine_root, *, runner=p.run_read, environ=None):
    env = dict(os.environ if environ is None else environ)
    portal, engine = p.local_root(portal_root), p.local_root(engine_root)
    project = ci_context(portal, engine, env)
    files = {}
    sets = p.file_sets(portal, engine)
    for name, root in (("portal", portal), ("engine", engine)):
        files[name] = {relative: hashlib.sha256(p.read_file(root / relative)).hexdigest() for relative in sets[name]}
    inputs = {"schema": INPUT_SCHEMA, "portal_sha": env["GITHUB_SHA"], "engine_sha": ENGINE_PIN,
              "project": project, "files": files, "trees": observe_trees(portal, engine, runner, env),
              "images": dict(REFERENCES), "run_id": env["GITHUB_RUN_ID"], "run_attempt": env["GITHUB_RUN_ATTEMPT"]}
    p.check_sources(inputs, portal, engine, runner, env)
    return inputs


def validate_inputs(inputs, project, env):
    p.need(isinstance(inputs, dict) and set(inputs) == {"schema", "portal_sha", "engine_sha", "project",
           "files", "trees", "images", "run_id", "run_attempt"}, "inputs")
    p.need(inputs["schema"] == INPUT_SCHEMA and inputs["project"] == project and inputs["images"] == REFERENCES
           and inputs["portal_sha"] == env["GITHUB_SHA"] and inputs["engine_sha"] == ENGINE_PIN
           and inputs["run_id"] == env["GITHUB_RUN_ID"] and inputs["run_attempt"] == env["GITHUB_RUN_ATTEMPT"], "inputs")
    p.need(isinstance(inputs["files"], dict) and set(inputs["files"]) == {"portal", "engine"}, "inputs")
    for files in inputs["files"].values():
        p.need(isinstance(files, dict) and 1 <= len(files) <= 160, "inputs")
        p.need(all(isinstance(path, str) and len(path) <= 256 and isinstance(value, str)
                   and p.HEX64.fullmatch(value) for path, value in files.items()), "inputs")
    p.need(isinstance(inputs["trees"], dict) and set(inputs["trees"]) == set(TREE_SPECS), "inputs")
    for name, expected in TREE_SPECS.items():
        group = inputs["trees"][name]
        p.need(isinstance(group, dict) and set(group) == set(expected)
               and all(isinstance(value, str) and p.HEX40.fullmatch(value) for value in group.values()), "inputs")


def materials_projection(metadata, service):
    result = {"provenance_field_observed": "buildx.build.provenance" in metadata,
              "materials_status": "absent", "material_count": 0, "other_materials_omitted": 0,
              "bases": [{"name": name, "digests": [], "digest_observed": False} for name in BASES[service]],
              "expected_base_digests_observed": False, "material_set_complete_verified": False}
    if not result["provenance_field_observed"]:
        return result
    provenance = metadata["buildx.build.provenance"]
    p.need(isinstance(provenance, dict), "materials")
    if "materials" in provenance:
        materials = provenance["materials"]
        result["materials_status"] = "observed_slsa_v0_2"
    elif "buildDefinition" in provenance:
        definition = provenance["buildDefinition"]
        p.need(isinstance(definition, dict), "materials")
        if "resolvedDependencies" not in definition:
            result["materials_status"] = "unrecognized"
            return result
        materials = definition["resolvedDependencies"]
        result["materials_status"] = "observed_slsa_v1"
    else:
        result["materials_status"] = "unrecognized" if provenance else "absent"
        return result
    p.need(isinstance(materials, list) and len(materials) <= 128, "materials")
    result["material_count"] = len(materials)
    bases = {base["name"]: base for base in result["bases"]}
    for material in materials:
        p.need(isinstance(material, dict) and isinstance(material.get("uri"), str)
               and len(material["uri"]) <= 2048, "materials")
        checksums = material.get("digest", {})
        p.need(isinstance(checksums, dict), "materials")
        sha256 = checksums.get("sha256")
        if "sha256" in checksums:
            p.need(isinstance(sha256, str) and p.HEX64.fullmatch(sha256), "materials")
        match = BASE_URI.fullmatch(material["uri"])
        if match is None or match[1] not in bases:
            result["other_materials_omitted"] += 1
            continue
        if sha256 is not None:
            bases[match[1]]["digests"].append("sha256:"+sha256)
    for base in result["bases"]:
        base["digests"] = sorted(set(base["digests"]))
        base["digest_observed"] = bool(base["digests"])
    result["expected_base_digests_observed"] = all(base["digest_observed"] for base in result["bases"])
    return result


def metadata_projection(raw, service, image_id):
    metadata = p.parse_json(raw)
    p.need(isinstance(metadata, dict), "metadata")
    config_digest = metadata.get("containerimage.config.digest")
    p.need(isinstance(config_digest, str) and p.IMAGE_ID.fullmatch(config_digest), "metadata_identity")
    p.need(config_digest == image_id, "metadata_identity")
    reported_image_digest = metadata.get("containerimage.digest")
    if "containerimage.digest" in metadata:
        p.need(isinstance(reported_image_digest, str) and p.IMAGE_ID.fullmatch(reported_image_digest), "metadata")
    descriptor_digest = None
    if "containerimage.descriptor" in metadata:
        descriptor = metadata["containerimage.descriptor"]
        p.need(isinstance(descriptor, dict), "metadata")
        descriptor_digest = descriptor.get("digest")
        p.need(isinstance(descriptor_digest, str) and p.IMAGE_ID.fullmatch(descriptor_digest), "metadata")
        p.need(reported_image_digest is None or descriptor_digest == reported_image_digest, "metadata_identity")
        annotations = descriptor.get("annotations", {})
        p.need(isinstance(annotations, dict), "metadata")
        if "config.digest" in annotations:
            p.need(annotations["config.digest"] == config_digest, "metadata_identity")
    return {"metadata_sha256": hashlib.sha256(raw).hexdigest(), "config_digest": config_digest,
            "config_digest_matches_runtime_image": True,
            "reported_image_digest": reported_image_digest,
            "reported_image_digest_observed": reported_image_digest is not None,
            "reported_image_digest_matches_config": None if reported_image_digest is None else reported_image_digest == config_digest,
            "reported_image_digest_type": "not_established",
            "descriptor_digest": descriptor_digest, "descriptor_digest_observed": descriptor_digest is not None,
            "registry_manifest_verified": False, "materials": materials_projection(metadata, service)}


def observe_runtime(project, service, portal, runner, env):
    args = ["docker", "compose", "--project-directory", str(portal / "apps/portal/deploy/phase6"),
            "--env-file", "/dev/null", "-p", project, "-f", str(portal / p.COMPOSE), "ps", "-q", service]
    container = runner(args, cwd=portal, env=env, limit=4096).strip()
    p.need(p.HEX64.fullmatch(container), "runtime")
    actual = p.parse_json(runner(["docker", "container", "inspect", "--format", CONTAINER_PROJECTION, container],
                                 cwd=portal, env=env, limit=4096))
    p.need(isinstance(actual, dict) and set(actual) == {"image_id", "reference", "project", "service"}, "runtime")
    p.need(actual["project"] == project and actual["service"] == service
           and actual["reference"] == REFERENCES[service], "runtime")
    p.need(isinstance(actual["image_id"], str) and p.IMAGE_ID.fullmatch(actual["image_id"]), "image_identity")
    inspected = p.parse_json(runner(["docker", "image", "inspect", "--format", "{{json .Id}}", actual["image_id"]],
                                    cwd=portal, env=env, limit=4096))
    p.need(inspected == actual["image_id"], "image_identity")
    return actual["image_id"]


def verify(inputs_path, metadata_dir, portal_root, engine_root, *, runner=p.run_read, environ=None):
    env = dict(os.environ if environ is None else environ)
    portal, engine = p.local_root(portal_root), p.local_root(engine_root)
    project = ci_context(portal, engine, env)
    directory = private_directory(metadata_dir, env)
    p.need(pathlib.Path(inputs_path) == directory / "source-inputs.json", "inputs")
    inputs = p.parse_json(p.read_file(inputs_path, limit=p.MAX_INPUT))
    validate_inputs(inputs, project, env)
    p.check_sources(inputs, portal, engine, runner, env)
    p.need(observe_trees(portal, engine, runner, env) == inputs["trees"], "source_tree")
    p.check_context(runner, portal, env)
    images = {}
    for service in SERVICES:
        image_id = observe_runtime(project, service, portal, runner, env)
        raw = p.read_file(directory / (service+".metadata.json"), limit=p.MAX_INPUT)
        image = metadata_projection(raw, service, image_id)
        group, dockerfile = DOCKERFILES[service]
        image.update({"reference": REFERENCES[service], "runtime_image_id": image_id,
                      "dockerfile_sha256": inputs["files"][group][dockerfile]})
        images[service] = image
    return {"schema": RESULT_SCHEMA, "status": "LOCAL_BUILD_ASSOCIATION_OBSERVED", "purpose": "ci-rehearsal",
            "run_id": inputs["run_id"], "run_attempt": inputs["run_attempt"], "project": project,
            "portal_sha": inputs["portal_sha"], "engine_sha": inputs["engine_sha"], "trees": inputs["trees"],
            "critical_file_count": sum(len(files) for files in inputs["files"].values()),
            "critical_file_digests_sha256": p.digest(inputs["files"]),
            "compose_file_sha256": inputs["files"]["portal"][p.COMPOSE],
            "dockerignore_sha256": inputs["files"]["portal"]["apps/portal/.dockerignore"],
            "source_inputs_unchanged": True, "metadata_matches_runtime_images": True, "images": images,
            "build_context_content_verified": False, "embedded_source_content_verified": False,
            "build_provenance_verified": False, "authenticated_provenance_verified": False,
            "human_approval_verified": False, "registry_release_published": False, "deployment_authorized": False}


class Parser(argparse.ArgumentParser):
    def error(self, message):
        raise p.Refused("arguments")


def main():
    try:
        parser = Parser(description=__doc__)
        parser.add_argument("action", choices=("capture", "verify"))
        parser.add_argument("--portal-root", required=True)
        parser.add_argument("--engine-root", required=True)
        parser.add_argument("--inputs")
        parser.add_argument("--metadata-dir")
        args = parser.parse_args()
        if args.action == "capture":
            p.need(args.inputs is None and args.metadata_dir is None, "arguments")
            result = capture(args.portal_root, args.engine_root)
        else:
            p.need(args.inputs is not None and args.metadata_dir is not None, "arguments")
            result = verify(args.inputs, args.metadata_dir, args.portal_root, args.engine_root)
        sys.stdout.buffer.write(p.canonical(result)+b"\n")
        return 0
    except p.Refused as error:
        code = str(error) if str(error) in ERRORS else "input"
        sys.stderr.write("phase13 build receipt refused: "+code+"\n")
        sys.stdout.buffer.write(p.canonical({"schema": RESULT_SCHEMA, "status": "REFUSED", "reason": code,
                                           "deployment_authorized": False})+b"\n")
        return 2
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        sys.stderr.write("phase13 build receipt refused: input\n")
        sys.stdout.buffer.write(p.canonical({"schema": RESULT_SCHEMA, "status": "REFUSED", "reason": "input",
                                           "deployment_authorized": False})+b"\n")
        return 2


if __name__ == "__main__":
    sys.exit(main())
