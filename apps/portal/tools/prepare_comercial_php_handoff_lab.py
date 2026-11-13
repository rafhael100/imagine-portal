#!/usr/bin/env python3
"""Rewrite ONLY four Astro offer CTAs for a local-only Comercial -> Portal PG18 lab.

Never operate on a live site or build for deployment. CI checks the original
branded hero and source remain unchanged. No real checkout endpoints created.
"""
from __future__ import annotations
import argparse
from pathlib import Path
import re
import shutil
import stat
import sys

SLUGS = {
    "onepage": "onepage",
    "landing-pages": "landing-pages",
    "gestao-google-ads": "performance",
    "gestao-meta-ads": "performance",
}
LOOPBACK_PORTAL = "http://127.0.0.1:18776/_rehearsal/purchase?offer="
HERO = re.compile(r'<section\b[^>]*class="[^"]*hero-main[^"]*"[^>]*>[\s\S]*?</section>')
CARD = re.compile(r'<article\b[^>]*data-quick-hire="(?P<slug>[^"]+)"[^>]*>[\s\S]*?</article>')
LINK = re.compile(r'<a\b(?P<attrs>[^>]*\bdata-quick-hire-action\b[^>]*)>(?P<body>[\s\S]*?)</a>')
HREF = re.compile(r'\bhref="[^"]*"')


def rewrite(source: str) -> tuple[str, dict[str, int]]:
    found: dict[str, int] = {slug: 0 for slug in SLUGS}

    def transform(match: re.Match[str]) -> str:
        slug = match.group("slug")
        if slug not in SLUGS:
            return match.group(0)
        found[slug] += 1

        def replace_link(link: re.Match[str]) -> str:
            attrs, body = link.group("attrs"), link.group("body")
            if len(HREF.findall(attrs)) != 1:
                raise ValueError("Malformed offer link.")
            attrs = HREF.sub(
                'href="' + LOOPBACK_PORTAL + SLUGS[slug] + '"', attrs
            )
            # This absolute address is allowed only inside disposable CI.
            # It must not escape into any deployed Comercial build.
            attrs = re.sub(r'\s+target="[^"]*"', "", attrs)
            attrs = re.sub(r'\s+rel="[^"]*"', "", attrs)
            return "<a" + attrs + ">" + body + "</a>"

        updated, count = LINK.subn(replace_link, match.group(0))
        if count != 1:
            raise ValueError("Offer lacks one unique action.")
        return updated

    result = CARD.sub(transform, source)
    if found != {slug: 1 for slug in SLUGS}:
        raise ValueError("Exactly four approved offers required.")
    before, after = HERO.search(source), HERO.search(result)
    if before is None or after is None or before.group(0) != after.group(0):
        raise ValueError("The approved commercial hero must not change.")
    if result == source:
        raise ValueError("CI-only links were not changed.")
    if result.count(LOOPBACK_PORTAL) != 4:
        raise ValueError("Portal handoff links must occur exactly four times.")
    return result, found


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("built_comercial", type=Path)
    p.add_argument("lab_output", type=Path)
    args = p.parse_args()
    root = args.built_comercial.resolve(strict=True)
    output = args.lab_output.absolute()
    if root == output or root in output.parents or output.exists() or output.is_symlink():
        raise ValueError("Output must be a new separate lab directory.")
    if not root.is_dir() or root.is_symlink():
        raise ValueError("Compiled Comercial directory required.")
    files = [f for f in root.rglob("*") if f.is_file()]
    if (len(files) > 400 or sum(f.stat().st_size for f in files) > 45_000_000
        or any(f.is_symlink() or not stat.S_ISREG(f.lstat().st_mode) for f in files)):
        raise ValueError("Unsafe or oversized compiled tree.")
    home = root / "index.html"
    if not home.is_file() or home.is_symlink():
        raise ValueError("Compiled homepage is missing.")
    original = home.read_text(encoding="utf-8")
    changed, found = rewrite(original)
    shutil.copytree(root, output, symlinks=False)
    (output / "index.html").write_text(changed, encoding="utf-8")
    if home.read_text(encoding="utf-8") != original:
        raise ValueError("Original commercial build was modified.")
    print("PASS: four local-only Comercial offer links prepared; original build unchanged.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        # Refuse printing payload, directory path, URLs, or env values.
        print("BLOCKED: synthetic Comercial->Portal handoff (" + type(exc).__name__ + ").", file=sys.stderr)
        raise SystemExit(2)
