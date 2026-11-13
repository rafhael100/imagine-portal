#!/usr/bin/env python3
"""Rehearse a nine-file, branding-only Imagine Portal wave against dev.

No remote writes, branch updates, database services, credentials or deployments.
Returns a full binary patch and an auditable manifest on success.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys

DEV_REF = "origin/dev/fast-track-portal-2026-11-05"
EXPECTED_LOGO_BLOB = "678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77"
SELECTED = (
    "apps/portal/public/assets/logo-imagine.png",
    "apps/portal/resources/views/components/portal/brand.blade.php",
    "apps/portal/resources/views/components/portal/layout.blade.php",
    "apps/portal/resources/views/components/portal/sidebar.blade.php",
    "apps/portal/resources/views/components/portal/styles.blade.php",
)
TEST = "apps/portal/tests/Feature/PortalUiComponentsTest.php"
OTHER_TESTS = (
    "apps/portal/tests/Feature/PortalHelpPresentationTest.php",
    "apps/portal/tests/Feature/PortalNoticesPresentationTest.php",
    "apps/portal/tests/Feature/PortalRequestsPresentationTest.php",
)


def git(root: Path, *args: str) -> bytes:
    return subprocess.run(
        ["git", "-C", str(root), *args], check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    ).stdout


def replace_exact(source: str, old: str, new: str) -> str:
    if source.count(old) != 1:
        raise RuntimeError("Legacy UI test has changed; review migration manually.")
    return source.replace(old, new)


def build(root: Path, worktree: Path, out: Path) -> dict:
    if out.exists() or worktree.exists():
        raise RuntimeError("Output or temporary worktree already exists.")
    head = git(root, "rev-parse", "HEAD").decode().strip()
    base = git(root, "rev-parse", "--verify", f"{DEV_REF}^{{commit}}").decode().strip()
    if len(head) != 40 or len(base) != 40:
        raise RuntimeError("Unexpected candidate or dev SHA.")
    git(root, "worktree", "add", "--detach", str(worktree), base)
    # Import only five visual files, not the surrounding 1,629 commits.
    git(worktree, "restore", f"--source={head}", "--staged", "--worktree", "--", *SELECTED)
    target = worktree / TEST
    source = target.read_text(encoding="utf-8")
    source = replace_exact(
        source,
        "            self::assertStringNotContainsString('src=', $html);",
        "            self::assertSame(1, substr_count($html, 'src=\"/assets/logo-imagine.png\"'));",
    )
    source = replace_exact(
        source,
        "            self::assertStringNotContainsString('<img', $html);",
        "            $expectedLogoCount = str_contains($template, '<x-portal.layout') ? 1 : 0;\n"
        "            self::assertSame($expectedLogoCount, substr_count($html, 'src=\"/assets/logo-imagine.png\"'));",
    )
    target.write_text(source, encoding="utf-8")
    for test_path in OTHER_TESTS:
        legacy = worktree / test_path
        test_source = legacy.read_text(encoding="utf-8")
        # Replace a blanket ban on *all* image sources with an exact pinned
        # first-party Imagine logo allowance; retain bans on active content,
        # non-local URLs, forms and arbitrary script sources.
        test_source = replace_exact(test_source, "'src=', ", "")
        logo_assert = (
            "self::assertSame(1, substr_count($html, "
            "'src=\"/assets/logo-imagine.png\"'));"
        )
        if test_path.endswith("PortalRequestsPresentationTest.php"):
            before = "        foreach (['<form', '<button'"
            after = "        " + logo_assert + "\n" + before
        else:
            before = '            self::assertStringContainsString(\'<main id="pi-content"\', $html);'
            after = before + "\n            " + logo_assert
        test_source = replace_exact(test_source, before, after)
        legacy.write_text(test_source, encoding="utf-8")
    logo = worktree / SELECTED[0]
    logo_bytes = logo.read_bytes()
    blob = hashlib.sha1(b"blob " + str(len(logo_bytes)).encode() + b"\0" + logo_bytes).hexdigest()
    if blob != EXPECTED_LOGO_BLOB:
        raise RuntimeError("Original Imagine logo blob does not match pinned provenance.")
    if b"<script" in (worktree / SELECTED[2]).read_bytes().lower():
        raise RuntimeError("Active script in candidate layout.")
    changed_raw = git(worktree, "diff", "--name-only", "HEAD", "--")
    changed = [p for p in changed_raw.decode().splitlines() if p]
    expected = sorted((*SELECTED, TEST, *OTHER_TESTS))
    if changed != expected:
        raise RuntimeError(f"Unexpected visual wave footprint: {changed!r}")
    out.mkdir(mode=0o700, parents=True)
    patch = git(worktree, "diff", "--binary", "HEAD", "--")
    if b"GIT binary patch" not in patch and b"Binary files" not in patch:
        raise RuntimeError("PNG did not survive as a binary Git change.")
    (out / "visual-wave.patch").write_bytes(patch)
    manifest = {
        "schema": "imagine.portal.visual-wave-rehearsal.v1",
        "source_review_head": head,
        "dev_base_sha": base,
        "dev_base_tree_sha": git(worktree, "rev-parse", "HEAD^{tree}").decode().strip(),
        "changed_files": changed,
        "file_count": len(changed),
        "logo_blob_sha1": blob,
        "patch_sha256": hashlib.sha256(patch).hexdigest(),
        "review_only": True,
        "production_approved": False,
        "safe_to_merge": False,
        "deployed": False,
    }
    (out / "manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True, type=Path)
    parser.add_argument("--worktree", required=True, type=Path)
    parser.add_argument("--out", required=True, type=Path)
    args = parser.parse_args()
    result = build(args.repo.resolve(), args.worktree.resolve(), args.out.resolve())
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, RuntimeError, subprocess.CalledProcessError) as exc:
        print("Visual wave rehearsal refused: " + str(exc)[:250], file=sys.stderr)
        sys.exit(2)
