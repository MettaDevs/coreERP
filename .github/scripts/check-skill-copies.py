#!/usr/bin/env python3
"""Pastikan skill yang ada di `.agents/skills` dan `.claude/skills` benar-benar sama.

Dua folder itu dibaca alat yang berbeda, dan isinya sudah menyimpang diam-diam sekali:
tiga skill berbeda isinya, masing-masing dengan aturan yang tidak dimiliki salinannya.
Tidak ada yang tahu sampai keduanya dibandingkan tangan. Pemeriksaan ini membuat
penyimpangan berikutnya berhenti di pull request, bukan ditemukan berbulan-bulan kemudian.

Skill yang hanya ada di satu folder dibiarkan. Yang diperiksa hanya nama yang ada di
kedua folder, karena itulah yang berjanji sama.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LEFT = ROOT / ".agents" / "skills"
RIGHT = ROOT / ".claude" / "skills"


def files_under(folder: Path) -> dict[str, bytes]:
    """Isi tiap berkas, dengan akhir baris dinormalkan supaya CRLF tidak dihitung beda."""
    out: dict[str, bytes] = {}
    for path in sorted(folder.rglob("*")):
        if path.is_file():
            rel = path.relative_to(folder).as_posix()
            out[rel] = path.read_bytes().replace(b"\r\n", b"\n")
    return out


def main() -> int:
    if not LEFT.is_dir() or not RIGHT.is_dir():
        print(f"Folder skill tidak ditemukan: {LEFT} atau {RIGHT}", file=sys.stderr)
        return 2

    shared = sorted({p.name for p in LEFT.iterdir() if p.is_dir()} &
                    {p.name for p in RIGHT.iterdir() if p.is_dir()})
    problems: list[str] = []

    for name in shared:
        left, right = files_under(LEFT / name), files_under(RIGHT / name)
        for rel in sorted(set(left) - set(right)):
            problems.append(f"{name}/{rel}: ada di .agents, tidak ada di .claude")
        for rel in sorted(set(right) - set(left)):
            problems.append(f"{name}/{rel}: ada di .claude, tidak ada di .agents")
        for rel in sorted(set(left) & set(right)):
            if left[rel] != right[rel]:
                problems.append(f"{name}/{rel}: isinya berbeda")

    if problems:
        print("Salinan skill menyimpang:\n", file=sys.stderr)
        for line in problems:
            print(f"  - {line}", file=sys.stderr)
        print(
            "\nSamakan keduanya sebelum melanjutkan. Gabungkan isinya, jangan menimpa"
            "\nsalah satu begitu saja: keduanya bisa membawa aturan yang tidak dimiliki"
            "\nyang lain.",
            file=sys.stderr,
        )
        return 1

    print(f"OK: {len(shared)} skill ada di kedua folder dan isinya sama.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
