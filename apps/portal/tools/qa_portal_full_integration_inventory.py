#!/usr/bin/env python3
"""Full, review-only Git diff inventory for the Imagine Portal integration gate.

Unlike GitHub's REST comparison file listing, this reads the complete local
Git history. It never merges, deploys, connects to the VPS or approves release.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
from pathlib import Path
import subprocess
import sys


def git(*args: str) -> bytes:
    return subprocess.run(
        ["git", *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    ).stdout


def sha(revision: str) -> str:
    value = git("rev-parse", "--verify", f"{revision}^{{commit}}").decode().strip()
    if len(value) != 40 or any(c not in "0123456789abcdef" for c in value):
        raise ValueError("Unexpected Git commit identifier.")
    return value


def group(path: str) -> str:
    # Categorization is a triage hint, NOT an AppSec/DBA determination.
    if (
        "/database/" in path
        or path.startswith("infra/")
        or "/Infrastructure/Identity/" in path
        or "/Domain/Identity/" in path
        or "/Http/" in path
        or "/routes/" in path
        or path.endswith(".sql")
    ):
        return "P0_authority_infrastructure_or_runtime"
    if (
        "/resources/views/" in path
        or "/Presentation/" in path
        or path.endswith((".css", ".html"))
    ):
        return "P1_interface"
    if (
        "/tests/" in path
        or path.startswith(".github/workflows/")
        or "/docs/" in path
        or path.endswith((".md", ".yml", ".yaml"))
    ):
        return "P2_tests_workflows_docs"
    return "P3_other_manual_review"


def inventory(base: str, head: str) -> dict:
    base_sha, head_sha = sha(base), sha(head)
    merge_base = git("merge-base", base_sha, head_sha).decode().strip()
    behind, ahead = map(
        int, git("rev-list", "--left-right", "--count", f"{base_sha}...{head_sha}")
        .decode().strip().split()
    )

    raw = git("diff", "--name-status", "--find-renames", "-z", merge_base, head_sha)
    tokens = raw.split(b"\x00")
    if tokens[-1:] == [b""]:
        tokens.pop()
    files: list[dict[str, str]] = []
    i = 0
    while i < len(tokens):
        status = tokens[i].decode("ascii", errors="strict")
        i += 1
        if not status or status[0] not in "ACDMRTUXB":
            raise ValueError("Unknown Git diff status.")
        is_move = status[0] in "RC"
        if i + (2 if is_move else 1) > len(tokens):
            raise ValueError("Truncated null-delimited diff.")
        old_path = tokens[i].decode("utf-8", errors="replace")
        i += 1
        if is_move:
            path = tokens[i].decode("utf-8", errors="replace")
            i += 1
        else:
            path = old_path
        files.append({
            "status": status,
            "path": path,
            "previous_path": old_path if is_move else "",
            "triage_bucket": group(path),
        })

    files.sort(key=lambda f: (f["triage_bucket"], f["path"]))
    counts = Counter(f["triage_bucket"] for f in files)
    return {
        "schema": "imagine.portal.integration-review-inventory.v1",
        "review_only": True,
        "safe_to_merge": False,
        "production_approved": False,
        "source": "local_git_full_history_not_github_api",
        "base_ref": base,
        "base_sha": base_sha,
        "head_sha": head_sha,
        "merge_base_sha": merge_base,
        "commits_ahead": ahead,
        "commits_behind": behind,
        "total_changed_files": len(files),
        "counts_by_triage_bucket": dict(sorted(counts.items())),
        "files": files,
    }


def write_report(result: dict, destination: Path) -> None:
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    (destination / "inventory.json").write_text(
        json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    lines = [
        "# Imagine Portal - inventário de integração, somente revisão",
        "",
        "**NÃO AUTORIZA MERGE, DEPLOY, DADOS REAIS OU ACESSO DE CLIENTES.**",
        "",
        f"Base: {result['base_ref']} ({result['base_sha']})",
        f"Candidato: {result['head_sha']}",
        f"Ancestral comum: {result['merge_base_sha']}",
        f"Commits à frente: **{result['commits_ahead']}**",
        f"Commits atrás: **{result['commits_behind']}**",
        f"Total de arquivos alterados: **{result['total_changed_files']}**",
        "",
        "## Triagem de arquivos (indicativa; não substitui revisão)",
        "",
    ]
    for key, count in sorted(result["counts_by_triage_bucket"].items()):
        lines.append(f"- {key}: {count}")
    lines += [
        "",
        "O inventário completo, sem limite de 300 arquivos, está no",
        "inventory.json deste mesmo artefato.",
        "Nenhuma aprovação operacional foi inferida de testes sintéticos.",
    ]
    (destination / "summary.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", required=True, type=Path)
    args = parser.parse_args()

    # Both refs are fixed by the workflow. The head is the checked-out PR SHA.
    base = "origin/dev/fast-track-portal-2026-11-05"
    result = inventory(base=base, head="HEAD")
    if result["total_changed_files"] != len(result["files"]):
        raise RuntimeError("Incomplete Git inventory.")
    write_report(result, args.out)
    print(json.dumps({
        "ahead": result["commits_ahead"],
        "behind": result["commits_behind"],
        "changed_files": result["total_changed_files"],
        "review_only": True,
        "production_approved": False,
    }))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, subprocess.CalledProcessError, RuntimeError, ValueError) as error:
        print("Git full-diff inventory failed closed: " + str(error)[:200], file=sys.stderr)
        sys.exit(2)
