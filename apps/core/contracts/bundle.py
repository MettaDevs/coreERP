#!/usr/bin/env python3
"""Merakit kontrak internal dari sumber pecahannya.

Sumber diedit tangan di `contracts/internal/`:

- satu akar per pembaca: satu per domain integrasi untuk sistem luar (`integrasi-finance.yaml`,
  kelak `integrasi-sdm.yaml`, dan seterusnya), ditambah `app.yaml` dan `pusat-admin.yaml`. Akar
  berisi info, keamanan, tag, daftar path yang boleh dilihat pembacanya, dan `x-portal` — judul,
  urutan, dan apakah ia terbit tanpa login di portal `/docs`;
- satu berkas per path (`paths/`), per webhook (`webhooks/`), dan per komponen (`components/`);
- `gabungan.yaml`, kepala berkas gabungan.

Akar baru cukup ditaruh di folder itu: perakit menemukannya sendiri, menulis spesifikasinya, dan
mendaftarkannya di katalog portal. Tidak ada daftar akar yang harus ikut disunting.

Komponen tidak perlu didaftarkan di akar: setiap `$ref: '#/components/<jenis>/<Nama>'` yang
dirujuk path diambil dari `components/<jenis>/<Nama>.yaml` dengan sendirinya.

Berkas hasil tidak pernah diedit tangan:

- `contracts/terbit/<akar>.yaml` — satu spesifikasi per akar. Keamanan tiap operasi disaring ke
  skema yang dikenal akarnya, jadi endpoint yang dibaca dua pembaca, misalnya
  `/operating-units`, hanya menampilkan cara masuk milik pembaca itu.
- `contracts/terbit/katalog.json` — daftar spesifikasi untuk portal `/docs`, dari `x-portal`.
- `contracts/openapi-internal.yaml` — gabungan seluruh pembaca, tanpa penyaringan. Jalurnya
  dipertahankan karena dibaca pemeriksa cakupan, test pusat admin, dan dokumen lain.

Pemakaian, dari `apps/core`:

    python contracts/bundle.py          # tulis ulang berkas hasil
    python contracts/bundle.py --check  # gagal bila berkas hasil tidak sama dengan sumbernya (CI)
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

try:
    import yaml
except ImportError:
    sys.exit("PyYAML is required: pip install pyyaml")

SUMBER = Path("contracts/internal")
DIST = Path("contracts/terbit")
KATALOG = DIST / "katalog.json"
GABUNGAN = Path("contracts/openapi-internal.yaml")
KEPALA_GABUNGAN = "gabungan.yaml"
METODE = ("get", "put", "post", "delete", "options", "head", "patch", "trace")
KEPALA = (
    "# Berkas hasil `python contracts/bundle.py` dari `contracts/internal/`.\n"
    "# Jangan diedit tangan: suntingan di sini hilang pada perakitan berikutnya, dan CI menolaknya.\n"
)


def muat(berkas: Path) -> Any:
    return yaml.safe_load(berkas.read_text(encoding="utf-8"))


def sisipkan_berkas(node: Any, dasar: Path) -> Any:
    """Mengganti setiap `$ref` ke berkas dengan isi berkas itu; `$ref` internal dibiarkan."""
    if isinstance(node, dict):
        ref = node.get("$ref")
        if isinstance(ref, str) and not ref.startswith("#"):
            if len(node) != 1:
                sys.exit(f"$ref berkas tidak boleh bersanding dengan kunci lain: {ref} (di {dasar})")
            berkas = (dasar / ref).resolve()
            if not berkas.exists():
                sys.exit(f"$ref menunjuk berkas yang tidak ada: {ref} (dari {dasar})")
            return sisipkan_berkas(muat(berkas), berkas.parent)
        return {kunci: sisipkan_berkas(nilai, dasar) for kunci, nilai in node.items()}
    if isinstance(node, list):
        return [sisipkan_berkas(nilai, dasar) for nilai in node]
    return node


def rujukan_komponen(node: Any, hasil: set[tuple[str, str]]) -> None:
    if isinstance(node, dict):
        ref = node.get("$ref")
        if isinstance(ref, str) and ref.startswith("#/components/"):
            _, _, jenis, nama = ref.split("/", 3)
            hasil.add((jenis, nama))
        for nilai in node.values():
            rujukan_komponen(nilai, hasil)
    elif isinstance(node, list):
        for nilai in node:
            rujukan_komponen(nilai, hasil)


def lengkapi_komponen(doc: dict[str, Any]) -> None:
    komponen = doc.setdefault("components", {})
    while True:
        dibutuhkan: set[tuple[str, str]] = set()
        rujukan_komponen(doc, dibutuhkan)
        kurang = sorted((j, n) for j, n in dibutuhkan if n not in komponen.get(j, {}))
        if not kurang:
            break
        for jenis, nama in kurang:
            berkas = SUMBER / "components" / jenis / f"{nama}.yaml"
            if not berkas.exists():
                sys.exit(f"Komponen {jenis}/{nama} dirujuk, tetapi {berkas} tidak ada.")
            komponen.setdefault(jenis, {})[nama] = sisipkan_berkas(muat(berkas), berkas.parent)
    # Urutan tetap supaya hasil perakitan tidak berubah hanya karena urutan penemuan.
    doc["components"] = {
        jenis: dict(sorted(isi.items())) if jenis != "securitySchemes" else isi
        for jenis, isi in komponen.items()
    }


def operasi(doc: dict[str, Any]):
    for kumpulan in ("paths", "webhooks"):
        for jalur, item in (doc.get(kumpulan) or {}).items():
            for metode, op in item.items():
                if metode in METODE and isinstance(op, dict):
                    yield kumpulan, jalur, metode, op


def saring_keamanan(doc: dict[str, Any], nama: str) -> None:
    skema = set((doc.get("components") or {}).get("securitySchemes") or {})

    def dikenal(syarat: dict[str, Any]) -> bool:
        return all(kunci in skema for kunci in syarat)

    if "security" in doc:
        doc["security"] = [syarat for syarat in doc["security"] if dikenal(syarat)]
    for _, jalur, metode, op in operasi(doc):
        if "security" in op:
            tersisa = [syarat for syarat in op["security"] if dikenal(syarat)]
            if not tersisa:
                sys.exit(f"{nama}: {metode.upper()} {jalur} tidak punya cara masuk yang dikenal akar ini.")
            op["security"] = tersisa


def periksa(doc: dict[str, Any], nama: str) -> None:
    tag = {t["name"] for t in doc.get("tags") or []}
    skema = set((doc.get("components") or {}).get("securitySchemes") or {})
    for kumpulan, jalur, metode, op in operasi(doc):
        for t in op.get("tags") or []:
            if t not in tag:
                sys.exit(f"{nama}: tag '{t}' pada {metode.upper()} {jalur} tidak dideklarasikan akar.")
        if kumpulan == "paths" and "security" not in op and not doc.get("security"):
            sys.exit(f"{nama}: {metode.upper()} {jalur} tidak menyebut keamanan dan akar tidak punya bawaan.")
        for syarat in op.get("security") or []:
            for kunci in syarat:
                if kunci not in skema:
                    sys.exit(f"{nama}: skema keamanan '{kunci}' pada {metode.upper()} {jalur} tidak didefinisikan.")


def akar_pembaca() -> list[tuple[str, dict[str, Any]]]:
    """Setiap akar di folder sumber beserta `x-portal`-nya, urut sesuai `urutan`."""
    hasil = []
    for berkas in sorted(SUMBER.glob("*.yaml")):
        if berkas.name == KEPALA_GABUNGAN:
            continue
        portal = (muat(berkas) or {}).get("x-portal")
        if not isinstance(portal, dict) or not {"judul", "publik", "urutan"} <= set(portal):
            sys.exit(f"{berkas}: akar wajib punya x-portal dengan judul, publik, dan urutan.")
        hasil.append((berkas.stem, portal))
    return sorted(hasil, key=lambda item: (item[1]["urutan"], item[0]))


def rakit_pembaca(nama: str) -> dict[str, Any]:
    akar = SUMBER / f"{nama}.yaml"
    doc = sisipkan_berkas(muat(akar), akar.parent)
    doc.pop("x-portal", None)
    lengkapi_komponen(doc)
    saring_keamanan(doc, nama)
    periksa(doc, nama)
    return doc


def rakit_gabungan(akar_urut: list[str]) -> dict[str, Any]:
    """Seluruh pembaca dalam satu dokumen: kepala dari `gabungan.yaml`, isi dari tiap akar."""
    kepala_berkas = SUMBER / KEPALA_GABUNGAN
    doc = muat(kepala_berkas)
    paths: dict[str, Any] = {}
    webhooks: dict[str, Any] = {}
    skema: dict[str, Any] = {}
    for nama in akar_urut:
        akar = muat(SUMBER / f"{nama}.yaml")
        for jalur, ref in (akar.get("paths") or {}).items():
            paths.setdefault(jalur, ref)
        for kait, ref in (akar.get("webhooks") or {}).items():
            webhooks.setdefault(kait, ref)
        for kunci, ref in ((akar.get("components") or {}).get("securitySchemes") or {}).items():
            skema.setdefault(kunci, ref)
    doc["paths"] = paths
    if webhooks:
        doc["webhooks"] = webhooks
    doc["components"] = {"securitySchemes": skema}
    doc = sisipkan_berkas(doc, kepala_berkas.parent)
    lengkapi_komponen(doc)
    periksa(doc, "gabungan")
    return doc


class _Dumper(yaml.SafeDumper):
    pass


def _teks(dumper: yaml.SafeDumper, nilai: str) -> yaml.ScalarNode:
    if "\n" in nilai:
        return dumper.represent_scalar("tag:yaml.org,2002:str", nilai, style="|")
    return dumper.represent_scalar("tag:yaml.org,2002:str", nilai)


_Dumper.add_representer(str, _teks)


def tulis(doc: dict[str, Any]) -> str:
    return KEPALA + yaml.dump(doc, Dumper=_Dumper, sort_keys=False, allow_unicode=True, width=100)


def main() -> int:
    if not SUMBER.exists():
        sys.exit(f"Tidak ditemukan: {SUMBER}. Jalankan dari apps/core.")

    akar = akar_pembaca()
    hasil = {DIST / f"{nama}.yaml": tulis(rakit_pembaca(nama)) for nama, _ in akar}
    hasil[GABUNGAN] = tulis(rakit_gabungan([nama for nama, _ in akar]))
    katalog = [
        {"id": nama, "judul": portal["judul"], "publik": bool(portal["publik"]), "berkas": f"{nama}.yaml"}
        for nama, portal in akar
    ]
    hasil[KATALOG] = json.dumps(katalog, ensure_ascii=False, indent=2) + "\n"
    yatim = sorted(str(b) for b in DIST.glob("*.yaml") if b not in hasil)
    if yatim:
        sys.exit("Spesifikasi di contracts/terbit/ tanpa akar di contracts/internal/: " + ", ".join(yatim))

    if "--check" in sys.argv[1:]:
        basi = [str(b) for b, isi in hasil.items() if not b.exists() or b.read_text(encoding="utf-8") != isi]
        if basi:
            print("Berkas kontrak hasil tidak sama dengan sumbernya di contracts/internal/:")
            for b in basi:
                print(f"  {b}")
            print("  -> Jalankan `python contracts/bundle.py` dari apps/core lalu commit hasilnya.")
            return 1
        print(f"OK: {len(hasil)} berkas kontrak hasil sama dengan sumbernya.")
        return 0

    for berkas, isi in hasil.items():
        berkas.parent.mkdir(parents=True, exist_ok=True)
        berkas.write_text(isi, encoding="utf-8", newline="\n")
        print(f"Ditulis: {berkas}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
