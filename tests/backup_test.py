import uuid
from psycopg2 import sql
import contextlib, datetime as dt, hashlib, http.cookiejar, importlib.util, io, json, os, pathlib, shutil, subprocess, sys, tarfile, tempfile, unittest, urllib.request
from unittest.mock import patch
import psycopg2
spec=importlib.util.spec_from_file_location('backup','/opt/backup.py');backup=importlib.util.module_from_spec(spec);spec.loader.exec_module(backup)
FIX=pathlib.Path('/fixture');IDENTITY=FIX/'keys/backup_identity';ARCHIVES=FIX/'archives'

def setup():
    if any(FIX.iterdir()): raise RuntimeError('Empty disposable fixture directory required')
    (FIX/'.ops01-fixture').write_text('isolated test only')
    (FIX/'secrets').mkdir(mode=0o755);(FIX/'keys').mkdir(mode=0o700);ARCHIVES.mkdir(mode=0o700)
    for name in backup.SECRETS:
        value='smtp://fixture.invalid:587' if name=='mailer_dsn' else os.urandom(32).hex()
        p=FIX/'secrets'/name;p.write_text(value+'\n');p.chmod(0o644)
    (FIX/'recovery.env').write_text('APP_ENV=dev\nMAIL_DELIVERY_ENABLED=0\nPOSTGRES_DB=gestion_stock\nPOSTGRES_USER=gestion_stock\n')
    backup.keygen(FIX/'keys')
    for p in [*FIX.rglob('*'),FIX]: os.chown(p,10001,10001)
    print('Disposable recovery fixture prepared')
def clean():
    if not (FIX/'.ops01-fixture').is_file(): return
    for p in FIX.iterdir():
        if p.is_dir(): shutil.rmtree(p)
        else: p.unlink()
def restored_env(): return patch.dict(os.environ,{'DATABASE_HOST':'restore-db','RESTORE_CONFIRM_DATABASE':'gestion_stock'})
def query(statement,params=None):
    with contextlib.closing(backup.connection()) as db,db.cursor() as c:
        c.execute(statement,params);rows=c.fetchall() if c.description else None;db.commit();return rows

