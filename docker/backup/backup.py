#!/usr/bin/env python3
"""Encrypted logical backups and non-destructive recovery. No Docker socket, no raw PGDATA copy."""
import argparse, contextlib, datetime as dt, fcntl, hashlib, json, os, pathlib, re, shutil, subprocess, sys, tarfile, tempfile, time, uuid
import psycopg2
from psycopg2 import sql

ROOTS = '.gitignore .dockerignore .env.example Dockerfile compose.yaml composer.json composer.lock phpunit.xml.dist README.md suivi.md cdc.md bin config docker public src templates tests docs migrations'.split()
SECRETS = ('app_secret', 'db_password', 'db_admin_password', 'mailer_dsn')
NAME = re.compile(r'^stock-(\d{8}T\d{6}Z)-[a-f0-9]{12}\.backup\.age$')
class Failure(Exception): pass

def log(code, level='info', **values):
    print(json.dumps(dict(time=dt.datetime.now(dt.timezone.utc).isoformat(), level=level, code=code, **values)), flush=True)
def integer(name, minimum, maximum, default=None):
    value = os.environ.get(name, str(default) if default is not None else '')
    if not re.fullmatch(r'[0-9]+', value) or not minimum <= int(value) <= maximum:
        raise Failure('invalid_' + name.lower())
    return int(value)
def run(args, **kwargs):
    try:
        result = subprocess.run(args, stderr=subprocess.PIPE, timeout=integer('BACKUP_COMMAND_TIMEOUT', 10, 86400, 600), **kwargs)
    except (OSError, subprocess.TimeoutExpired): raise Failure('command_unavailable_or_timeout') from None
    if result.returncode: raise Failure('command_failed_' + pathlib.Path(args[0]).name)
    return result

def connection():
    try:
        password = pathlib.Path(os.environ.get('DATABASE_PASSWORD_FILE', '/run/secrets/db_password')).read_text().strip()
        db = psycopg2.connect(host=os.environ.get('DATABASE_HOST','db'), port=os.environ.get('DATABASE_PORT','5432'), dbname=os.environ.get('DATABASE_NAME','gestion_stock'), user=os.environ.get('DATABASE_USER','gestion_stock'), password=password, connect_timeout=10, options='-c timezone=UTC -c datestyle=ISO,YMD')
        return db
    except (OSError, psycopg2.Error): raise Failure('database_connection_failed') from None

def pg_env():
    e = os.environ.copy()
    e.update(PGHOST=os.environ.get('DATABASE_HOST','db'), PGPORT=os.environ.get('DATABASE_PORT','5432'), PGDATABASE=os.environ.get('DATABASE_NAME','gestion_stock'), PGUSER=os.environ.get('DATABASE_USER','gestion_stock'), PGPASSWORD=pathlib.Path(os.environ.get('DATABASE_PASSWORD_FILE','/run/secrets/db_password')).read_text().strip(), PGCONNECT_TIMEOUT='10', PGOPTIONS='-c timezone=UTC -c datestyle=ISO,YMD')
    return e

def digest(path):
    with open(path,'rb') as stream: return hashlib.file_digest(stream,'sha256').hexdigest()
def fsync_file(path):
    with open(path,'rb') as stream: os.fsync(stream.fileno())
def fsync_dir(path):
    fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)
def atomic_json(path, value):
    temp=path.with_suffix('.tmp-'+uuid.uuid4().hex)
    try:
        with open(temp,'x') as f: json.dump(value,f); f.flush();os.fsync(f.fileno())
        os.chmod(temp,0o600);os.replace(temp,path);fsync_dir(path.parent)
    finally: temp.unlink(missing_ok=True)

