#!/usr/bin/env python3
"""Copy verified encrypted backup pairs; never synchronize or delete arbitrary Drive files."""
import argparse
import base64
import configparser
import datetime as dt
import fcntl
import getpass
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import time

UTC = dt.timezone.utc
NAME = re.compile(r'^stock-(\d{8}T\d{6}Z)-[a-f0-9]{12}\.backup\.age$')
REMOTE = 'gestion_stock_drive:'
MAGIC = b'age-encryption.org/v1\n'


class Failure(Exception):
    """Only fixed, non-secret error codes may be logged."""


def now():
    return dt.datetime.now(UTC)


def log(code, **values):
    print(json.dumps({'time': now().isoformat(), 'code': code, **values}), flush=True)


def integer(name, default, low, high):
    raw = os.environ.get(name, str(default))
    if not re.fullmatch(r'[0-9]+', raw) or not low <= int(raw) <= high:
        raise Failure('invalid_setting')
    return int(raw)


def enabled():
    value = os.environ.get('DRIVE_BACKUP_ENABLED', '0')
    if value not in ('0', '1'):
        raise Failure('invalid_setting')
    return value == '1'


def digest(path, algorithm='sha256'):
    with path.open('rb') as handle:
        return hashlib.file_digest(handle, algorithm).hexdigest()


def regular(path):
    return not path.is_symlink() and path.is_file()


def stamp(name):
    match = NAME.fullmatch(name)
    if not match:
        return None
    try:
        return dt.datetime.strptime(match[1], '%Y%m%dT%H%M%SZ').replace(tzinfo=UTC)
    except ValueError:
        return None


def allowed_name(name):
    return stamp(name) is not None or (name.endswith('.sha256') and stamp(name[:-7]) is not None)


def parse_config(text, folder):
    parser = configparser.ConfigParser(interpolation=None)
    try:
        parser.read_string(text)
    except configparser.Error:
        raise Failure('invalid_drive_configuration') from None
    if parser.sections() != ['gestion_stock_drive'] or parser.defaults():
        raise Failure('invalid_drive_configuration')
    conf = parser['gestion_stock_drive']
    if set(conf) - {'type', 'client_id', 'client_secret', 'scope', 'root_folder_id', 'token', 'team_drive'}:
        raise Failure('invalid_drive_configuration')
    if conf.get('type') != 'drive' or conf.get('scope') != 'drive' or conf.get('team_drive', ''):
        raise Failure('invalid_drive_configuration')
    if not re.fullmatch(r'[A-Za-z0-9_-]{10,200}', folder) or conf.get('root_folder_id') != folder:
        raise Failure('drive_folder_mismatch')
    if not re.fullmatch(r'[A-Za-z0-9_-]+\.apps\.googleusercontent\.com', conf.get('client_id', '')) or not conf.get('client_secret'):
        raise Failure('own_oauth_client_required')
    try:
        token = json.loads(conf.get('token', ''))
    except (ValueError, TypeError):
        raise Failure('invalid_oauth_token') from None
    if not isinstance(token, dict) or not token.get('refresh_token') or not token.get('access_token'):
        raise Failure('invalid_oauth_token')
    return parser


def private_directory(path):
    if path.is_symlink():
        raise Failure('unsafe_directory')
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    if path.stat().st_uid != os.getuid():
        raise Failure('unsafe_directory_owner')
    path.chmod(0o700)


def validate_config(path, folder):
    if not regular(path) or path.stat().st_mode & 0o077 or path.stat().st_uid != os.getuid():
        raise Failure('drive_configuration_missing_or_permissions')
    if path.stat().st_size > 65536 or path.parent.is_symlink():
        raise Failure('invalid_drive_configuration')
    parse_config(path.read_text(), folder)


