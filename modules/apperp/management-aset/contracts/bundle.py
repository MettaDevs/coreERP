"""Bangun contracts/openapi.yaml dari contracts/src/.

AGENTS.md mewajibkan kontrak dipecah begitu melewati ~1500 baris dan bundle-nya di-commit.
Bundle tetap berada di `contracts/openapi.yaml` supaya `app.yaml` (kunci `api.openapi`) dan
Control Plane membaca path yang sama seperti sebelum kontrak dipecah.

Sumber kebenaran ada di `contracts/src/`. Jangan pernah menyunting `contracts/openapi.yaml`
langsung; ia ditimpa setiap kali skrip ini dijalankan.

Root `src/openapi.yaml` mendaftarkan potongannya lewat kunci ekstensi `x-bundle`, sehingga
file sumber tetap dokumen OpenAPI yang sah. Referensi `#/components/...` di dalam potongan
dibiarkan apa adanya karena resolusinya terjadi di bundle, bukan per file.

Pemakaian:
    python contracts/bundle.py            # bangun ulang bundle
    python contracts/bundle.py --check    # gagal bila bundle tidak sinkron dengan sumber (CI)
"""

import pathlib
import sys

import yaml

ROOT = pathlib.Path(__file__).resolve().parent
SRC = ROOT / "src"
BUNDLE = ROOT / "openapi.yaml"
HEADER = (
    "# DIBANGKITKAN OLEH contracts/bundle.py - JANGAN DISUNTING LANGSUNG.\n"
    "# Sunting contracts/src/ lalu jalankan: python contracts/bundle.py\n"
)


def _literal_str(dumper, data):
    style = "|" if "\n" in data else None
    return dumper.represent_scalar("tag:yaml.org,2002:str", data, style=style)


class _Dumper(yaml.SafeDumper):
    pass


_Dumper.add_representer(str, _literal_str)


def _load(relative: str):
    path = SRC / relative
    if not path.is_file():
        raise SystemExit(f"potongan kontrak tidak ditemukan: {path}")
    return yaml.safe_load(path.read_text(encoding="utf-8")) or {}


def build() -> str:
    root = yaml.safe_load((SRC / "openapi.yaml").read_text(encoding="utf-8"))
    manifest = root.pop("x-bundle", None)
    if not manifest:
        raise SystemExit("src/openapi.yaml tidak memiliki kunci x-bundle")

    paths: dict = {}
    for relative in manifest.get("paths", []):
        fragment = _load(relative)
        overlap = set(fragment) & set(paths)
        if overlap:
            raise SystemExit(f"path ganda di {relative}: {sorted(overlap)}")
        paths.update(fragment)

    components: dict = {}
    for section, relative in manifest.get("components", {}).items():
        components[section] = _load(relative)

    root["paths"] = paths
    root["components"] = components
    body = yaml.dump(
        root,
        Dumper=_Dumper,
        sort_keys=False,
        allow_unicode=True,
        default_flow_style=False,
        width=120,
    )
    return HEADER + body


def main() -> int:
    generated = build()
    check = "--check" in sys.argv

    if check:
        if not BUNDLE.is_file():
            print("bundle belum dibangun: jalankan python contracts/bundle.py")
            return 1
        current = BUNDLE.read_text(encoding="utf-8")
        if yaml.safe_load(current) != yaml.safe_load(generated):
            print("bundle tidak sinkron dengan contracts/src/: jalankan python contracts/bundle.py")
            return 1
        print("bundle sinkron dengan contracts/src/")
        return 0

    BUNDLE.write_text(generated, encoding="utf-8")
    doc = yaml.safe_load(generated)
    print(
        f"{BUNDLE.name} dibangun: {len(generated.splitlines())} baris, "
        f"{len(doc['paths'])} path, {len(doc['components'])} bagian components."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