def fingerprints(db):
    result={}
    with db.cursor() as c:
        c.execute("SELECT schemaname,tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') AND schemaname NOT LIKE 'pg_toast%%' ORDER BY schemaname,tablename")
        tables=c.fetchall()
    for schema,table in tables:
        count=0; h=hashlib.sha256()
        with db.cursor(name='fingerprint_'+uuid.uuid4().hex) as c:
            c.execute(sql.SQL('SELECT row_to_json(t)::text FROM {}.{} t ORDER BY row_to_json(t)::text COLLATE "C"').format(sql.Identifier(schema),sql.Identifier(table)))
            for (row,) in c:
                b=row.encode();h.update(len(b).to_bytes(8,'big'));h.update(b);count+=1
        result[schema+'.'+table]={'rows':count,'sha256':h.hexdigest()}
    return result

def source_files(directory):
    for root in ROOTS:
        p=directory/root
        if p.is_symlink(): raise Failure('source_symlink_refused')
        if not p.exists(): continue
        for f in sorted(p.rglob('*') if p.is_dir() else [p]):
            if any(part in ('.DS_Store','__pycache__','.git','vendor','var') for part in f.parts): continue
            if f.is_symlink(): raise Failure('source_symlink_refused')
            if f.is_file(): yield f, f.relative_to(directory).as_posix()

def prune(directory, days, current):
    cutoff=dt.datetime.now(dt.timezone.utc)-dt.timedelta(days=days)
    for p in directory.iterdir():
        match=NAME.fullmatch(p.name)
        if not match or p.name==current or p.is_symlink() or not p.is_file(): continue
        try: stamp=dt.datetime.strptime(match[1],'%Y%m%dT%H%M%SZ').replace(tzinfo=dt.timezone.utc)
        except ValueError:
            log('retention_invalid_name_kept',level='warning');continue
        if stamp>=cutoff: continue
        side=p.with_suffix(p.suffix+'.sha256')
        if side.is_symlink() or not side.is_file() or side.read_text().strip()!=digest(p):
            log('retention_unverified_file_kept',level='warning');continue
        p.unlink();side.unlink()
    fsync_dir(directory)

