"""Isolated fixtures only: no Google account, real backups or secrets are read."""
import base64
import configparser
import contextlib
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
module_path = ROOT / 'docker/drive/drive-backup.py'
if not module_path.exists():
    module_path = Path('/opt/drive-backup.py')
spec = importlib.util.spec_from_file_location('drive', module_path)
drive = importlib.util.module_from_spec(spec)
spec.loader.exec_module(drive)
TIME = dt.datetime(2026, 10, 28, 18, 0, tzinfo=dt.timezone.utc)
FOLDER = 'fixture_folder_only_1234'
CONFIG = ('[gestion_stock_drive]\ntype = drive\nclient_id = 123-fixture.apps.googleusercontent.com\n'
          'client_secret = GOCSPX-fixture-only\nscope = drive\nroot_folder_id = ' + FOLDER + '\n'
          'token = {"access_token":"fixture-access", "refresh_token":"fixture-refresh"}\n')


def pair(directory, days=0, content=None, suffix='abcdef123456'):
    when = TIME - dt.timedelta(days=days)
    name = 'stock-' + when.strftime('%Y%m%dT%H%M%SZ') + '-' + suffix + '.backup.age'
    path = directory / name
    path.write_bytes(content if content is not None else drive.MAGIC + b'FAKE encrypted data for testing only\n')
    (directory / (name + '.sha256')).write_text(hashlib.sha256(path.read_bytes()).hexdigest() + '\n')
    return name


class FakeRemote:
    def __init__(self, directory):
        self.directory = directory
        self.operations = []
        self.fail_upload_side = False
        self.fail_delete_side = False
        self.bad_verify = False

    def listing(self):
        return {p.name: {'Size': p.stat().st_size, 'Hashes': {'md5': hashlib.md5(p.read_bytes()).hexdigest()}}
                for p in self.directory.iterdir() if p.is_file()}

    def upload(self, path):
        self.operations.append(('upload', path.name))
        if self.fail_upload_side and path.name.endswith('.sha256'):
            raise drive.Failure('drive_command_failed')
        target = self.directory / path.name
        if target.exists() and target.read_bytes() != path.read_bytes():
            raise drive.Failure('immutable_conflict')
        shutil.copyfile(path, target)

    def verify(self, directory, names):
        if self.bad_verify:
            raise drive.Failure('drive_command_failed')
        for name in names:
            if (directory / name).read_bytes() != (self.directory / name).read_bytes():
                raise drive.Failure('drive_command_failed')

    def download(self, name, path):
        self.operations.append(('download', name))
        shutil.copyfile(self.directory / name, path)

    def delete(self, name):
        self.operations.append(('delete', name))
        if self.fail_delete_side and name.endswith('.sha256'):
            raise drive.Failure('drive_command_failed')
        (self.directory / name).unlink(missing_ok=True)


class DriveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source, self.work, self.remote_dir = [self.root / name for name in ('source', 'work', 'remote')]
        for directory in (self.source, self.work, self.remote_dir):
            directory.mkdir()
        self.state = self.root / 'status.json'
        self.remote = FakeRemote(self.remote_dir)
        self.clock = patch.object(drive, 'now', return_value=TIME)
        self.clock.start()
        self.addCleanup(self.clock.stop)

    def sync(self):
        with contextlib.redirect_stdout(io.StringIO()):
            return drive.sync_once(self.source, self.work, self.state, self.remote)

    def test_new_pair_only_no_key_or_status_and_repeated_sync_is_idempotent(self):
        name = pair(self.source)
        for foreign in ('backup_identity', 'status.json', 'key.txt', '.lock', name + '.new'):
            (self.source / foreign).write_text('DO NOT UPLOAD fixture')
        result = self.sync()
        self.assertEqual(set(p.name for p in self.remote_dir.iterdir()), {name, name + '.sha256'})
        self.assertEqual(result['verified_count'], 1)
        self.assertEqual(stat.S_IMODE(self.state.stat().st_mode), 0o600)
        self.remote.operations.clear()
        self.sync()
        self.assertEqual(self.remote.operations, [])
        drive.health(self.state)

    def test_bad_local_checksum_refused_before_upload_or_retention(self):
        name = pair(self.source)
        (self.source / (name + '.sha256')).write_text('0' * 64)
        pair(self.remote_dir, 100)
        with self.assertRaisesRegex(drive.Failure, 'checksum'):
            self.sync()
        self.assertEqual(self.remote.operations, [])

    def test_plaintext_with_valid_checksum_refused(self):
        pair(self.source, content=b'plaintext not encrypted')
        with self.assertRaisesRegex(drive.Failure, 'unencrypted'):
            self.sync()
        self.assertEqual(self.remote.operations, [])

    def test_remote_same_name_wrong_bytes_refused_without_overwrite(self):
        pair(self.source)
        name = pair(self.remote_dir, content=drive.MAGIC + b'other bytes')
        before = (self.remote_dir / name).read_bytes()
        with self.assertRaisesRegex(drive.Failure, 'remote_backup_conflict'):
            self.sync()
        self.assertEqual((self.remote_dir / name).read_bytes(), before)
        self.assertEqual(self.remote.operations, [])

    def test_interrupted_sidecar_upload_recovers_without_duplicate(self):
        name = pair(self.source)
        self.remote.fail_upload_side = True
        with self.assertRaises(drive.Failure):
            self.sync()
        self.assertTrue((self.remote_dir / name).is_file())
        self.assertFalse(self.state.exists())
        self.remote.fail_upload_side = False
        self.sync()
        self.assertEqual(len(list(self.remote_dir.iterdir())), 2)

    def test_verification_failure_prevents_retention_and_success(self):
        pair(self.source)
        old = pair(self.remote_dir, 100)
        self.remote.bad_verify = True
        with self.assertRaises(drive.Failure):
            self.sync()
        self.assertTrue((self.remote_dir / old).is_file())
        self.assertFalse(self.state.exists())

    def test_retention_removes_verified_old_pair_only(self):
        new = pair(self.source)
        old = pair(self.remote_dir, 100)
        recent = pair(self.remote_dir, 89)
        orphan = pair(self.remote_dir, 101, suffix='111111111111')
        (self.remote_dir / (orphan + '.sha256')).unlink()
        invalid = pair(self.remote_dir, 102, suffix='222222222222')
        (self.remote_dir / (invalid + '.sha256')).write_text('0' * 64)
        (self.remote_dir / 'my-photo.jpg').write_bytes(b'keep')
        result = self.sync()
        self.assertFalse((self.remote_dir / old).exists())
        self.assertFalse((self.remote_dir / (old + '.sha256')).exists())
        for keep in (new, recent, orphan, invalid, 'my-photo.jpg'):
            self.assertTrue((self.remote_dir / keep).exists(), keep)
        self.assertEqual(result['removed_count'], 1)
        self.assertEqual(result['retention_skipped_count'], 1)

    def test_retention_interruption_resumes_verified_sidecar_cleanup(self):
        pair(self.source)
        old = pair(self.remote_dir, 100)
        self.remote.fail_delete_side = True
        with self.assertRaises(drive.Failure):
            self.sync()
        self.assertFalse((self.remote_dir / old).exists())
        self.assertTrue((self.remote_dir / (old + '.sha256')).exists())
        self.assertEqual(drive.read_state(self.state)['pending_delete']['name'], old)
        self.remote.fail_delete_side = False
        result = self.sync()
        self.assertFalse((self.remote_dir / (old + '.sha256')).exists())
        self.assertNotIn('pending_delete', result)

    def test_changed_pending_sidecar_is_not_deleted(self):
        pair(self.source)
        old = pair(self.remote_dir, 100)
        self.remote.fail_delete_side = True
        with self.assertRaises(drive.Failure):
            self.sync()
        (self.remote_dir / (old + '.sha256')).write_text('changed foreign contents')
        self.remote.fail_delete_side = False
        with self.assertRaisesRegex(drive.Failure, 'candidate_changed'):
            self.sync()
        self.assertTrue((self.remote_dir / (old + '.sha256')).exists())

    def test_stale_or_future_backup_refuses_all_remote_operations(self):
        for age in (2, -1):
            with self.subTest(age=age):
                name = pair(self.source, age)
                with self.assertRaisesRegex(drive.Failure, 'stale|future'):
                    self.sync()
                (self.source / name).unlink()
                (self.source / (name + '.sha256')).unlink()
        self.assertEqual(self.remote.operations, [])

    def test_symlink_archive_refused(self):
        name = pair(self.source)
        (self.source / name).rename(self.root / 'outside')
        (self.source / name).symlink_to(self.root / 'outside')
        with self.assertRaisesRegex(drive.Failure, 'invalid_backup_pair'):
            self.sync()

    def test_health_requires_recent_success_and_latest_attempt_success(self):
        pair(self.source)
        status = self.sync()
        status['last_attempt_ok'] = False
        drive.atomic_json(self.state, status)
        with self.assertRaisesRegex(drive.Failure, 'failed'):
            drive.health(self.state)
        status['last_attempt_ok'] = True
        status['last_success_at'] = (TIME - dt.timedelta(days=2)).isoformat()
        drive.atomic_json(self.state, status)
        with self.assertRaisesRegex(drive.Failure, 'stale'):
            drive.health(self.state)

    def test_config_folder_token_permissions_and_injection_validation(self):
        drive.parse_config(CONFIG, FOLDER)
        for bad in (CONFIG.replace(FOLDER, 'another_folder_123'), CONFIG.replace('refresh_token', 'missing'),
                    CONFIG + 'service_account_file = /private/key\n', CONFIG + '\n[second]\ntype = local\n'):
            with self.assertRaises(drive.Failure):
                drive.parse_config(bad, FOLDER)
        path = self.root / 'config.conf'
        path.write_text(CONFIG)
        path.chmod(0o644)
        with self.assertRaises(drive.Failure):
            drive.validate_config(path, FOLDER)
        path.chmod(0o600)
        drive.validate_config(path, FOLDER)

    def test_import_is_hidden_atomic_and_does_not_log_credentials(self):
        output = io.StringIO()
        target = self.root / 'config/rclone.conf'
        with patch.object(drive.getpass, 'getpass', return_value=base64.b64encode(CONFIG.encode()).decode()), contextlib.redirect_stdout(output):
            drive.import_config(target, FOLDER)
        self.assertEqual(target.read_text(), CONFIG)
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
        self.assertNotIn('fixture-access', output.getvalue())
        self.assertNotIn('fixture-only', output.getvalue())

    def test_rclone_errors_suppress_stderr_secrets_and_environment_overrides(self):
        with patch.dict(os.environ, {'RCLONE_CONFIG_PASS': 'secret-fixture', 'HTTPS_PROXY': 'http://fixture.invalid'}), patch.object(drive.subprocess, 'run') as run:
            run.return_value = subprocess.CompletedProcess([], 1, b'', b'fixture-access SECRET')
            with self.assertRaisesRegex(drive.Failure, '^drive_command_failed$'):
                drive.Rclone(self.root / 'config').call('lsjson', 'fixture:')
            self.assertNotIn('RCLONE_CONFIG_PASS', run.call_args.kwargs['env'])
            self.assertNotIn('HTTPS_PROXY', run.call_args.kwargs['env'])

    def test_listing_rejects_duplicate_names_and_occupied_destinations(self):
        rclone = drive.Rclone(self.root / 'config')
        name = pair(self.source)
        with patch.object(rclone, 'call', return_value=json.dumps([{'Path': name}, {'Path': name}]).encode()):
            with self.assertRaisesRegex(drive.Failure, 'duplicate'):
                rclone.listing()
        rows = [{'Path': name, 'Size': 100}, {'Path': 'foreign.txt'},
                {'Path': name + '.sha256', 'MimeType': 'application/vnd.google-apps.shortcut'}]
        with patch.object(rclone, 'call', return_value=json.dumps(rows).encode()):
            with self.assertRaisesRegex(drive.Failure, 'occupied'):
                rclone.listing()
        with patch.object(rclone, 'call', return_value=json.dumps(rows[:2]).encode()):
            self.assertEqual(list(rclone.listing()), [name])


class LocalRcloneTest(unittest.TestCase):
    """Real rclone command semantics, with a local fixture instead of Google Drive."""
    @unittest.skipUnless(shutil.which('rclone'), 'rclone not on PATH')
    def test_real_upload_check_idempotence_and_retention_local_backend(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for name in ('local', 'remote', 'work'):
                (root / name).mkdir()
            config = root / 'rclone.conf'
            config.write_text('[gestion_stock_drive]\ntype = alias\nremote = ' + str(root / 'remote') + '\n')
            new = pair(root / 'local')
            old = pair(root / 'remote', 100)
            remote = drive.Rclone(config)
            with patch.object(drive, 'now', return_value=TIME), contextlib.redirect_stdout(io.StringIO()):
                drive.sync_once(root / 'local', root / 'work', root / 'status.json', remote)
                drive.sync_once(root / 'local', root / 'work', root / 'status.json', remote)
            self.assertFalse((root / 'remote' / old).exists())
            self.assertEqual(len(list((root / 'remote').iterdir())), 2)
            self.assertEqual((root / 'local' / new).read_bytes(), (root / 'remote' / new).read_bytes())


if __name__ == '__main__':
    unittest.main(verbosity=2)
