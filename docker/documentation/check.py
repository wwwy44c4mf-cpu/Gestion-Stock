"""Check repository-local Markdown targets; never follows external links."""
import re
from pathlib import Path
from urllib.parse import unquote, urlsplit

root = Path(__file__).resolve().parents[2]
files = [*root.glob('*.md'), *root.joinpath('docs').rglob('*.md')]
failures = []
for file in files:
    content = re.sub(r'```[^\n]*\n.*?```', '', file.read_text(), flags=re.S)
    for target in re.findall(r'\]\(([^\s)]+)(?:\s+"[^"]*")?\)', content):
        parsed = urlsplit(target.strip('<>'))
        if parsed.scheme or parsed.netloc or not parsed.path:
            continue
        path = (file.parent / unquote(parsed.path)).resolve()
        if not path.is_relative_to(root) or not path.exists():
            failures.append(f'{file.relative_to(root)}: {target}')
if failures:
    raise SystemExit('Broken local documentation links:\n' + '\n'.join(failures))
print(f'{len(files)} Markdown documents checked: local file targets exist.')
