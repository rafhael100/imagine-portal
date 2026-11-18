#!/usr/bin/env python3
"""Offline refusal/sanitization cases; injected observations never authorize CI."""
import copy
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
MODULE = pathlib.Path(__file__).resolve().parents[3] / "tools/performance-release/build_receipt.py"
spec = importlib.util.spec_from_file_location("build_receipt", MODULE)
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)
p = b.p
CANARY = "RAW_PRIVATE_METADATA_DO_NOT_PUBLISH"


class Fixture:
    def __init__(self, root):
        self.portal = root / "portal"
        self.engine = self.portal / "engine"
        self.engine.mkdir(parents=True)
        self.private = root / "phase13-build-123-1"
        self.private.mkdir(mode=0o700)
        self.path = self.private / "source-inputs.json"
        self.env = {"GITHUB_ACTIONS": "true", "ADMIN_PHASE13_BUILD_CI": "1", "ADMIN_PHASE7_CI": "1",
                    "GITHUB_RUN_ID": "123", "GITHUB_RUN_ATTEMPT": "1", "GITHUB_SHA": "a"*40,
                    "GITHUB_WORKSPACE": str(self.portal), "RUNNER_TEMP": str(root),
                    "P7_PROJECT": "imagine-performance-p7-ci-123-1"}
        self.env.update({b.REFERENCE_ENV[name]: reference for name, reference in b.REFERENCES.items()})
        self.sha = {self.portal: self.env["GITHUB_SHA"], self.engine: b.ENGINE_PIN}
        self.trees = {self.portal: {"HEAD^{tree}": "c"*40, "HEAD:apps/portal": "d"*40},
                      self.engine: {"HEAD^{tree}": "e"*40, "HEAD:src": "f"*40, "HEAD:deploy/private-admin": "1"*40}}
        self.dirty = ""
        self.context = "unix:///var/run/docker.sock"
        self.calls = []
        for checkout, files in ((self.portal, p.PORTAL_FILES | {"apps/portal/database/migrations/2026_11_17_000001_synthetic.php"}),
                                (self.engine, p.ENGINE_FILES)):
            for relative in files:
                path = checkout / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("synthetic controlled source\n")
        self.containers, self.runtime, self.metadata = {}, {}, {}
        for index, name in enumerate(b.SERVICES):
            container = str(index+1)*64
            image = "sha256:"+str(index+4)*64
            manifest = "sha256:"+"abc"[index]*64
            self.containers[name] = container
            self.runtime[container] = {"image_id": image, "reference": b.REFERENCES[name],
                                       "project": self.env["P7_PROJECT"], "service": name}
            self.metadata[name] = {
                "containerimage.config.digest": image, "containerimage.digest": manifest,
                "containerimage.descriptor": {"digest": manifest, "annotations": {"config.digest": image, "private": CANARY}},
                "buildx.build.ref": "/private/"+CANARY,
                "buildx.build.warnings": {"private": CANARY},
                "buildx.build.provenance": {"materials": [
                    {"uri": "pkg:docker/"+base+"@example?platform=linux%2Famd64", "digest": {"sha256": "e"*64}}
                    for base in b.BASES[name]], "invocation": {"parameters": {"TOKEN": CANARY}}}}
        self.inputs = b.capture(str(self.portal), str(self.engine), runner=self.runner, environ=self.env)
        self.write_inputs()
        self.write_metadata()

    def write_inputs(self):
        self.path.write_bytes(p.canonical(self.inputs))

    def write_metadata(self):
        for name, metadata in self.metadata.items():
            (self.private / (name+".metadata.json")).write_bytes(p.canonical(metadata))

    def runner(self, args, *, cwd, env, limit):
        self.calls.append(args)
        if args[0] == "git":
            if "--show-toplevel" in args:
                return str(cwd)+"\n"
            if "rev-parse" in args:
                return (self.sha[cwd] if args[-1] == "HEAD" else self.trees[cwd][args[-1]])+"\n"
            if "status" in args:
                return self.dirty
            if "ls-files" in args:
                return "\n".join(args[args.index("--")+1:])+"\n"
        if args == ["docker", "context", "show"]:
            return "default\n"
        if args[:3] == ["docker", "context", "inspect"]:
            return json.dumps(self.context)
        if args[:2] == ["docker", "compose"]:
            if args[-3:-1] != ["ps", "-q"]:
                raise AssertionError("unexpected Compose command")
            return self.containers[args[-1]]+"\n"
        if args[:3] == ["docker", "container", "inspect"]:
            if args[3:5] != ["--format", b.CONTAINER_PROJECTION]:
                raise AssertionError("unexpected container projection")
            return json.dumps(self.runtime[args[-1]])
        if args[:3] == ["docker", "image", "inspect"]:
            if args[3:5] != ["--format", "{{json .Id}}"]:
                raise AssertionError("unexpected image projection")
            return json.dumps(args[-1])
        raise AssertionError("unexpected read command")

    def verify(self):
        return b.verify(str(self.path), str(self.private), str(self.portal), str(self.engine),
                        runner=self.runner, environ=self.env)


class ReceiptTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="phase13-build-test-")
        self.f = Fixture(pathlib.Path(self.temp.name))

    def tearDown(self):
        self.temp.cleanup()

    def denied(self, reason):
        with self.assertRaises(p.Refused) as caught:
            self.f.verify()
        self.assertEqual(str(caught.exception), reason)

    def test_positive_preserves_reported_digest_without_inferred_type_and_inputs(self):
        before = {path: path.read_bytes() for path in self.f.private.iterdir()}
        result = self.f.verify()
        self.assertEqual(result["status"], "LOCAL_BUILD_ASSOCIATION_OBSERVED")
        for name, image in result["images"].items():
            self.assertNotEqual(image["config_digest"], image["reported_image_digest"])
            self.assertFalse(image["reported_image_digest_matches_config"])
            self.assertEqual(image["reported_image_digest_type"], "not_established")
            self.assertEqual(image["config_digest"], self.f.runtime[self.f.containers[name]]["image_id"])
            self.assertTrue(image["materials"]["expected_base_digests_observed"])
            self.assertFalse(image["registry_manifest_verified"] or image["materials"]["material_set_complete_verified"])
        for field in ("build_provenance_verified", "authenticated_provenance_verified", "build_context_content_verified",
                      "embedded_source_content_verified", "human_approval_verified", "registry_release_published", "deployment_authorized"):
            self.assertFalse(result[field])
        self.assertEqual(before, {path: path.read_bytes() for path in self.f.private.iterdir()})

    def test_observed_docker_exporter_digest_equal_to_config_without_descriptor(self):
        for metadata in self.f.metadata.values():
            metadata["containerimage.digest"] = metadata["containerimage.config.digest"]
            del metadata["containerimage.descriptor"]
        self.f.write_metadata()
        result = self.f.verify()
        self.assertTrue(result["metadata_matches_runtime_images"])
        for image in result["images"].values():
            self.assertEqual(image["reported_image_digest"], image["config_digest"])
            self.assertTrue(image["reported_image_digest_observed"] and image["reported_image_digest_matches_config"])
            self.assertTrue(image["config_digest_matches_runtime_image"])
            self.assertEqual(image["reported_image_digest_type"], "not_established")
            self.assertIsNone(image["descriptor_digest"])
            self.assertFalse(image["descriptor_digest_observed"] or image["registry_manifest_verified"])
            self.assertNotIn("manifest_digest", image)

    def test_only_allowlisted_metadata_is_emitted(self):
        material = {"uri": "https://user:"+CANARY+"@private.invalid/source", "digest": {"sha256": "d"*64}}
        self.f.metadata["portal"]["buildx.build.provenance"]["materials"].append(material)
        self.f.write_metadata()
        result = self.f.verify()
        serialized = p.canonical(result).decode()
        self.assertNotIn(CANARY, serialized)
        self.assertNotIn(str(self.f.portal), serialized)
        self.assertNotIn("private.invalid", serialized)
        self.assertLess(len(serialized), 16384)
        self.assertEqual(result["images"]["portal"]["materials"]["other_materials_omitted"], 1)

    def test_missing_optional_metadata_is_explicit(self):
        self.f.metadata["portal"] = {"containerimage.config.digest": self.f.metadata["portal"]["containerimage.config.digest"]}
        self.f.write_metadata()
        image = self.f.verify()["images"]["portal"]
        self.assertIsNone(image["reported_image_digest"])
        self.assertIsNone(image["reported_image_digest_matches_config"])
        self.assertEqual(image["reported_image_digest_type"], "not_established")
        self.assertFalse(image["reported_image_digest_observed"] or image["descriptor_digest_observed"])
        self.assertEqual(image["materials"]["materials_status"], "absent")
        self.assertFalse(image["materials"]["expected_base_digests_observed"])

    def test_unknown_provenance_shape_is_qualified(self):
        self.f.metadata["portal"]["buildx.build.provenance"] = {"future": CANARY}
        self.f.write_metadata()
        material = self.f.verify()["images"]["portal"]["materials"]
        self.assertEqual(material["materials_status"], "unrecognized")
        self.assertFalse(material["expected_base_digests_observed"])

    def test_slsa_v1_material_projection_is_supported(self):
        provenance = self.f.metadata["portal"]["buildx.build.provenance"]
        self.f.metadata["portal"]["buildx.build.provenance"] = {"buildDefinition": {"resolvedDependencies": provenance["materials"]}}
        self.f.write_metadata()
        material = self.f.verify()["images"]["portal"]["materials"]
        self.assertEqual(material["materials_status"], "observed_slsa_v1")
        self.assertTrue(material["expected_base_digests_observed"])

    def test_base_without_digest_is_not_resolved_from_tag(self):
        self.f.metadata["portal"]["buildx.build.provenance"]["materials"][0]["digest"] = {}
        self.f.write_metadata()
        material = self.f.verify()["images"]["portal"]["materials"]
        self.assertFalse(material["expected_base_digests_observed"])
        self.assertEqual(material["bases"][0]["digests"], [])

    def test_missing_config_digest_is_refused(self):
        del self.f.metadata["portal"]["containerimage.config.digest"]
        self.f.write_metadata(); self.denied("metadata_identity")

    def test_metadata_from_other_service_is_refused(self):
        self.f.metadata["portal"] = copy.deepcopy(self.f.metadata["receiver"])
        self.f.write_metadata(); self.denied("metadata_identity")

    def test_conflicting_reported_digest_or_config_annotation_is_refused(self):
        descriptor = self.f.metadata["portal"]["containerimage.descriptor"]
        original = copy.deepcopy(descriptor)
        descriptor["digest"] = "sha256:"+"f"*64
        self.f.write_metadata(); self.denied("metadata_identity")
        descriptor.update(original)
        descriptor["annotations"]["config.digest"] = "sha256:"+"f"*64
        self.f.write_metadata(); self.denied("metadata_identity")

    def test_malformed_material_digest_is_refused(self):
        self.f.metadata["portal"]["buildx.build.provenance"]["materials"][0]["digest"]["sha256"] = "invalid"
        self.f.write_metadata(); self.denied("materials")

    def test_duplicate_and_oversized_metadata_are_refused(self):
        path = self.f.private / "portal.metadata.json"
        path.write_text('{"containerimage.digest":"one","containerimage.digest":"two"}')
        self.denied("json")
        path.write_bytes(b" "*(p.MAX_INPUT+1)); self.denied("input")

    def test_runtime_project_service_and_reference_must_match(self):
        actual = self.f.runtime[self.f.containers["portal"]]
        for key in ("project", "service", "reference"):
            saved = actual[key]
            actual[key] = "wrong"
            self.denied("runtime")
            actual[key] = saved

    def test_multiple_containers_are_refused(self):
        self.f.containers["portal"] += "\n"+"f"*64
        self.denied("runtime")

    def test_dockerfile_change_between_capture_and_verify_is_refused(self):
        (self.f.portal / b.DOCKERFILES["portal"][1]).write_text("changed Dockerfile\n")
        self.denied("file_digest")

    def test_tree_change_between_capture_and_verify_is_refused(self):
        self.f.trees[self.f.engine]["HEAD:src"] = "2"*40
        self.denied("source_tree")

    def test_source_revision_and_dirty_checkout_are_refused(self):
        self.f.sha[self.f.engine] = "2"*40
        self.denied("source_sha")
        self.f.sha[self.f.engine] = b.ENGINE_PIN
        self.f.dirty = "?? src/Unexpected.php\n"
        self.denied("source_dirty")

    def test_expected_file_omission_is_refused(self):
        del self.f.inputs["files"]["portal"][p.COMPOSE]
        self.f.write_inputs(); self.denied("file_set")

    def test_remote_docker_and_missing_ci_guard_are_refused(self):
        self.f.env["DOCKER_HOST"] = "ssh://private.invalid"
        self.denied("context")
        del self.f.env["DOCKER_HOST"]
        del self.f.env["ADMIN_PHASE13_BUILD_CI"]
        self.denied("ci_only")

    def test_public_metadata_directory_is_refused(self):
        self.f.private.chmod(0o755)
        self.denied("private_dir")

    def test_metadata_symlink_is_refused(self):
        path = self.f.private / "portal.metadata.json"
        path.unlink()
        path.symlink_to(self.f.private / "receiver.metadata.json")
        self.denied("path")

    def test_cli_errors_never_echo_unknown_arguments(self):
        process = subprocess.run([sys.executable, str(MODULE), "--"+CANARY], stdout=subprocess.PIPE,
                                 stderr=subprocess.PIPE, check=False)
        self.assertEqual(process.returncode, 2)
        self.assertNotIn(CANARY.encode(), process.stdout+process.stderr)
        self.assertEqual(json.loads(process.stdout)["reason"], "arguments")


if __name__ == "__main__":
    unittest.main()
