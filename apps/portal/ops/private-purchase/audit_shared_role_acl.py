#!/usr/bin/env python3
"""CI-only fail-closed PostgreSQL role/ownership release-risk audit.

Reads metadata from an already isolated PostgreSQL18 fixture, NEVER any rows,
passwords, person names, or customer IDs. The CURRENT unsafe ACL is expected
and MUST produce NO-GO; a privilege-policy change requires independent review.
"""
from __future__ import annotations
import argparse
from hashlib import sha256
import json
from pathlib import Path
import sys

FIELDS = {
    "customer_table_select": bool,
    "orders_table_select": bool,
    "customers_force_rls": bool,
    "orders_force_rls": bool,
    "customer_rls_scopes_actor": bool,
    "orders_rls_scopes_actor": bool,
    "role_superuser": bool,
    "role_bypassrls": bool,
}
SCHEMA = "imagine.p0.current-customer-sql-release-risk.v1"


def evidence_from_metadata(value: object) -> dict:
    if not isinstance(value, dict) or set(value) != set(FIELDS):
        raise ValueError("Unsafe or incomplete SQL privilege metadata.")
    if any(type(value[k]) is not t for k, t in FIELDS.items()):
        raise ValueError("SQL privilege metadata must have exact Boolean types.")
    # Intentionally require the observed OPEN risk. A future SQL fix cannot
    # silently become GO by merely editing a grant or the test expected values.
    expected = {
        "customer_table_select": True,
        "orders_table_select": True,
        "customers_force_rls": True,
        "orders_force_rls": True,
        "customer_rls_scopes_actor": False,
        "orders_rls_scopes_actor": False,
        "role_superuser": False,
        "role_bypassrls": False,
    }
    if value != expected:
        raise ValueError("Privilege findings changed: require independent owner/DBA review.")
    claims = {
        "schema": SCHEMA,
        "source": "actual-private-preview-postgresql18-metadata-only",
        "shared_login_selects_customer_rows_without_per_customer_db_fence": True,
        "shared_login_selects_order_rows_without_per_customer_db_fence": True,
        "php_where_customer_filter_is_not_sql_authority": True,
        "release_gate": "NO_GO",
        "app_runtime_customer_db_privileges_hardened": False,
        "per_customer_sql_owner_isolation_verified": False,
        "can_enable_real_customer_data": False,
        "can_enable_public_registration": False,
        "capability_candidate_707_deployed": False,
        "migration_review_required": True,
        "independent_appsec_dba_review_required": True,
        "no_customer_rows_read": True,
    }
    canonical = json.dumps(claims, separators=(",", ":"), ensure_ascii=False)
    return {
        "claims": claims,
        "integrity_sha256_not_signature": sha256(canonical.encode()).hexdigest(),
        "authorized_deploy": False,
    }


def self_test() -> None:
    safe = {
        "customer_table_select": True,
        "orders_table_select": True,
        "customers_force_rls": True,
        "orders_force_rls": True,
        "customer_rls_scopes_actor": False,
        "orders_rls_scopes_actor": False,
        "role_superuser": False,
        "role_bypassrls": False,
    }
    assert evidence_from_metadata(safe)["claims"]["release_gate"] == "NO_GO"
    for key in FIELDS:
        changed = dict(safe)
        changed[key] = not changed[key]
        try:
            evidence_from_metadata(changed)
        except ValueError:
            pass
        else:
            raise AssertionError("Privilege drift did not fail closed.")
    for invalid in (None, [], {"customer_table_select": True}, dict(safe, unauthorized=True)):
        try:
            evidence_from_metadata(invalid)
        except ValueError:
            pass
        else:
            raise AssertionError("Malformed privilege evidence accepted.")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--input", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.self_test:
        if args.input or args.output:
            parser.error("--self-test takes no files.")
        self_test()
        print("PASS: fail-closed privilege release gate and ten adversarial metadata cases.")
        return 0
    if not args.input or not args.output:
        parser.error("Both --input and --output are required.")
    if (not args.input.is_file() or args.input.is_symlink()
        or args.input.stat().st_size > 2000
        or args.output.exists() or args.output.is_symlink()):
        raise ValueError("Evidence sources must be small, new and regular.")
    data = json.loads(args.input.read_text(encoding="utf-8"))
    report = evidence_from_metadata(data)
    if args.output.parent.resolve() != args.input.parent.resolve():
        raise ValueError("Evidence report must remain within the same private CI directory.")
    args.output.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print("PASS: actual CI PostgreSQL ACL inspected; real-customer rollout remains NO-GO.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, json.JSONDecodeError, AssertionError) as exc:
        print("NO-GO metadata audit refused (" + type(exc).__name__ + ").", file=sys.stderr)
        raise SystemExit(2)