def backup():
    retention=integer('BACKUP_RETENTION_DAYS',1,3650)
    directory=pathlib.Path(os.environ.get('BACKUP_DIRECTORY','/backups'))
    if directory.is_symlink(): raise Failure('backup_directory_symlink_refused')
    directory.mkdir(parents=True,exist_ok=True);os.chmod(directory,0o700)
    with open(directory/'.lock','a') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: raise Failure('backup_already_running') from None
        stamp=dt.datetime.now(dt.timezone.utc)
        name='stock-'+stamp.strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:12]+'.backup.age'
        part=directory/(name+'.partial');side=directory/(name+'.sha256')
        try:
            with tempfile.TemporaryDirectory(prefix='backup-',dir=os.environ.get('TMPDIR','/work')) as temp:
                stage=pathlib.Path(temp);dump=stage/'database.dump'
                with contextlib.closing(connection()) as db:
                    db.set_session(isolation_level='REPEATABLE READ',readonly=True)
                    with db.cursor() as c:
                        c.execute('SELECT pg_export_snapshot(),current_setting(\'server_version_num\')');snapshot,version=c.fetchone()
                    if not str(version).startswith('18'): raise Failure('postgres_major_mismatch')
                    run(['pg_dump','--format=custom','--no-owner','--no-acl','--snapshot='+snapshot,'--file='+str(dump)],env=pg_env(),stdout=subprocess.DEVNULL)
                    tables=fingerprints(db)
                    db.rollback()
                run(['pg_restore','--list',str(dump)],stdout=subprocess.DEVNULL)
                paths={'database.dump':dump}
                source=pathlib.Path(os.environ.get('BACKUP_SOURCE','/source'))
                for p,relative in source_files(source): paths['source/'+relative]=p
                env=pathlib.Path(os.environ.get('BACKUP_ENV_FILE',str(source/'.env')))
                if not env.is_file() or env.is_symlink(): raise Failure('environment_snapshot_missing')
                paths['source/.env']=env
                for secret in SECRETS:
                    p=pathlib.Path(os.environ.get('BACKUP_SECRETS_DIRECTORY','/run/secrets'))/secret
                    if not p.is_file() or not p.stat().st_size: raise Failure('recovery_secret_missing')
                    paths['source/.secrets/'+secret]=p
                modes={key:(0o600 if key in ('database.dump','source/.env') else (0o644 if key.startswith('source/.secrets/') else (0o755 if p.stat().st_mode & 0o111 else 0o644))) for key,p in paths.items()}
                # Snapshot input bytes once, so files changing while read cannot invalidate their manifest.
                for i,(key,p) in enumerate(list(paths.items())):
                    if key=='database.dump': continue
                    copy=stage/('file-'+str(i));shutil.copyfile(p,copy);paths[key]=copy
                manifest={'format':1,'snapshot_started_at':stamp.isoformat(),'postgres_major':18,'retention_days':retention,'schedule_interval_seconds':integer('BACKUP_INTERVAL_SECONDS',900,86400,86400),'tables':tables,'files':{key:{'size':p.stat().st_size,'sha256':digest(p),'mode':modes[key]} for key,p in paths.items()}}
                manifest_file=stage/'manifest.json';manifest_file.write_text(json.dumps(manifest,sort_keys=True))
                bundle=stage/'bundle.tar'
                with tarfile.open(bundle,'w') as tar:
                    for key,p in paths.items(): tar.add(p,arcname=key,recursive=False)
                    tar.add(manifest_file,arcname='manifest.json',recursive=False)
                recipient=pathlib.Path(os.environ.get('BACKUP_RECIPIENT_FILE','/run/secrets/backup_recipient')).read_text().strip()
                if not re.fullmatch(r'age1[0-9a-z]{58}',recipient): raise Failure('invalid_age_recipient')
                run(['age','-r',recipient,'-o',str(part),str(bundle)],stdout=subprocess.DEVNULL)
                os.chmod(part,0o600);fsync_file(part);checksum=digest(part)
                with open(side,'x') as f: f.write(checksum+'\n');f.flush();os.fsync(f.fileno())
                os.chmod(side,0o600);os.replace(part,directory/name);fsync_dir(directory)
                atomic_json(directory/'status.json',{'snapshot_started_at':stamp.isoformat(),'completed_at':dt.datetime.now(dt.timezone.utc).isoformat(),'archive':name,'sha256':checksum})
                prune(directory,retention,name)
                log('backup_succeeded',archive=name)
                return directory/name
        finally:
            part.unlink(missing_ok=True)
            if not (directory/name).exists(): side.unlink(missing_ok=True)

def allowed_member(name):
    if name in ('database.dump','manifest.json','source/.env'): return True
    if name.startswith('source/.secrets/'): return name.split('/')==['source','.secrets',name.split('/')[-1]] and name.split('/')[-1] in SECRETS
    if not name.startswith('source/'): return False
    rel=pathlib.PurePosixPath(name[7:])
    return bool(rel.parts) and rel.parts[0] in ROOTS and not any(p in ('.','..','.git','.secrets','vendor','var','__pycache__') for p in rel.parts)

