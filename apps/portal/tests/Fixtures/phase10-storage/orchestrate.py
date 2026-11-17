#!/usr/bin/env python3
"""Guarded, synthetic-only Docker handoff; no arbitrary target path/volume input."""
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile


stage = 'guard'
VOLUME_PROJECTION = '{"Name":{{json .Name}},"Driver":{{json .Driver}},"Scope":{{json .Scope}},"CreatedAt":{{json .CreatedAt}},"Project":{{json (index .Labels "com.docker.compose.project")}},"LogicalName":{{json (index .Labels "com.docker.compose.volume")}},"HasOptions":{{if .Options}}true{{else}}false{{end}}'
CONTAINER_PROJECTION = '{"running":{{json .State.Running}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"portal_image":{{if eq (index .Config.Labels "com.docker.compose.service") "portal"}}{{json .Image}}{{else}}null{{end}},"storage_mounts":[{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{"type":{{json .Type}},"name":{{json .Name}},"destination":{{json .Destination}}}{{end}}{{end}}]}'


def checked(value, name):
    if not value:
        raise RuntimeError(name)


def command(args, *, input=None):
    return subprocess.run(args, input=input, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=True, timeout=180).stdout


def docker_json(*args):
    return json.loads(command(['docker', *args]))


def sanitized_diagnostics(value):
    if isinstance(value, bytes):
        value = value.decode('utf-8', errors='replace')
    for line in (value or '').splitlines():
        if re.fullmatch(r'PHASE10_STORAGE_DENIED stage=[a-z-]+ check=[a-z_]+', line):
            print(line, file=sys.stderr)


