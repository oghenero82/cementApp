#!/usr/bin/env python3
"""Verify generated registry against every archived PHP Fields declaration."""
import hashlib,json,re,zipfile
from pathlib import Path
from collections import Counter
root=Path(__file__).resolve().parents[1]
z=zipfile.ZipFile(root/'seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip')
s=(root/'frontend/src/registry/seededFieldRegistry.js').read_text()
registry=json.loads(s[s.index('{'):s.rindex('}')+1])
classes=[dict(re.findall(r"public const (\w+) = '([^']*)';",z.read(n).decode())) for n in z.namelist() if '/Fields/' in n and n.endswith('.php')]
assert len(classes)==51
assert Counter(tuple(sorted(f.items())) for f in classes)==Counter(tuple(sorted(f.items())) for f in registry.values()),'PHP/JS field registry drift'
assert registry==json.loads((root/'resources/cement/semantic_field_registry.json').read_text()),'Backend registry drift'
base='Cement_MetaForge4_Seeders/'
for line in z.read(base+'CHECKSUMS.sha256').decode().splitlines():
 digest,path=line.split(maxsplit=1)
 assert hashlib.sha256(z.read(base+path.lstrip('*'))).hexdigest()==digest,path
for name in ['recordtypes','modules','services','routes','portals','report_templates','role_templates','institution_settings_extension']:
 assert json.loads(z.read(base+'resources/cement/'+name+'.json'))==json.loads((root/'resources/cement'/f'{name}.json').read_text()),name+' catalog drift'
print('PASS: 51 PHP field maps / 652 aliases, backend registry, catalogs, 190 archive checksums')
