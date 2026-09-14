#!/usr/bin/env python3
"""Gagal bila route API admin.erp dan kontraknya tidak sepakat.

Route di bawah `/api` dipanggil agen di server klien dan alur rilis — keduanya di luar repo PHP ini.
Tidak ada test yang gagal ketika satu route lupa ditulis di kontrak; pemeriksaan ini satu-satunya
yang menyadarinya.

Route dibaca dari Laravel sendiri (`php artisan route:list --json`), bukan dari teks berkas route:
route yang didaftarkan lewat grup berprefix tidak pernah muncul sebagai string utuh di sana.

Jalankan dari `apps/control-plane`:

    python contracts/check-contract-coverage.py
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    sys.exit("PyYAML dibutuhkan: pip install pyyaml")

CONTRACT = Path("contracts/openapi-agent.yaml")
HTTP_METHODS = ("get", "post", "put", "patch", "delete")


def routes_in_code() -> set[tuple[str, str]]:
    output = subprocess.run(
        ["php", "artisan", "route:list", "--json", "--path=api"],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    routes = set()

    for route in json.loads(output):
        uri = "/" + route["uri"].lstrip("/")
        if not uri.startswith("/api/"):
            continue
        path = uri[len("/api"):]
        for method in route["method"].split("|"):
            if method.lower() in HTTP_METHODS:
                routes.add((method.upper(), normalise(path)))

    return routes


def routes_in_contract() -> set[tuple[str, str]]:
    spec = yaml.safe_load(CONTRACT.read_text(encoding="utf-8"))
    return {
        (method.upper(), normalise(path))
        for path, operations in (spec.get("paths") or {}).items()
        for method in operations
        if method in HTTP_METHODS
    }


def normalise(path: str) -> str:
    """Nama parameter tidak ikut dibandingkan: `{operation}` dan `{id}` menunjuk route yang sama."""
    return re.sub(r"\{[^}]+\}", "{}", path)


def main() -> int:
    code = routes_in_code()
    contract = routes_in_contract()

    if not code:
        # Pemeriksa yang tidak menemukan satu route pun hampir pasti salah membaca, bukan lulus.
        print("Tidak satu pun route API terbaca dari Laravel. Pemeriksaan ini tidak boleh lulus kosong.")
        return 1

    missing = code - contract
    stale = contract - code

    for method, path in sorted(missing):
        print(f"Route tanpa kontrak:   {method:6} /api{path}")
    for method, path in sorted(stale):
        print(f"Kontrak tanpa route:   {method:6} /api{path}")

    if missing or stale:
        print("\nKontrak dan route harus bergerak bersama. Perbarui contracts/openapi-agent.yaml.")
        return 1

    print(f"Kontrak mencakup seluruh {len(code)} route API.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
