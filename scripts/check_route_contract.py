#!/usr/bin/env python3
"""Bandingkan Laravel `route:list --json` vs OpenAPI JSON, tanpa mengubah data.

Contoh:
 php artisan route:list --json --path=api/v1 > routes.json
 python scripts/check_route_contract.py --routes routes.json --spec docs/api/openapi.json
"""
from __future__ import annotations
import argparse
import json
import re
import sys
from pathlib import Path


def normalize(path: str) -> str:
    return re.sub(r"\{[^}/]+\}", "{}", "/" + path.strip("/"))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--routes', required=True, type=Path, help='Output JSON dari artisan route:list')
    parser.add_argument('--spec', required=True, type=Path, help='Spesifikasi OpenAPI JSON')
    args = parser.parse_args()

    try:
        route_data = json.loads(args.routes.read_text(encoding='utf-8-sig'))
        spec = json.loads(args.spec.read_text(encoding='utf-8-sig'))
        if not isinstance(route_data, list) or not isinstance(spec.get('paths'), dict):
            raise ValueError('Struktur JSON tidak sesuai Laravel route:list atau OpenAPI.')
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        return 2

    methods = {'get','post','patch','put','delete'}
    expected = {
        (method.upper(), normalize(path))
        for path, operations in spec['paths'].items()
        for method in operations
        if method in methods
    }
    actual: set[tuple[str,str]] = set()
    for entry in route_data:
        uri = entry.get('uri', '')
        for method in str(entry.get('method', '')).split('|'):
            method = method.upper()
            if method.lower() in methods:
                actual.add((method, normalize(uri)))

    missing = sorted(expected - actual)
    additional = sorted((method,path) for method,path in actual - expected if path.startswith('/api/v1'))
    print(f'Endpoint OpenAPI: {len(expected)}; matched: {len(expected)-len(missing)}')
    if missing:
        print('\nMISSING DI LARAVEL (wajib ditinjau):')
        for method, path in missing:
            print(f'  {method:6} {path}')
    if additional:
        print('\nROUTE TAMBAHAN (review/dokumentasikan jika API publik):')
        for method, path in additional:
            print(f'  {method:6} {path}')
    if missing:
        return 1
    print('\nPASS: semua method/path OpenAPI telah ditemukan dalam daftar route Laravel.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
