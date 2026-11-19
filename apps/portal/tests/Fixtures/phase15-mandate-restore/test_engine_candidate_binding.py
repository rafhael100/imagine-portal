#!/usr/bin/env python3
"""Offline CI guard: Portal candidate must be reproducibly bound to ONE engine SHA.

This is not a signature, SQL001-to-S2S bridge, consent evidence or Ads grant.
The engine checkout and all three CI workflows must match the candidate
build receipt pin. Negative tests only mutate strings in memory.
"""
from __future__ import annotations

import pathlib
import re
import subprocess

PIN = "22e85992bd7f5cea7b950edfa02a3a8255639952"
ROOT = pathlib.Path(__file__).resolve().parents[5]
WORKFLOWS = (
    ".github/workflows/performance-phase7-exact-compose.yml",
    ".github/workflows/performance-phase8-authority.yml",
    ".github/workflows/performance-phase8-recovery.yml",
)
RECEIPT = "apps/portal/tools/performance-release/build_receipt.py"
DOC = "apps/portal/tools/performance-release/README-BUILD.md"


def check_workflow(value: str) -> None:
    refs = re.findall(r"(?m)^\s+ref:\s*([a-f0-9]{40})\s*$", value)
    if refs != [PIN] or value.count("repository: rafhael100/imagine-performance-ai") != 1:
        raise ValueError("engine workflow pin disagrees with candidate")
    if "persist-credentials: false" not in value or "pull_request:" not in value:
        raise ValueError("candidate checkout safety requirements missing")


def check_receipt(value: str) -> None:
    pins = re.findall(r'(?m)^ENGINE_PIN = "([a-f0-9]{40})"$', value)
    if pins != [PIN]:
        raise ValueError("candidate build receipt engine pin disagrees")


def check_doc(value: str) -> None:
    if value.count(PIN) != 1:
        raise ValueError("candidate receipt documentation pin disagrees")


def check_checkout(value: str) -> None:
    if value != PIN:
        raise ValueError("checked-out engine does not match pinned candidate")


def denied(operation) -> None:
    try:
        operation()
    except ValueError:
        return
    raise AssertionError("negative candidate pin case incorrectly accepted")


def main() -> None:
    values = {path: (ROOT / path).read_text(encoding="utf-8")
              for path in (*WORKFLOWS, RECEIPT, DOC)}
    for path in WORKFLOWS:
        check_workflow(values[path])
    check_receipt(values[RECEIPT])
    check_doc(values[DOC])

    head = subprocess.check_output(
        ["git", "-C", str(ROOT / "engine"), "rev-parse", "HEAD"], text=True
    ).strip()
    check_checkout(head)

    # Deliberately adversarial cases, no writes to repository or checkout.
    for path in WORKFLOWS:
        denied(lambda p=path: check_workflow(values[p].replace(PIN, "0" * 40, 1)))
        denied(lambda p=path: check_workflow(values[p].replace(PIN, "unknown", 1)))
    denied(lambda: check_receipt(values[RECEIPT].replace(PIN, "f" * 40, 1)))
    denied(lambda: check_doc(values[DOC].replace(PIN, "f" * 40, 1)))
    denied(lambda: check_checkout("0" * 40))
    print("PORTAL_PERFORMANCE_ADS_PIN_PASS workflows=3 receipt=1 doc=1 "
          "checkout=1 negative_cases=9 offline=true operational=false")


if __name__ == "__main__":
    main()