@contextlib.contextmanager
def unpack(archive, identity):
    archive=pathlib.Path(archive)
    if archive.is_symlink() or not archive.is_file(): raise Failure('archive_missing')
    side=archive.with_suffix(archive.suffix+'.sha256')
    if side.is_symlink() or not side.is_file() or side.read_text().strip()!=digest(archive): raise Failure('archive_checksum_failed')
    with tempfile.TemporaryDirectory(prefix='restore-',dir=os.environ.get('TMPDIR','/work')) as temp:
        stage=pathlib.Path(temp);bundle=stage/'bundle.tar'
        run(['age','-d','-i',str(identity),'-o',str(bundle),str(archive)],stdout=subprocess.DEVNULL)
        maximum=integer('BACKUP_MAX_EXPANDED_BYTES',1024,1024**4,10*1024**3)
        if bundle.stat().st_size>maximum: raise Failure('archive_too_large')
        with tarfile.open(bundle,'r:') as tar:
            members=tar.getmembers();names=[m.name for m in members]
            if len(set(names))!=len(names) or any(not m.isfile() or not allowed_member(m.name) for m in members) or sum(m.size for m in members)>maximum: raise Failure('unsafe_archive')
            if 'manifest.json' not in names: raise Failure('manifest_missing')
            manifest=json.load(tar.extractfile('manifest.json'))
            if manifest.get('format')!=1 or manifest.get('postgres_major')!=18 or not isinstance(manifest.get('files'),dict): raise Failure('unsupported_manifest')
            if set(names)!=(set(manifest['files'])|{'manifest.json'}) or 'database.dump' not in manifest['files']: raise Failure('manifest_file_set_mismatch')
            for m in members:
                if m.name=='manifest.json': continue
                p=stage/m.name;p.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
                with tar.extractfile(m) as src,open(p,'xb') as dest: shutil.copyfileobj(src,dest)
                expected=manifest['files'][m.name]
                if expected.get('size')!=p.stat().st_size or expected.get('sha256')!=digest(p) or expected.get('mode') not in (0o600,0o644,0o755): raise Failure('member_checksum_failed')
                os.chmod(p,0o600)
        yield stage,manifest

def restore_files(archive,identity,output):
    output=pathlib.Path(output)
    if output.is_symlink() or not output.is_dir() or any(output.iterdir()): raise Failure('recovery_directory_not_empty')
    with unpack(archive,identity) as (stage,manifest):
        # Full authentication and hash checks finish before any recovered file becomes visible.
        for name in manifest['files']:
            target=output/name;target.parent.mkdir(parents=True,exist_ok=True,mode=0o755);shutil.copyfile(stage/name,target)
            os.chmod(target,manifest['files'][name]['mode'])
        if (output/'source/.secrets').exists(): os.chmod(output/'source/.secrets',0o700)
        if (output/'source').exists(): os.chmod(output/'source',0o700)
        (output/'manifest.json').write_text(json.dumps(manifest,sort_keys=True));os.chmod(output/'manifest.json',0o600)
        os.chmod(output,0o700)
    uid=integer('RESTORE_OUTPUT_UID',0,2147483647,os.getuid());gid=integer('RESTORE_OUTPUT_GID',0,2147483647,os.getgid())
    for p in [*output.rglob('*'),output]: os.chown(p,uid,gid)
    log('recovery_files_verified')

def restore_db(archive,identity):
    if os.environ.get('RESTORE_CONFIRM_DATABASE')!=os.environ.get('DATABASE_NAME','gestion_stock'): raise Failure('restore_confirmation_required')
    with unpack(archive,identity) as (stage,manifest):
        with contextlib.closing(connection()) as db:
            with db.cursor() as c:
                c.execute("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%%') OR EXISTS(SELECT 1 FROM pg_namespace WHERE nspname NOT IN ('public','pg_catalog','information_schema') AND nspname NOT LIKE 'pg_%%')")
                if c.fetchone()[0]: raise Failure('restore_database_not_empty')
            db.rollback()
        run(['pg_restore','--no-owner','--no-acl','--single-transaction','--exit-on-error','--dbname='+os.environ.get('DATABASE_NAME','gestion_stock'),str(stage/'database.dump')],env=pg_env(),stdout=subprocess.DEVNULL)
        verify_database(manifest)
    log('database_restored_and_verified')

def verify_database(manifest):
    with contextlib.closing(connection()) as db:
        db.set_session(isolation_level='REPEATABLE READ',readonly=True)
        if fingerprints(db)!=manifest['tables']: raise Failure('restored_fingerprints_mismatch')
        with db.cursor() as c:
            c.execute("SELECT to_regclass('public.stock_reconciliation')")
            if c.fetchone()[0]:
                c.execute('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0')
                if c.fetchone()[0]: raise Failure('stock_reconciliation_failed')
        db.rollback()