def inspect_volume(name):
    result = subprocess.run(['docker', 'volume', 'inspect', '--format', VOLUME_PROJECTION, name], text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    if result.returncode:
        # Absence is checked independently with the matching name inventory;
        # a Docker connection/permission error must never be treated as absence.
        names = command(['docker', 'volume', 'ls', '--filter', f'name={name}', '--format', '{{.Name}}']).splitlines()
        checked(name not in names, 'volume_inspection_failed')
        return None
    volume = json.loads(result.stdout)
    checked(isinstance(volume, dict), 'volume_identity')
    return volume


def volume_identity(volume, name, project, logical):
    checked(volume is not None and volume['Name'] == name, 'volume_name')
    checked(volume['Driver'] == 'local' and volume['Scope'] == 'local' and volume['HasOptions'] is False, 'volume_driver')
    checked(volume['Project'] == project and volume['LogicalName'] == logical, 'volume_labels')
    return volume


def private_json(name, value):
    with tempfile.NamedTemporaryFile(mode='w', dir=private, prefix='phase10-', delete=False) as stream:
        json.dump(value, stream)
        temporary = stream.name
    try:
        command(['sudo', 'install', '-m', '400', '-o', '33', '-g', '33', temporary, str(work / name)])
    finally:
        Path(temporary).unlink()


def read_private(name):
    return json.loads(command(['sudo', 'cat', str(work / name)]))


def assert_quiesced():
    portal_image = None
    for service in ['portal', 'portal-web', 'receiver', 'performance-web']:
        ids = command(['docker', 'compose', '-p', project, '-f', str(compose), 'ps', '-aq', service]).splitlines()
        checked(len(ids) == 1, 'source_service_identity')
        item = docker_json('inspect', '--format', CONTAINER_PROJECTION, ids[0])
        checked(item['project'] == project and item['service'] == service,
                'source_service_labels')
        checked(item['running'] is False, 'source_writers_must_be_stopped')
        if service == 'portal':
            mounts = item['storage_mounts']
            checked(len(mounts) == 1 and mounts[0]['type'] == 'volume' and mounts[0]['name'] == source_name, 'source_storage_mount')
            portal_image = item['portal_image']
            checked(isinstance(portal_image, str) and re.fullmatch(r'sha256:[a-f0-9]{64}', portal_image) is not None, 'source_image_identity')
    return portal_image


def one_shot(action, image_id, *, source=None, target=None, root=False, source_writable=False):
    name = f'{test_project}-{action}'
    args = ['docker', 'create', '--name', name, '--network', 'none', '--read-only',
            '--user', '0:0' if root else '33:33', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--entrypoint', 'php',
            '--label', 'imagine.performance.fixture=phase10-storage',
            '--label', f'imagine.performance.ci-run={run_id}-{attempt}',
            '-e', 'GITHUB_ACTIONS=true', '-e', 'ADMIN_PHASE10_STORAGE_CI=1',
            '--mount', f'type=bind,src={fixture},dst=/run/storage-fixtures,readonly',
            '--mount', f'type=bind,src={tools},dst=/run/storage-tools,readonly',
            '--mount', f'type=bind,src={work},dst=/run/storage-work']
    if root:
        # The initializer changes only the owner of a literally empty root.
        # chmod is performed before chown; no FOWNER/DAC override is granted.
        args += ['--cap-add', 'CHOWN']
    if source:
        args += ['--mount', f'type=volume,src={source},dst=/run/storage-source,volume-nocopy' + ('' if source_writable else ',readonly')]
    if target:
        args += ['--mount', f'type=volume,src={target},dst=/run/storage-target,volume-nocopy']
    args += [image_id, '/run/storage-fixtures/fixture.php', action]
    created_id = None
    started = None
    try:
        candidate = command(args).strip()
        checked(re.fullmatch(r'[a-f0-9]{64}', candidate) is not None, 'created_container_identity')
        created_id = candidate
        started = subprocess.run(['docker', 'start', '--attach', created_id], text=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False, timeout=180)
        exit_code = command(['docker', 'inspect', '--format', '{{.State.ExitCode}}', created_id]).strip()
        checked(started.returncode == 0 and exit_code == '0', 'container_exit_status')
        return started.stdout
    except Exception as error:
        if started is not None:
            sanitized_diagnostics(started.stdout)
            sanitized_diagnostics(started.stderr)
        if isinstance(error, (subprocess.CalledProcessError, subprocess.TimeoutExpired)):
            sanitized_diagnostics(error.stderr)
            sanitized_diagnostics(error.stdout)
        raise
    finally:
        # A name collision leaves created_id unset. Never remove a container
        # merely because it happens to use the name we wanted to create.
        if created_id is not None:
            subprocess.run(['docker', 'rm', '-f', created_id], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)


try:
    checked(os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('ADMIN_PHASE10_STORAGE_CI') == '1', 'ci_only')
    run_id, attempt = os.environ['GITHUB_RUN_ID'], os.environ['GITHUB_RUN_ATTEMPT']
    checked(run_id.isdecimal() and attempt.isdecimal(), 'ci_identity')
    project = f'imagine-performance-p7-ci-{run_id}-{attempt}'
    restored_project = f'imagine-performance-p8-restore-ci-{run_id}-{attempt}'
    test_project = f'imagine-performance-p10-ci-{run_id}-{attempt}'
    checked(os.environ['P7_PROJECT'] == project and os.environ['P8_PROJECT'] == restored_project, 'project_identity')
    workspace = Path(os.environ['GITHUB_WORKSPACE'])
    private = Path(os.environ['P8_PRIVATE'])
    materials = Path(os.environ['P6_CI_MATERIAL'])
    checked(private == Path(os.environ['RUNNER_TEMP']) / f'phase8-recovery-{run_id}-{attempt}', 'private_path')
    checked(materials == Path(os.environ['RUNNER_TEMP']) / f'phase7-{run_id}-{attempt}', 'material_path')
    fixture = workspace / 'apps/portal/tests/Fixtures/phase10-storage'
    tools = workspace / 'apps/portal/tools/performance-recovery'
    compose = workspace / 'apps/portal/deploy/phase6/compose.yaml'
    work = materials / 'storage-recovery'
    source_name, target_name = f'{project}_portal_storage', f'{restored_project}_portal_storage'
    negative_name = f'{test_project}_negative'
    checked(len(sys.argv) == 2 and sys.argv[1] in ['capture', 'restore'], 'action')
    action = sys.argv[1]
    source_image_id = assert_quiesced()
    source_identity = volume_identity(inspect_volume(source_name), source_name, project, 'portal_storage')

    if action == 'capture':
        stage = 'capture'
        checked(not work.exists(), 'fresh_private_handoff')
        checked(inspect_volume(target_name) is None, 'target_must_not_exist_at_capture')
        checked(not command(['docker', 'volume', 'ls', '-q', '--filter', f'label=com.docker.compose.project={restored_project}']).strip(), 'new_target_project_required')
        checked(not command(['docker', 'ps', '-aq', '--filter', f'label=com.docker.compose.project={restored_project}']).strip(), 'new_target_containers_required')
        image_id = docker_json('image', 'inspect', '--format', '{{json .Id}}', os.environ['P6_PORTAL_IMAGE'])
        checked(re.fullmatch(r'sha256:[a-f0-9]{64}', image_id) is not None, 'image_identity')
        checked(image_id == source_image_id, 'source_container_image_binding')
        command(['sudo', 'install', '-d', '-m', '700', '-o', '33', '-g', '33', str(work)])
        for source, destination in [('key', 'key'), ('manifest.json', 'manifest.json'), ('paired.enc', 'paired.enc')]:
            command(['sudo', 'install', '-m', '400', '-o', '33', '-g', '33', str(private / source), str(work / destination)])
        private_json('creation-request.json', {
            'schema': 'performance.phase10.storage.creation.v1', 'run': run_id, 'attempt': attempt,
            'target_project': restored_project, 'target_name': target_name, 'target_absence_observed': True,
            'not_before_epoch': int(datetime.datetime.now(datetime.timezone.utc).timestamp()),
            'source': source_identity, 'image_id': image_id,
        })
        # These are explicit synthetic sentinels, written once before the read-
        # only snapshot. No runtime or restore ever receives the backup key.
        one_shot('seed-source', image_id, source=source_name, source_writable=True)
        one_shot('capture', image_id, source=source_name)
        print('PHASE10_STORAGE_CAPTURE_PASS: read-only source snapshot bound to authenticated paired databases')
    else:
        stage = 'fresh-target'
        request = read_private('creation-request.json')
        checked(request['schema'] == 'performance.phase10.storage.creation.v1' and request['run'] == run_id and request['attempt'] == attempt
                and request['target_project'] == restored_project and request['target_name'] == target_name
                and request['target_absence_observed'] is True and request['source'] == source_identity, 'creation_receipt')
        image_id = request['image_id']
        checked(image_id == source_image_id, 'source_container_image_binding')
        checked(docker_json('image', 'inspect', '--format', '{{json .Id}}', image_id) == image_id, 'same_image_required')
        # up --wait portal-db performance-db may create declared named volumes;
        # it must not have mounted portal_storage in any container yet.
        checked(not command(['docker', 'ps', '-aq', '--filter', f'volume={target_name}']).strip(), 'target_must_never_have_been_mounted')
        volume = inspect_volume(target_name)
        if volume is None:
            command(['docker', 'volume', 'create', '--label', f'com.docker.compose.project={restored_project}',
                     '--label', 'com.docker.compose.volume=portal_storage', target_name])
            volume = inspect_volume(target_name)
        target_identity = volume_identity(volume, target_name, restored_project, 'portal_storage')
        created = datetime.datetime.fromisoformat(target_identity['CreatedAt'].replace('Z', '+00:00')).timestamp()
        checked(created >= request['not_before_epoch'], 'target_predates_current_run')
        private_json('target-receipt.json', {'request': request, 'volume': target_identity})
        checked(inspect_volume(negative_name) is None, 'fresh_negative_volume_required')
        command(['docker', 'volume', 'create', '--label', f'com.docker.compose.project={test_project}',
                 '--label', 'com.docker.compose.volume=negative', negative_name])
        negative_identity = volume_identity(inspect_volume(negative_name), negative_name, test_project, 'negative')
        try:
            stage = 'negative-proof'
            one_shot('initialize', image_id, target=negative_name, root=True)
            one_shot('negatives', image_id, target=negative_name)
            checked(volume_identity(inspect_volume(target_name), target_name, restored_project, 'portal_storage') == target_identity, 'target_identity_changed')
            stage = 'restore'
            one_shot('initialize', image_id, target=target_name, root=True)
            one_shot('restore', image_id, target=target_name)
            one_shot('verify', image_id, source=source_name, target=target_name)
            checked(volume_identity(inspect_volume(source_name), source_name, project, 'portal_storage') == source_identity, 'source_volume_changed')
            checked(volume_identity(inspect_volume(target_name), target_name, restored_project, 'portal_storage') == target_identity, 'restored_volume_changed')
            report = read_private('evidence.json')
            report['immutable_portal_image'] = image_id
            evidence = Path(os.environ['RUNNER_TEMP']) / 'phase10-storage-evidence'
            evidence.mkdir(mode=0o700, exist_ok=True)
            (evidence / 'checks.json').write_text(json.dumps(report, indent=2))
        finally:
            # Only the new negative-test volume created by this invocation is
            # removed here. P7/P8 volumes remain owned by their existing cleanup.
            if volume_identity(inspect_volume(negative_name), negative_name, test_project, 'negative') == negative_identity:
                command(['docker', 'volume', 'rm', negative_name])
        print('PHASE10_STORAGE_RESTORE_PASS: fresh volume, authenticated binding, exact file digests/modes and unchanged source')
except Exception as error:
    check = str(error) if re.fullmatch('[a-z_]+', str(error)) else 'execution'
    print(f'PHASE10_STORAGE_ORCHESTRATION_DENIED stage={stage} check={check}', file=sys.stderr)
    sys.exit(1)
