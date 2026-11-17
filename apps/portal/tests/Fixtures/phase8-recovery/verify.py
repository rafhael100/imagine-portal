#!/usr/bin/env python3
"""Assertions and sanitized evidence for the disposable recovery exercise."""
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys


def sanitized_error(_kind, error, _traceback):
    action = sys.argv[1] if len(sys.argv) > 1 and re.fullmatch('[a-z]+', sys.argv[1]) else 'unknown'
    message = str(error)
    detail = message if re.fullmatch('[a-z_]+', message) else 'assertion'
    print(f'PHASE8_VERIFICATION_DENIED action={action} check={detail}', file=sys.stderr)


sys.excepthook = sanitized_error

assert os.environ.get('GITHUB_ACTIONS') == 'true'
assert os.environ.get('ADMIN_PHASE8_RECOVERY_CI') == '1'
root = Path(os.environ['P8_PRIVATE'])
assert root == Path(os.environ['RUNNER_TEMP']) / f"phase8-recovery-{os.environ['GITHUB_RUN_ID']}-{os.environ['GITHUB_RUN_ATTEMPT']}"


def read(name):
    return json.loads((root / name).read_text())


def write(name, value):
    (root / name).write_text(json.dumps(value, indent=2))


def frozen_equal(before, after, separate=False):
    assert before['kind'] == after['kind']
    assert (before['source_cluster'] != after['source_cluster']) if separate else (before['source_cluster'] == after['source_cluster'])
    for key in ['tables', 'profile', 'authority', 'append', 'sequences', 'sessions']:
        assert before[key] == after[key], f'frozen_{key}_{before["kind"]}'
    if before['kind'] == 'portal':
        assert before['sessions']['encrypted'] and before['sessions']['count'] > 0


action = sys.argv[1]
if action == 'manifest':
    (root / 'key').write_bytes(secrets.token_bytes(32))
    (root / 'key').chmod(0o600)
    workspace = Path(os.environ['GITHUB_WORKSPACE'])
    write('manifest.json', {
        'generation': secrets.token_hex(16),
        'portal_sha': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=workspace, text=True).strip(),
        'engine_sha': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=workspace / 'engine', text=True).strip(),
        'compose_sha256': hashlib.sha256((workspace / 'apps/portal/deploy/phase6/compose.yaml').read_bytes()).hexdigest(),
    })
elif action == 'tamper':
    damaged = bytearray((root / 'paired.enc').read_bytes())
    damaged[-1] ^= 1
    (root / 'tampered.enc').write_bytes(damaged)
    (root / 'wrong-key').write_bytes(secrets.token_bytes(32))
    (root / 'wrong-key').chmod(0o600)
    other = read('manifest.json')
    other['generation'] = secrets.token_hex(16)
    write('wrong-generation.json', other)
elif action in ['restart', 'baseline']:
    for kind in ['portal', 'receiver']:
        if action == 'restart':
            frozen_equal(read(f'restart-before-{kind}.json'), read(f'restart-after-{kind}.json'))
        else:
            frozen_equal(read(f'baseline-{kind}.json'), read(f'restored-baseline-{kind}.json'), separate=True)
elif action == 'finish':
    counts = {}
    for kind in ['portal', 'receiver']:
        current = read(f'current-{kind}.json')
        frozen_equal(current, read(f'final-source-{kind}.json'))
        restored = read(f'final-restored-{kind}.json')
        assert current['source_cluster'] != restored['source_cluster']
        assert current['authority'] == restored['authority']
        assert current['profile'] == restored['profile']
        for table, expected in current['append'].items():
            assert all(row in restored['append'][table] for row in expected), 'persistent_current_append'
        counts[kind] = {table: info['count'] for table, info in restored['tables'].items()}
    before_engine = read('baseline-receiver.json')
    current_engine = read('current-receiver.json')
    for table in ['performance_admin_inbox.receipts', 'performance_admin_inbox.nonces', 'performance_admin_inbox.request_audit']:
        assert len(current_engine['append'][table]) > len(before_engine['append'][table]), 'post_snapshot_event_missing'
    report = {
        'schema': 'performance.phase8.recovery.integrity.v1',
        'sources': read('manifest.json'),
        'checks': [
            'same_six_containers_restart_without_replacement',
            'all_table_content_and_sequences_preserved_on_restart',
            'paired_dumps_taken_with_application_writers_stopped',
            'xchacha20poly1305_authentication_before_plaintext',
            'tampered_ciphertext_denied_without_output',
            'wrong_key_denied_without_output',
            'wrong_generation_denied_without_output',
            'existing_decryption_destination_denied',
            'separate_new_postgresql_clusters',
            'all_table_content_sequences_rls_column_acl_memberships_preserved',
            'nonempty_restore_target_denied',
            'both_stale_authorities_denied_before_application_start',
            'current_reference_outside_restored_archive',
            'monotonic_revocation_reconciled_without_credential_or_scope_writes',
            'post_snapshot_audits_receipts_nonces_inserted_without_overwrite',
            'both_current_authority_gates_required_before_runtime',
            'restored_runtime_database_tls_and_least_privilege_verified',
            'restored_reader_denies_revoked_delegation_and_resolves_active_company',
            'restored_nonce_unique_constraint_denies_duplicate',
            'protected_keys_unchanged',
            'original_database_contents_unchanged_by_restore',
        ],
        'restored_table_counts': counts,
        'source': 'synthetic',
        'compose_overlay': False,
        'session_driver': 'database',
        'external_custody_verified': False,
        'operational_antirollback_anchor_verified': False,
        'deployment_authorized': False,
        'external_operations_enabled': False,
        'billable': False,
    }
    destination = Path(os.environ['P8_EVIDENCE']) / 'integrity.json'
    destination.write_text(json.dumps(report, indent=2))
else:
    raise AssertionError('unknown_action')
print(f'PHASE8_VERIFICATION_OK action={action}')