def latest(): return ARCHIVES/json.loads((ARCHIVES/'status.json').read_text())['archive']
def web_check():
    # Ready means database access; authenticated stock access additionally proves the recovered application.
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    assert json.load(opener.open('http://web:8080/health/ready'))['status']=='ok'
    csrf=json.load(opener.open('http://web:8080/api/v1/auth/csrf'))['token']
    request=urllib.request.Request('http://web:8080/api/v1/auth/login',data=json.dumps({'email':'recovery@example.test','password':'Recovery-fixture-2026!'}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
    assert opener.open(request).status==200
    rows=json.load(opener.open('http://web:8080/api/v1/stocks'))['data'];assert len(rows)==1 and rows[0]['quantity']==8
    print('Recovered web application, login and stock verified')

def recreate_check():
    # Login audit events intentionally differ; the core business snapshot must remain identical.
    with restored_env():
        assert query('SELECT sum(quantity) FROM stock_levels')[0][0]==8
        assert query('SELECT count(*) FROM stock_movements')[0][0]==3
        assert query("SELECT count(*) FROM messenger_messages WHERE queue_name='ops_recovery_probe' AND body='durable-recovery-fixture'")[0][0]==1
        assert query('SELECT count(*) FROM inventories')[0][0]==1
        assert query('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0')[0][0]==0
    web_check();print('REC-15 preserved business data and durable queue after container recreation')

class RecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.archive=backup.backup()
    def test_01_encrypted_consistent_snapshot_and_fresh_restore(self):
        self.assertEqual(0o700,ARCHIVES.stat().st_mode&0o777);self.assertEqual(0o600,self.archive.stat().st_mode&0o777)
        self.assertTrue(self.archive.read_bytes().startswith(b'age-encryption.org/v1'))
        with backup.unpack(self.archive,IDENTITY) as (stage,manifest):
            self.assertEqual(17,manifest['tables']['public.doctrine_migration_versions']['rows'])
            self.assertEqual(3,manifest['tables']['public.stock_movements']['rows'])
            self.assertNotIn('source/.secrets/backup_identity',manifest['files']);self.assertNotIn('source/.git/HEAD',manifest['files'])
            self.assertEqual((FIX/'secrets/app_secret').read_bytes(),(stage/'source/.secrets/app_secret').read_bytes())
            with contextlib.closing(backup.connection()) as db: self.assertEqual(manifest['tables'],backup.fingerprints(db))
        with restored_env():
            backup.restore_db(self.archive,IDENTITY)
            self.assertTrue(query('SELECT privacy_required FROM runtime_restore_gate')[0][0])
            self.assertEqual(8,query('SELECT sum(quantity) FROM stock_levels')[0][0]);self.assertEqual(3,query('SELECT count(*) FROM stock_movements')[0][0])
            self.assertEqual(1,query('SELECT count(*) FROM inventories')[0][0])
    def test_02_recover_files_modes_and_existing_directory_refusal(self):
        output=FIX/'recovered';output.mkdir()
        backup.restore_files(self.archive,IDENTITY,output)
        self.assertTrue((output/'source/docker/entrypoint.sh').stat().st_mode&0o100)
        self.assertEqual(0o644,(output/'source/public/index.php').stat().st_mode&0o777)
        self.assertEqual(0o700,(output/'source/.secrets').stat().st_mode&0o777)
        self.assertEqual(0o600,(output/'source/.env').stat().st_mode&0o777)
        with self.assertRaisesRegex(backup.Failure,'not_empty'): backup.restore_files(self.archive,IDENTITY,output)
    def test_03_nonempty_database_and_confirmation_refused_without_change(self):
        with restored_env():
            before=query('SELECT count(*) FROM stock_movements')
            with self.assertRaisesRegex(backup.Failure,'not_empty'): backup.restore_db(self.archive,IDENTITY)
            self.assertEqual(before,query('SELECT count(*) FROM stock_movements'))
            with patch.dict(os.environ,{'RESTORE_CONFIRM_DATABASE':'wrong'}):
                with self.assertRaisesRegex(backup.Failure,'confirmation_required'): backup.restore_db(self.archive,IDENTITY)
    def test_04_corruption_and_wrong_key_rejected_before_write(self):
        bad=FIX/'corrupt.backup.age';data=bytearray(self.archive.read_bytes());data[-10]^=1;bad.write_bytes(data)
        side=bad.with_suffix(bad.suffix+'.sha256');side.write_text('wrong')
        with self.assertRaisesRegex(backup.Failure,'checksum'):
            with backup.unpack(bad,IDENTITY): pass
        # Even a recomputed sidecar cannot bypass age authentication.
        side.write_text(backup.digest(bad))
        with self.assertRaises(backup.Failure):
            with backup.unpack(bad,IDENTITY): pass
        other=FIX/'other-key';backup.keygen(other)
        with self.assertRaises(backup.Failure):
            with backup.unpack(self.archive,other/'backup_identity'): pass
    def test_05_unsafe_archive_paths_and_symlinks_rejected(self):
        for name,kind in [('../escape','file'),('source/.secrets/backup_identity','file'),('source/bin/evil','link')]:
            bundle=FIX/'unsafe.tar'
            with tarfile.open(bundle,'w') as tar:
                info=tarfile.TarInfo(name)
                if kind=='link': info.type=tarfile.SYMTYPE;info.linkname='/etc/passwd';tar.addfile(info)
                else: info.size=1;tar.addfile(info,io.BytesIO(b'x'))
            encrypted=FIX/'unsafe.backup.age'
            encrypted.unlink(missing_ok=True)
            backup.run(['age','-r',(FIX/'keys/backup_recipient').read_text().strip(),'-o',str(encrypted),str(bundle)],stdout=subprocess.DEVNULL)
            encrypted.with_suffix(encrypted.suffix+'.sha256').write_text(backup.digest(encrypted))
            with self.assertRaisesRegex(backup.Failure,'unsafe_archive'):
                with backup.unpack(encrypted,IDENTITY): pass
        self.assertFalse((FIX/'escape').exists())
    def test_06_retention_preserves_unverified_newest_and_foreign_files(self):
        directory=FIX/'retention';directory.mkdir();old='stock-20000101T000000Z-aaaaaaaaaaaa.backup.age';unverified='stock-20000101T000000Z-bbbbbbbbbbbb.backup.age'
        for name in [old,unverified,self.archive.name,'operator-note']:(directory/name).write_text('test')
        (directory/(old+'.sha256')).write_text(backup.digest(directory/old))
        backup.prune(directory,90,self.archive.name)
        self.assertFalse((directory/old).exists());self.assertTrue((directory/unverified).exists());self.assertTrue((directory/self.archive.name).exists());self.assertTrue((directory/'operator-note').exists())
    def test_07_missing_settings_failed_dump_and_lock_keep_last_success(self):
        previous=(ARCHIVES/'status.json').read_bytes()
        with patch.dict(os.environ,{'BACKUP_RETENTION_DAYS':''}):
            with self.assertRaises(backup.Failure):backup.backup()
        import fcntl
        with open(ARCHIVES/'.lock','a') as f:
            fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(backup.Failure,'already_running'):backup.backup()
        with patch.dict(os.environ,{'DATABASE_HOST':'nonexistent.invalid'}):
            with self.assertRaises(backup.Failure):backup.backup()
        self.assertEqual(previous,(ARCHIVES/'status.json').read_bytes());self.assertEqual([],list(ARCHIVES.glob('*.partial')))
        backup.health()
        with patch.dict(os.environ,{'BACKUP_INTERVAL_SECONDS':'0'}):
            with self.assertRaises(backup.Failure):backup.health()
    def test_08_existing_keys_are_preserved(self):
        private=IDENTITY.read_bytes()
        with self.assertRaisesRegex(backup.Failure,'preserved'):backup.keygen(IDENTITY.parent)
        self.assertEqual(private,IDENTITY.read_bytes())
    def test_085_schedule_slots_restart_catchup_and_health(self):
        self.assertTrue(backup.due(172800,0,86400))
        self.assertFalse(backup.due(172900,172800,86400))
        self.assertTrue(backup.due(259200,172800,86400))
        self.assertTrue(backup.due(172800,400000,86400))
        status=ARCHIVES/'status.json';before=status.read_bytes();values=json.loads(before)
        values['snapshot_started_at']='2000-01-01T00:00:00+00:00';backup.atomic_json(status,values)
        with self.assertRaisesRegex(backup.Failure,'stale'):backup.health()
        status.write_bytes(before)
    def test_086_sql_restore_error_rolls_back_all_created_objects(self):
        role='ops_missing_'+uuid.uuid4().hex[:8];database='ops_atomic_'+uuid.uuid4().hex[:8]
        admin={'DATABASE_USER':'postgres','DATABASE_PASSWORD_FILE':'/run/secrets/db_admin_password'}
        with patch.dict(os.environ,admin),contextlib.closing(backup.connection()) as source_admin:
            source_admin.autocommit=True
            with source_admin.cursor() as c:c.execute(sql.SQL('CREATE ROLE {}').format(sql.Identifier(role)))
        with patch.dict(os.environ,{**admin,'DATABASE_HOST':'restore-db'}),contextlib.closing(backup.connection()) as target_admin:
            target_admin.autocommit=True
            with target_admin.cursor() as c:c.execute(sql.SQL('CREATE DATABASE {} OWNER gestion_stock').format(sql.Identifier(database)))
        try:
            query('CREATE TABLE ops_restore_probe (id integer)');query('ALTER TABLE ops_restore_probe ENABLE ROW LEVEL SECURITY')
            with contextlib.closing(backup.connection()) as db,db.cursor() as c:
                c.execute(sql.SQL('CREATE POLICY ops_policy ON ops_restore_probe TO {} USING (true)').format(sql.Identifier(role)));db.commit()
            archive=backup.backup()
            with patch.dict(os.environ,{'DATABASE_HOST':'restore-db','DATABASE_NAME':database,'RESTORE_CONFIRM_DATABASE':database}):
                with self.assertRaisesRegex(backup.Failure,'command_failed_pg_restore'):backup.restore_db(archive,IDENTITY)
                self.assertEqual(0,query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'")[0][0])
        finally:
            query('DROP TABLE IF EXISTS ops_restore_probe')
            with patch.dict(os.environ,admin),contextlib.closing(backup.connection()) as db:
                db.autocommit=True
                with db.cursor() as c:c.execute(sql.SQL('DROP ROLE {}').format(sql.Identifier(role)))
            with patch.dict(os.environ,{**admin,'DATABASE_HOST':'restore-db'}),contextlib.closing(backup.connection()) as db:
                db.autocommit=True
                with db.cursor() as c:c.execute(sql.SQL('DROP DATABASE {}').format(sql.Identifier(database)))
    def test_09_live_writes_do_not_break_exported_snapshot(self):
        # Inject a committed source write after pg_dump's snapshot export, before fingerprints.
        original=backup.run;done=False
        def concurrent(args,**kwargs):
            nonlocal done
            result=original(args,**kwargs)
            if args[0]=='pg_dump' and not done:
                query("INSERT INTO audit_logs(action,target_type) VALUES ('ops.snapshot_probe','test')");done=True
            return result
        with patch.object(backup,'run',side_effect=concurrent): archive=backup.backup()
        with backup.unpack(archive,IDENTITY) as (_,manifest):
            self.assertTrue(done);self.assertEqual(3,manifest['tables']['public.audit_logs']['rows'])
        # Keep the first known archive for the authenticated recovery checks.
        backup.atomic_json(FIX/'recette.json',{'archive':self.archive.name})

if __name__=='__main__':
    command=sys.argv[1] if len(sys.argv)>1 else 'test'
    if command=='setup':setup()
    elif command=='clean':clean()
    elif command=='gate':
        try:
            urllib.request.urlopen('http://web:8080/health/ready')
            raise AssertionError('Restored application must remain closed')
        except urllib.error.HTTPError as error:
            assert error.code==503
            print('Restored application blocked until retention succeeds')
    elif command=='web':web_check()
    elif command=='recreated':recreate_check()
    else:unittest.main(argv=[sys.argv[0]],verbosity=2)
