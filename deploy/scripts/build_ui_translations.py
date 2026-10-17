"""Merge reviewed EN/SV/DE text tables into Laravel JSON dictionaries."""
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--check', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
tables = {}
for path in sorted((root / 'lang').glob('ui-*.tsv')):
    for number, line in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
        if not line.strip():
            continue
        parts = line.split('\t')
        if len(parts) != 3 or any(not value for value in parts):
            raise SystemExit(f'{path.name}:{number}: expected three nonempty columns')
        key, sv, de = parts
        if key in tables and tables[key] != (sv, de):
            raise SystemExit(f'{path.name}:{number}: conflicting duplicate key')
        tables[key] = (sv, de)
for locale, column in (('en', None), ('sv', 0), ('de', 1)):
    path = root / 'lang' / f'{locale}.json'
    data = json.loads(path.read_text(encoding='utf-8'))
    expected = {key: key if column is None else values[column] for key, values in tables.items()}
    if args.check:
        missing = [key for key, value in expected.items() if data.get(key) != value]
        if missing:
            raise SystemExit(f'{locale}: {len(missing)} stale/missing table translations')
    else:
        data.update(expected)
        path.write_text(json.dumps(data, ensure_ascii=False, sort_keys=True, indent=4) + '\n', encoding='utf-8', newline='\n')
    print(f'{locale}: {len(data)} dictionary entries; {len(expected)} table entries verified')

en_path = root / 'lang/en.json'
en = json.loads(en_path.read_text(encoding='utf-8'))
for locale in ('sv', 'de'):
    for key in json.loads((root / 'lang' / f'{locale}.json').read_text(encoding='utf-8')):
        if key not in en:
            if args.check:
                raise SystemExit('en: missing explicit English fallback for ' + key)
            en[key] = key
if not args.check:
    en_path.write_text(json.dumps(en, ensure_ascii=False, sort_keys=True, indent=4) + '\n', encoding='utf-8', newline='\n')
