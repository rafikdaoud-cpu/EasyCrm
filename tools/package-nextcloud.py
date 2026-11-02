#!/usr/bin/env python3
"""Package each Nextcloud app under its real app ID (stdlib only)."""
from pathlib import Path
import hashlib
import sys
import xml.etree.ElementTree as ET
import zipfile

root = Path(__file__).resolve().parents[1]
out = Path(sys.argv[1]).resolve() if len(sys.argv) == 2 else None
if out is None:
    raise SystemExit('Usage: python3 tools/package-nextcloud.py OUTPUT_DIRECTORY')
out.mkdir(parents=True, exist_ok=True)
for folder in ('callcrm', 'nextcloud'):
    app = root / folder
    info = ET.parse(app / 'appinfo/info.xml').getroot()
    app_id, version = info.findtext('id'), info.findtext('version')
    if app_id not in ('callcrm', 'easycrm') or not version:
        raise SystemExit(f'Invalid app metadata: {folder}')
    archive = out / f'{app_id}-{version}.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
        for path in sorted(app.rglob('*')):
            relative = path.relative_to(app)
            if any(p.startswith('.') or p in ('node_modules', '__pycache__', 'build') for p in relative.parts):
                continue
            if path.is_symlink():
                raise SystemExit(f'Refusing symlink in package: {path}')
            if path.is_file():
                bundle.write(path, Path(app_id) / relative)
    with zipfile.ZipFile(archive) as bundle:
        if bundle.testzip() is not None:
            raise SystemExit(f'Corrupt archive: {archive}')
        assert f'{app_id}/appinfo/info.xml' in bundle.namelist()
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix('.zip.sha256').write_text(f'{checksum}  {archive.name}\n')
    print(f'{archive.name}: SHA256 {checksum}')