def health():
    interval=integer('BACKUP_INTERVAL_SECONDS',900,86400)
    status=json.loads((pathlib.Path(os.environ.get('BACKUP_DIRECTORY','/backups'))/'status.json').read_text())
    age=(dt.datetime.now(dt.timezone.utc)-dt.datetime.fromisoformat(status['snapshot_started_at'])).total_seconds()
    directory=pathlib.Path(os.environ.get('BACKUP_DIRECTORY','/backups'))
    if not 0<=age<=interval+integer('BACKUP_COMMAND_TIMEOUT',10,86400,600)+60 or not NAME.fullmatch(status['archive']) or not (directory/status['archive']).is_file(): raise Failure('backup_stale_or_missing')
    archive=directory/status['archive']
    if archive.is_symlink() or digest(archive)!=status.get('sha256'): raise Failure('backup_integrity_failed')

def due(now,last,interval):
    return last<=0 or last>now+60 or int(now//interval)>int(last//interval)

def schedule():
    interval=integer('BACKUP_INTERVAL_SECONDS',900,86400);integer('BACKUP_RETENTION_DAYS',1,3650)
    directory=pathlib.Path(os.environ.get('BACKUP_DIRECTORY','/backups'))
    while True:
        try:
            try: last=dt.datetime.fromisoformat(json.loads((directory/'status.json').read_text())['snapshot_started_at']).timestamp()
            except (OSError,KeyError,ValueError,json.JSONDecodeError): last=0
            # UTC slots avoid drift and catch up once after a restart, without replaying missed runs.
            if due(time.time(),last,interval): backup()
        except (Failure,OSError,psycopg2.Error,ValueError): log('scheduled_backup_failed',level='error')
        time.sleep(min(60,interval))

def keygen(directory):
    directory=pathlib.Path(directory);directory.mkdir(mode=0o700,parents=True,exist_ok=True)
    private=directory/'backup_identity';public=directory/'backup_recipient'
    if private.exists() or public.exists(): raise Failure('existing_backup_key_preserved')
    run(['age-keygen','-o',str(private)],stdout=subprocess.DEVNULL)
    os.chmod(private,0o600)
    result=run(['age-keygen','-y',str(private)],stdout=subprocess.PIPE)
    with open(public,'xb') as f: f.write(result.stdout)
    os.chmod(public,0o644)
    uid=integer('BACKUP_KEY_UID',0,2147483647,os.getuid());gid=integer('BACKUP_KEY_GID',0,2147483647,os.getgid())
    for p in [private,public]: os.chown(p,uid,gid)
    log('backup_key_created_keep_identity_off_host')

def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser();parser.add_argument('command',choices=['once','schedule','health','keygen','inspect','restore-files','restore-db','verify-db']);parser.add_argument('archive',nargs='?');parser.add_argument('--identity',default='/run/secrets/backup_identity');parser.add_argument('--output');parser.add_argument('--keys-directory',default='/keys');a=parser.parse_args()
    if a.command=='once': backup()
    elif a.command=='schedule': schedule()
    elif a.command=='health': health()
    elif a.command=='keygen': keygen(a.keys_directory)
    elif a.command=='restore-files': restore_files(a.archive,a.identity,a.output)
    elif a.command=='restore-db': restore_db(a.archive,a.identity)
    elif a.command=='verify-db':
        with unpack(a.archive,a.identity) as (_,manifest): verify_database(manifest)
        log('database_integrity_verified')
    elif a.command=='inspect':
        with unpack(a.archive,a.identity) as (_,manifest): log('archive_verified',snapshot_started_at=manifest['snapshot_started_at'],postgres_major=manifest['postgres_major'],table_count=len(manifest['tables']))
if __name__=='__main__':
    try: main()
    except (Failure,OSError,ValueError,KeyError,tarfile.TarError,psycopg2.Error) as error:
        log(str(error) if isinstance(error,Failure) else 'backup_operation_failed',level='error');sys.exit(1)