def atomic_text(path, value):
    if path.is_symlink():
        raise Failure('unsafe_state')
    fd, temporary = tempfile.mkstemp(prefix='.drive-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as handle:
            handle.write(value)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        pathlib.Path(temporary).unlink(missing_ok=True)


def atomic_json(path, value):
    atomic_text(path, json.dumps(value))


def read_state(path):
    if not path.exists():
        return {}
    if not regular(path) or path.stat().st_size > 1048576:
        raise Failure('unsafe_state')
    try:
        value = json.loads(path.read_text())
    except (ValueError, OSError):
        raise Failure('invalid_state') from None
    if not isinstance(value, dict):
        raise Failure('invalid_state')
    return value


class Rclone:
    def __init__(self, config):
        self.config = config

    def call(self, *args):
        command = ['rclone', '--config', str(self.config), '--retries', '2', '--low-level-retries', '2',
                   '--contimeout', '15s', '--timeout', '60s', '--stats', '0', '--log-file', '/dev/null', *args]
        environment = {k: v for k, v in os.environ.items() if not k.startswith('RCLONE_')
                       and k.upper() not in ('HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY')}
        environment['HOME'] = '/tmp'
        try:
            result = subprocess.run(command, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    timeout=integer('DRIVE_COMMAND_TIMEOUT_SECONDS', 600, 10, 3600))
        except (OSError, subprocess.TimeoutExpired):
            raise Failure('drive_command_unavailable_or_timeout') from None
        if result.returncode:
            raise Failure('drive_command_failed')
        return result.stdout

    def listing(self):
        try:
            rows = json.loads(self.call('lsjson', REMOTE, '--max-depth', '1', '--hash'))
        except (ValueError, UnicodeError):
            raise Failure('invalid_drive_listing') from None
        if not isinstance(rows, list):
            raise Failure('invalid_drive_listing')
        result = {}
        for row in rows:
            name = row.get('Path') if isinstance(row, dict) else None
            if not isinstance(name, str) or not allowed_name(name):
                continue
            if row.get('IsDir') or row.get('MimeType') == 'application/vnd.google-apps.shortcut':
                raise Failure('drive_backup_name_occupied')
            if name in result:
                raise Failure('duplicate_drive_backup_names')
            result[name] = row
        return result

    def upload(self, path):
        self.call('copyto', str(path), REMOTE + path.name, '--immutable', '--checksum', '--transfers', '1')

    def verify(self, directory, names):
        listing = directory / 'verify-files.txt'
        listing.write_text('\n'.join(names) + '\n')
        self.call('check', str(directory), REMOTE, '--one-way', '--files-from-raw', str(listing), '--checkers', '1')

    def download(self, name, path):
        if not allowed_name(name):
            raise Failure('unsafe_download')
        self.call('copyto', REMOTE + name, str(path), '--transfers', '1')

    def delete(self, name):
        if not allowed_name(name):
            raise Failure('unsafe_delete')
        # Exact per-file permanent removal, after age/name/pair/SHA verification only.
        self.call('deletefile', REMOTE + name, '--drive-use-trash=false')


def checked_pair(directory, name, maximum):
    archive = directory / name
    side = directory / (name + '.sha256')
    if stamp(name) is None or not regular(archive) or not regular(side):
        raise Failure('invalid_backup_pair')
    if archive.stat().st_size > maximum or side.stat().st_size > 100:
        raise Failure('backup_too_large')
    with archive.open('rb') as handle:
        if handle.read(len(MAGIC)) != MAGIC:
            raise Failure('unencrypted_backup_refused')
    try:
        checksum = side.read_text().strip()
    except UnicodeError:
        raise Failure('backup_checksum_failed') from None
    if not re.fullmatch(r'[a-f0-9]{64}', checksum) or checksum != digest(archive):
        raise Failure('backup_checksum_failed')
    return checksum


def matches(metadata, path):
    if not isinstance(metadata, dict) or metadata.get('Size') != path.stat().st_size:
        return False
    hashes = metadata.get('Hashes')
    return isinstance(hashes, dict) and hashes.get('md5') == digest(path, 'md5')


def finish_deletion(state, state_path, known, work, remote, newest, cutoff, maximum):
    pending = state.get('pending_delete')
    if pending is None:
        return False
    if not isinstance(pending, dict):
        raise Failure('invalid_pending_deletion')
    name, checksum = pending.get('name'), pending.get('sha256')
    if not isinstance(name, str) or stamp(name) is None or stamp(name) >= cutoff or name == newest:
        raise Failure('invalid_pending_deletion')
    if not isinstance(checksum, str) or not re.fullmatch(r'[a-f0-9]{64}', checksum):
        raise Failure('invalid_pending_deletion')
    side = name + '.sha256'
    # A crash may have removed the archive but not its sidecar. The private journal
    # records the verified pair; revalidate any remaining bytes before resuming.
    with tempfile.TemporaryDirectory(prefix='drive-prune-', dir=work) as temporary:
        stage = pathlib.Path(temporary)
        for candidate, limit in ((name, maximum), (side, 100)):
            if candidate not in known:
                continue
            size = known[candidate].get('Size')
            if not isinstance(size, int) or not 0 < size <= limit:
                raise Failure('retention_candidate_changed')
            remote.download(candidate, stage / candidate)
            if not regular(stage / candidate) or (stage / candidate).stat().st_size > limit:
                raise Failure('retention_candidate_changed')
        if name in known:
            with (stage / name).open('rb') as handle:
                if handle.read(len(MAGIC)) != MAGIC or digest(stage / name) != checksum:
                    raise Failure('retention_candidate_changed')
        if side in known and (stage / side).read_text().strip() != checksum:
            raise Failure('retention_candidate_changed')
        for candidate in (name, side):
            if candidate in known:
                remote.delete(candidate)
                del known[candidate]
    state.pop('pending_delete')
    atomic_json(state_path, state)
    return True


def sync_once(directory, work, state_path, remote, retention=90, maximum=268435456):
    state = read_state(state_path)
    if directory.is_symlink() or not directory.is_dir():
        raise Failure('backup_directory_missing')
    cutoff = now() - dt.timedelta(days=retention)
    candidates = sorted((p.name for p in directory.iterdir()
                         if stamp(p.name) is not None and stamp(p.name) >= cutoff), reverse=True)
    if not candidates:
        raise Failure('no_encrypted_backup')
    age = (now() - stamp(candidates[0])).total_seconds()
    if age < -300:
        raise Failure('local_backup_in_future')
    if age > integer('DRIVE_HEALTH_MAX_AGE_SECONDS', 129600, 3600, 604800):
        raise Failure('local_backup_stale')
    private_directory(work)
    known = remote.listing()
    verified, errors = {}, []
    for name in candidates:
        try:
            with tempfile.TemporaryDirectory(prefix='drive-copy-', dir=work) as temporary:
                stage = pathlib.Path(temporary)
                checked_pair(directory, name, maximum)
                # Stage an immutable local copy; retention may unlink the source concurrently.
                shutil.copyfile(directory / name, stage / name)
                shutil.copyfile(directory / (name + '.sha256'), stage / (name + '.sha256'))
                checksum = checked_pair(stage, name, maximum)
                names = (name, name + '.sha256')
                for candidate in names:
                    if candidate in known and not matches(known[candidate], stage / candidate):
                        raise Failure('remote_backup_conflict')
                if not all(candidate in known for candidate in names):
                    remote.upload(stage / name)
                    remote.verify(stage, [name])
                    remote.upload(stage / names[1])
                    remote.verify(stage, names)
                verified[name] = checksum
        except FileNotFoundError:
            continue
        except Failure as error:
            errors.append(str(error))
        except (OSError, UnicodeError):
            errors.append('backup_copy_failed')
    if errors:
        raise Failure(errors[0])
    if not verified:
        raise Failure('drive_copy_incomplete')
    newest = max(verified, key=stamp)
    if (now() - stamp(newest)).total_seconds() > integer('DRIVE_HEALTH_MAX_AGE_SECONDS', 129600, 3600, 604800):
        raise Failure('local_backup_stale')
    # No retention until a recent local backup is confirmed in the remote folder.
    removed = int(finish_deletion(state, state_path, known, work, remote, newest, cutoff, maximum))
    skipped = 0
    for name, metadata in list(known.items()):
        when = stamp(name)
        side = name + '.sha256'
        if when is None or when >= cutoff or name == newest or side not in known:
            continue
        if not isinstance(metadata.get('Size'), int) or not 0 < metadata['Size'] <= maximum:
            skipped += 1
            continue
        side_size = known[side].get('Size')
        if not isinstance(side_size, int) or not 0 < side_size <= 100:
            skipped += 1
            continue
        with tempfile.TemporaryDirectory(prefix='drive-retention-', dir=work) as temporary:
            stage = pathlib.Path(temporary)
            remote.download(name, stage / name)
            remote.download(side, stage / side)
            try:
                checksum = checked_pair(stage, name, maximum)
            except (Failure, OSError):
                skipped += 1
                continue
        state['pending_delete'] = {'name': name, 'sha256': checksum}
        atomic_json(state_path, state)
        removed += int(finish_deletion(state, state_path, known, work, remote, newest, cutoff, maximum))
    state.update({'last_success_at': now().isoformat(), 'snapshot_started_at': stamp(newest).isoformat(),
                  'archive': newest, 'verified_count': len(verified), 'removed_count': removed,
                  'retention_skipped_count': skipped, 'last_attempt_ok': True})
    atomic_json(state_path, state)
    log('drive_backup_succeeded', verified_count=len(verified), removed_count=removed, retention_skipped_count=skipped)
    return state


def import_config(path, folder):
    raw = getpass.getpass('Configuration Drive encodee (saisie masquee) : ')
    try:
        text = base64.b64decode(raw, validate=True).decode()
    except (ValueError, UnicodeError):
        raise Failure('invalid_encoded_configuration') from None
    parse_config(text, folder)
    private_directory(path.parent)
    atomic_text(path, text)
    log('drive_configuration_saved')


def health(state_path):
    status = read_state(state_path)
    try:
        ages = [(now() - dt.datetime.fromisoformat(status[key])).total_seconds()
                for key in ('snapshot_started_at', 'last_success_at')]
    except (KeyError, ValueError, TypeError):
        raise Failure('drive_backup_unavailable') from None
    if status.get('last_attempt_ok') is not True:
        raise Failure('drive_backup_failed')
    if min(ages) < -300 or max(ages) > integer('DRIVE_HEALTH_MAX_AGE_SECONDS', 129600, 3600, 604800):
        raise Failure('drive_backup_stale')


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['once', 'schedule', 'health', 'import-config'])
    args = parser.parse_args()
    config = pathlib.Path(os.environ.get('DRIVE_CONFIG_FILE', '/drive-config/rclone.conf'))
    state = pathlib.Path(os.environ.get('DRIVE_STATE_DIRECTORY', '/drive-state'))
    work = pathlib.Path(os.environ.get('DRIVE_WORK_DIRECTORY', '/drive-work'))
    folder = os.environ.get('DRIVE_FOLDER_ID', '')
    if args.command == 'import-config':
        import_config(config, folder)
        return
    active = enabled()
    if not active:
        if args.command == 'health':
            return
        if args.command == 'once':
            raise Failure('drive_delivery_disabled')
    if args.command == 'health':
        health(state / 'status.json')
        return
    private_directory(state)
    while True:
        if active:
            with (state / '.lock').open('a') as lock:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    if args.command == 'once':
                        raise Failure('drive_copy_already_running') from None
                else:
                    try:
                        validate_config(config, folder)
                        sync_once(pathlib.Path(os.environ.get('BACKUP_DIRECTORY', '/backups')), work,
                                  state / 'status.json', Rclone(config),
                                  integer('DRIVE_RETENTION_DAYS', 90, 1, 3650),
                                  integer('DRIVE_MAX_ARCHIVE_BYTES', 268435456, 1024, 10737418240))
                    except (Failure, OSError, UnicodeError) as error:
                        code = str(error) if isinstance(error, Failure) else 'drive_io_error'
                        status = read_state(state / 'status.json')
                        status.update({'last_attempt_at': now().isoformat(), 'last_attempt_ok': False, 'last_error_code': code})
                        atomic_json(state / 'status.json', status)
                        log('drive_backup_failed', reason=code)
                        if args.command == 'once':
                            raise Failure('drive_backup_failed') from None
        else:
            log('drive_delivery_disabled')
        if args.command == 'once':
            return
        time.sleep(integer('DRIVE_SYNC_INTERVAL_SECONDS', 3600, 60, 86400))


if __name__ == '__main__':
    try:
        main()
    except (Failure, OSError, UnicodeError) as error:
        log(str(error) if isinstance(error, Failure) else 'drive_io_error')
        sys.exit(1)
