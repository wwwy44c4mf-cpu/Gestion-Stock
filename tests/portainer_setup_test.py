"""Real credential provisioning in temporary directories, never live volumes."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('setup', '/opt/portainer-setup.py')
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class SetupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        paths = ('ROOT', 'APP', 'DB', 'BACKUP', 'KEYS', 'STATE')
        for name in paths:
            setattr(setup, name, root / name.lower())
        setup.DIRECTORIES = tuple(getattr(setup, name) for name in paths)
        self.env = patch.dict(os.environ, {
            'APP_DOMAIN': 'stock.example.test',
            'APP_TRUSTED_HOST_PATTERN': '^stock[.]example[.]test\\z',
            'CLOUDFLARED_ADDRESS': '192.0.2.4',
            'RELEASE_ID': 'synthetic-only', 'DATABASE_RELEASE_ID': 'synthetic-only',
            'POSTGRES_DB': 'gestion_stock', 'POSTGRES_USER': 'gestion_stock'}, clear=True)
        self.env.start()
        self.addCleanup(self.env.stop)
        self.args = patch.object(setup.sys, 'argv', ['setup.py'])
        self.args.start()
        self.addCleanup(self.args.stop)

    def test_redeploy_preserves_secrets_and_separates_identities(self):
        setup.main()
        before = (setup.APP / 'db_password').read_bytes()
        setup.main()
        self.assertEqual(before, (setup.APP / 'db_password').read_bytes())
        self.assertEqual(before, (setup.DB / 'db_password').read_bytes())
        self.assertEqual(70, (setup.DB / 'db_password').stat().st_uid)
        self.assertEqual(10001, (setup.APP / 'db_password').stat().st_uid)
        self.assertFalse((setup.APP / 'db_admin_password').exists())
        self.assertFalse((setup.BACKUP / 'backup_identity').exists())
        self.assertEqual(0o400, (setup.APP / 'db_password').stat().st_mode & 0o777)
        self.assertEqual('null://null', (setup.APP / 'mailer_dsn').read_text().strip())

    def test_lost_secret_is_not_silently_regenerated(self):
        setup.main()
        (setup.DB / 'db_password').unlink()
        with self.assertRaisesRegex(SystemExit, 'restore_required'):
            setup.main()
        self.assertFalse((setup.DB / 'db_password').exists())

    def test_partial_initialization_is_not_overwritten(self):
        setup.APP.mkdir()
        (setup.APP / 'db_password').write_text('existing-value')
        with self.assertRaisesRegex(SystemExit, 'partial_installation'):
            setup.main()
        self.assertEqual('existing-value', (setup.APP / 'db_password').read_text())

    def test_database_identity_cannot_change_on_redeploy(self):
        setup.main()
        os.environ['POSTGRES_DB'] = 'another_database'
        with self.assertRaisesRegex(SystemExit, 'identity_change'):
            setup.main()

    def test_proxy_cannot_be_a_whole_network(self):
        os.environ['CLOUDFLARED_ADDRESS'] = '192.0.2.0/24'
        with self.assertRaises(ValueError):
            setup.main()
        self.assertFalse((setup.APP / 'app_secret').exists())

    def test_pattern_must_match_domain_exactly(self):
        os.environ['APP_TRUSTED_HOST_PATTERN'] = '.+'
        with self.assertRaisesRegex(SystemExit, 'pattern_mismatch'):
            setup.main()

    def test_world_readable_secret_is_refused(self):
        setup.main()
        (setup.APP / 'db_password').chmod(0o644)
        with self.assertRaisesRegex(SystemExit, 'permissions_invalid'):
            setup.main()


if __name__ == '__main__':
    unittest.main(verbosity=2)
