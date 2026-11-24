from datetime import datetime, timezone
from html import escape
from pathlib import Path
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
bootstrap = (root / 'codeon-shipping-rules.php').read_text()
readme = (root / 'readme.txt').read_text()
version = re.search(r'^ \* Version: (.+)$', bootstrap, re.M)[1]
runtime_version = re.search(r"define\('CODEON_SHIPPING_RULES_VERSION', '([^']+)'\);", bootstrap)[1]
stable_tag = re.search(r'^Stable tag: (.+)$', readme, re.M)[1]
if version != runtime_version or version != stable_tag:
    raise SystemExit('Plugin header, runtime constant and readme version must agree.')
if len(sys.argv) > 2 and sys.argv[2] != f'v{version}':
    raise SystemExit('Release tag does not match the plugin version.')
update_uri = re.search(r'^ \* Update URI: (.+)$', bootstrap, re.M)[1].rstrip('/')
if update_uri != re.search(r"define\('CODEON_SHIPPING_RULES_UPDATE_URI', '([^']+)'\);", bootstrap)[1].rstrip('/'):
    raise SystemExit('The Update URI header and runtime constant must agree.')
destination = Path(sys.argv[1] if len(sys.argv) > 1 else root / 'dist')
destination.mkdir(parents=True, exist_ok=True)

if not (root / 'vendor/autoload_packages.php').is_file():
    raise SystemExit('Run composer install before packaging.')

runtime_roots = {
    'assets', 'includes', 'languages', 'vendor', 'codeon-shipping-rules.php',
    'uninstall.php', 'wpml-config.xml', 'readme.txt', 'LICENSE', 'composer.json',
    'composer.lock',
}
skip_parts = {'.git', '.DS_Store', 'node_modules', 'dist', '__pycache__'}


def archive(output, source=False):
    files = []
    for path in root.rglob('*'):
        relative = path.relative_to(root)
        parts = relative.parts
        if any(item in skip_parts for item in parts) or not path.is_file() or path.is_symlink():
            continue
        if source:
            if parts[0] == 'vendor':
                continue
        elif parts[0] not in runtime_roots:
            continue
        elif parts[0] == 'vendor' and any(item in {'tests', '.github', 'docs'} for item in parts[1:]):
            continue
        files.append((relative, path))

    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for relative, path in sorted(files):
            entry = zipfile.ZipInfo(str(Path('codeon-shipping-rules') / relative), (2026, 1, 1, 0, 0, 0))
            entry.external_attr = 0o644 << 16
            chmod = path.stat().st_mode & 0o111
            if chmod:
                entry.external_attr |= chmod << 16
            package.writestr(entry, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)

    print(f'{output.name}: {output.stat().st_size:,} bytes, {len(files)} files')


def readme_section(name):
    match = re.search(rf'^== {re.escape(name)} ==\n(.*?)(?=^== |\Z)', readme, re.M | re.S)
    return match[1].strip() if match else ''


def to_html(text):
    lines, in_list = [], False
    for line in text.splitlines():
        line = line.rstrip()
        item = re.match(r'^\* (.+)$', line)
        if item:
            if not in_list:
                lines.append('<ul>')
                in_list = True
            lines.append(f'<li>{escape(item[1])}</li>')
            continue
        if in_list:
            lines.append('</ul>')
            in_list = False
        heading = re.match(r'^= (.+) =$', line)
        if heading:
            lines.append(f'<h4>{escape(heading[1])}</h4>')
        elif line:
            lines.append(f'<p>{escape(line)}</p>')
    if in_list:
        lines.append('</ul>')
    return '\n'.join(lines)


def header(text, name):
    match = re.search(rf'^(?: \* )?{re.escape(name)}: (.+)$', text, re.M)
    return match[1].strip() if match else ''


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


release = destination / f'codeon-shipping-rules-{version}.zip'
source = destination / f'codeon-shipping-rules-{version}-source.zip'
manifest = destination / 'update.json'
archive(release)
archive(source, source=True)
if release.stat().st_size > 1_500_000:
    raise SystemExit('Install ZIP exceeds the 1.5 MB release budget.')

# WordPress reads this from the latest release to offer and verify automatic updates.
manifest.write_text(json.dumps({
    'slug': 'codeon-shipping-rules',
    'name': header(bootstrap, 'Plugin Name'),
    'version': version,
    'package': f'{update_uri}/releases/download/v{version}/{release.name}',
    'sha256': sha256(release),
    'requires': header(bootstrap, 'Requires at least'),
    'requires_php': header(bootstrap, 'Requires PHP'),
    'tested': header(readme, 'Tested up to'),
    'released': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'sections': {
        'description': to_html(readme_section('Description')),
        'changelog': to_html(readme_section('Changelog')),
    },
}, indent=2, ensure_ascii=False) + '\n')

sums = ''.join(f'{sha256(path)}  {path.name}\n' for path in [release, source, manifest])
(destination / 'SHA256SUMS.txt').write_text(sums)
print(sums, end='')
