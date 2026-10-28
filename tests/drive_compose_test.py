"""Exercise named-volume permissions of the separate stack, using empty fixture volumes."""
import json
import os
from pathlib import Path
import subprocess
import uuid

ROOT = Path(__file__).resolve().parents[1]
project = 'gestion-stock-drive-fixture-' + uuid.uuid4().hex[:12]
volume = project + '-source'
env = dict(os.environ, DRIVE_RELEASE_ID='ops05-test', DRIVE_FOLDER_ID='fixture_folder_only_1234',
           DRIVE_BACKUP_ENABLED='0', BACKUP_VOLUME_NAME=volume)
compose = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'compose.drive.yaml')]


def call(args, **kwargs):
    return subprocess.run(args, env=env, check=True, capture_output=True, text=True, **kwargs).stdout.strip()


try:
    call(['docker', 'volume', 'create', '--label', 'gestion-stock.fixture=drive-ops05', volume])
    call(compose + ['up', '-d', '--no-build', '--pull', 'never'])
    container = call(compose + ['ps', '-q', 'drive'])
    details = json.loads(call(['docker', 'inspect', container]))[0]
    assert details['Config']['User'] == '10001:10001'
    assert details['HostConfig']['ReadonlyRootfs'] is True
    assert not details['HostConfig']['PortBindings']
    assert 'ALL' in [item.upper() for item in details['HostConfig']['CapDrop']]
    mounts = {mount['Destination']: mount for mount in details['Mounts']}
    assert mounts['/backups']['RW'] is False
    assert mounts['/drive-config']['RW'] is True
    assert mounts['/drive-state']['RW'] is True
    assert not any('recovery' in mount['Destination'] or 'secret' in mount['Destination'] for mount in details['Mounts'])
    call(compose + ['exec', '-T', 'drive', 'python3', '-c',
        'from pathlib import Path; import os; '
        'assert os.getuid()==10001; '
        'p=Path("/drive-config/fixture");p.write_text("fixture");p.unlink(); '
        's=Path("/drive-state/fixture");s.write_text("fixture");s.unlink()'])
    readonly = subprocess.run(compose + ['exec', '-T', 'drive', 'touch', '/backups/forbidden'], env=env, capture_output=True)
    assert readonly.returncode != 0
    call(compose + ['exec', '-T', 'drive', 'python3', '/opt/drive-backup.py', 'health'])
    missing = subprocess.run(compose + ['exec', '-T', '-e', 'DRIVE_BACKUP_ENABLED=1', 'drive',
                             'python3', '/opt/drive-backup.py', 'once'], env=env, capture_output=True, text=True)
    assert missing.returncode == 1
    assert 'drive_configuration_missing_or_permissions' in missing.stdout
    health = subprocess.run(compose + ['exec', '-T', '-e', 'DRIVE_BACKUP_ENABLED=1', 'drive',
                            'python3', '/opt/drive-backup.py', 'health'], env=env, capture_output=True, text=True)
    assert health.returncode == 1
    print('Stack isolée : volumes privés accessibles sous UID 10001, source en lecture seule, aucun port, aucun secret applicatif et refus sans autorisation Google.')
finally:
    subprocess.run(compose + ['down', '--volumes', '--remove-orphans'], env=env, capture_output=True)
    subprocess.run(['docker', 'volume', 'rm', volume], env=env, capture_output=True)
