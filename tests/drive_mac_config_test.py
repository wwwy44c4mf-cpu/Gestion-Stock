"""Local fixture tests: never open the owner's OAuth JSON or configuration."""
import base64
import contextlib
import io
import json
from pathlib import Path
import runpy
import stat
import tempfile
import unittest
from unittest.mock import patch

helper = runpy.run_path(str(Path(__file__).resolve().parents[1] / 'bin/configure-drive-mac'))
prepare, main = helper['prepare'], helper['main']


class MacConfigTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.source = self.home / 'client.json'
        self.target = self.home / '.config/gestion-stock/rclone.conf'
        self.source.write_text(json.dumps({'installed': {'client_id': '123-fixture.apps.googleusercontent.com',
                                                       'client_secret': 'GOCSPX-fixture-only'}}))

    def test_import_private_and_exact_without_touching_other_remotes(self):
        prepare(self.source, self.target, 'fixture_folder_1234')
        self.assertIn('client_id = 123-fixture.apps.googleusercontent.com', self.target.read_text())
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.target.parent.stat().st_mode), 0o700)
        self.target.write_text('[other]\ntype = local\n')
        with self.assertRaises(ValueError):
            prepare(self.source, self.target, 'fixture_folder_1234')
        self.assertIn('[other]', self.target.read_text())

    def test_wrong_client_and_ini_injection_do_not_replace_existing_file(self):
        prepare(self.source, self.target, 'fixture_folder_1234')
        before = self.target.read_bytes()
        for data in ({'web': {}}, {'installed': {'client_id': 'bad\n[evil]', 'client_secret': 'fixture'}},
                     {'installed': {'client_id': '123.apps.googleusercontent.com', 'client_secret': 'bad\ntype = local'}}):
            self.source.write_text(json.dumps(data))
            with self.assertRaises(ValueError):
                prepare(self.source, self.target, 'fixture_folder_1234')
            self.assertEqual(self.target.read_bytes(), before)

    def test_export_only_copies_to_local_clipboard_never_prints_tokens_or_uses_secret_arguments(self):
        prepare(self.source, self.target, 'fixture_folder_1234')
        with self.target.open('a') as handle:
            handle.write('token = {"access_token":"fixture-access", "refresh_token":"fixture-refresh"}\n')
        binary = self.home / 'Applications/rclone-gestion-stock/rclone'
        binary.parent.mkdir(parents=True)
        binary.touch()
        output = io.StringIO()
        with patch.object(helper['sys'], 'argv', ['configure-drive-mac', '--copy-config']), \
             patch.object(helper['sys'], 'platform', 'darwin'), patch.object(helper['Path'], 'home', return_value=self.home), \
             patch.object(helper['subprocess'], 'run') as run, contextlib.redirect_stdout(output):
            self.assertEqual(main(), 0)
        self.assertEqual(run.call_args.args, (['/usr/bin/pbcopy'],))
        self.assertIn(b'fixture-refresh', base64.b64decode(run.call_args.kwargs['input']))
        self.assertNotIn('fixture-refresh', output.getvalue())
        self.assertNotIn('GOCSPX-fixture-only', output.getvalue())


if __name__ == '__main__':
    unittest.main(verbosity=2)
