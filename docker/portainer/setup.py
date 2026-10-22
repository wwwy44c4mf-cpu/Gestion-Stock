#!/usr/bin/env python3
"""First-install credentials; never regenerate a partially lost installation."""
import getpass
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

ROOT = Path('/deployment')
APP = Path('/app-secrets')
DB = Path('/db-secrets')
BACKUP = Path('/backup-secrets')
KEYS = Path('/recovery-key')
STATE = Path('/schema-state')
DIRECTORIES = (ROOT, APP, DB, BACKUP, KEYS, STATE)


def fail(message):
    raise SystemExit(message)


def configuration():
    domain = os.environ.get('APP_DOMAIN', '')
    if not re.fullmatch(r'(?=.{1,253}\Z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', domain):
        fail('invalid_app_domain')
    proxy = str(ipaddress.ip_address(os.environ['CLOUDFLARED_ADDRESS']))
    host_pattern = '^' + domain.replace('.', '[.]') + '\\z'
    if os.environ.get('APP_TRUSTED_HOST_PATTERN') != host_pattern:
        fail('trusted_host_pattern_mismatch')
    values = {'COMPOSE_PROJECT_NAME': os.environ.get('COMPOSE_PROJECT_NAME', 'gestion-stock'),
              'RELEASE_ID': os.environ['RELEASE_ID'],
              'DATABASE_RELEASE_ID': os.environ['DATABASE_RELEASE_ID'],
              'APP_DOMAIN': domain, 'CLOUDFLARED_ADDRESS': proxy,
              'CLOUDFLARED_NETWORK': os.environ.get('CLOUDFLARED_NETWORK', 'hassio'),
              'POSTGRES_DB': os.environ.get('POSTGRES_DB', 'gestion_stock'),
              'POSTGRES_USER': os.environ.get('POSTGRES_USER', 'gestion_stock'),
              'MAIL_DELIVERY_ENABLED': os.environ.get('MAIL_DELIVERY_ENABLED', '0'),
              'MAIL_FROM_ADDRESS': os.environ.get('MAIL_FROM_ADDRESS', 'noreply@example.invalid'),
              'BACKUP_INTERVAL_SECONDS': '86400', 'BACKUP_RETENTION_DAYS': '90'}
    if values['POSTGRES_USER'] == 'postgres':
        fail('application_superuser_refused')
    for key in ('POSTGRES_DB', 'POSTGRES_USER'):
        if not re.fullmatch(r'[a-z][a-z0-9_]{0,62}', values[key]):
            fail('invalid_database_identifier')
    for value in values.values():
        if not re.fullmatch(r'[a-zA-Z0-9_.:@/+-]+', value):
            fail('invalid_deployment_value')
    values['APP_TRUSTED_HOST_PATTERN'] = "'" + host_pattern + "'"
    return values


def write(path, value, uid=10001):
    if path.is_symlink():
        fail('symlink_refused')
    temporary = path.with_name(path.name + '.tmp')
    with temporary.open('x') as stream:
        stream.write(value + '\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.chown(temporary, uid, uid)
    os.chmod(temporary, 0o400)
    temporary.replace(path)


def expected_files():
    return ((APP, ('app_secret', 'db_password', 'mailer_dsn')),
            (DB, ('db_password', 'db_admin_password')),
            (BACKUP, ('app_secret', 'db_password', 'db_admin_password', 'mailer_dsn', 'backup_recipient')),
            (KEYS, ('backup_identity',)))


def verify():
    for directory, names in expected_files():
        for name in names:
            path = directory / name
            if path.is_symlink() or not path.is_file() or not path.stat().st_size:
                fail('incomplete_credentials_restore_required')
            uid = 0 if directory == KEYS else (70 if directory == DB else 10001)
            mode = 0o600 if directory == KEYS else 0o400
            if path.stat().st_uid != uid or path.stat().st_mode & 0o777 != mode:
                fail('credentials_permissions_invalid')
    for name in ('app_secret', 'db_password', 'mailer_dsn'):
        if (APP / name).read_bytes() != (BACKUP / name).read_bytes():
            fail('credential_copies_differ')
    for name in ('db_password', 'db_admin_password'):
        if (DB / name).read_bytes() != (BACKUP / name).read_bytes():
            fail('credential_copies_differ')
    public = subprocess.run(['age-keygen', '-y', str(KEYS / 'backup_identity')],
                            check=True, capture_output=True, text=True).stdout.strip()
    if public != (BACKUP / 'backup_recipient').read_text().strip():
        fail('recovery_key_mismatch')


def main():
    os.umask(0o077)
    for directory in DIRECTORIES:
        directory.mkdir(exist_ok=True)
        if directory.is_symlink():
            fail('symlink_refused')
    marker = ROOT / 'initialized.json'
    if sys.argv[1:] == ['smtp']:
        verify()
        dsn = getpass.getpass('DSN SMTP (saisie masquée) : ')
        if not dsn.startswith(('smtp://', 'smtps://')) or any(c in dsn for c in '\r\n\x00'):
            fail('invalid_smtp_dsn')
        write(APP / 'mailer_dsn', dsn)
        write(BACKUP / 'mailer_dsn', dsn)
        print('SMTP enregistré ; redémarrer app, worker et scheduler.')
        return
    values = configuration()
    if marker.exists():
        if marker.is_symlink():
            fail('symlink_refused')
        saved = json.loads(marker.read_text())
        if any(saved.get(k) != values[k] for k in ('POSTGRES_DB', 'POSTGRES_USER')):
            fail('database_identity_change_refused')
        verify()
    else:
        if any(list(directory.iterdir()) for directory in DIRECTORIES):
            fail('partial_installation_restore_required')
        credentials = {name: secrets.token_hex(32) for name in ('app_secret', 'db_password', 'db_admin_password')}
        credentials['mailer_dsn'] = 'null://null'
        subprocess.run(['age-keygen', '-o', str(KEYS / 'backup_identity')],
                       check=True, capture_output=True)
        os.chmod(KEYS, 0o700)
        os.chmod(KEYS / 'backup_identity', 0o600)
        credentials['backup_recipient'] = subprocess.run(
            ['age-keygen', '-y', str(KEYS / 'backup_identity')], check=True,
            capture_output=True, text=True).stdout.strip()
        for directory, names in expected_files():
            if directory == KEYS:
                continue
            # The official Alpine PostgreSQL entrypoint drops to UID 70 before
            # executing init scripts; application and backup use UID 10001.
            uid = 70 if directory == DB else 10001
            os.chown(directory, uid, uid)
            os.chmod(directory, 0o700)
            for name in names:
                write(directory / name, credentials[name], uid)
        write(marker, json.dumps({key: values[key] for key in ('POSTGRES_DB', 'POSTGRES_USER')}))
    os.chown(STATE, 10001, 10001)
    os.chmod(STATE, 0o700)
    write(ROOT / 'portainer.env', '\n'.join(key + '=' + value for key, value in values.items()))
    print('Configuration vérifiée ; secrets conservés sans affichage.')


if __name__ == '__main__':
    main()
