"""Titik pengulangan dari hasil ramp: last-pass dan first-fail tiap stack, untuk argumen --points repeat.py.

Beberapa ramp boleh menyebut stack yang sama (misalnya ramp lanjutan sesudah stack lulus semua langkah); hasilnya
digabung per stack. Stack yang salah satu run-nya masih memuat login (sesi siap pakai ditolak), atau yang ujung
ramp-nya tidak meyakinkan tanpa first-fail, dilaporkan ke stderr agar diperiksa manusia.
"""

from __future__ import annotations

import argparse
from collections import defaultdict
import json
from pathlib import Path
import sys

PRIVATE = Path(__file__).resolve().parents[1] / "private"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("ramps", nargs="+", help="Nama ramp, misalnya r9n r9b r9p")
    args = parser.parse_args()
    runs: dict[str, list[dict]] = defaultdict(list)
    stopped: dict[str, list] = defaultdict(list)
    for name in args.ramps:
        path = PRIVATE / "load" / f"{name}-ramp.json"
        if not path.exists():
            print(f"{name}: ringkasan ramp tidak ada", file=sys.stderr)
            continue
        for candidate, state in json.loads(path.read_text(encoding="utf-8"))["candidates"].items():
            runs[candidate].extend(state["runs"])
            if state["stopped"]:
                stopped[candidate].append(state["stopped"])
    points, problems = [], []
    for candidate, entries in runs.items():
        logins = []
        for entry in entries:
            report = PRIVATE / "load" / entry["run_id"] / "report.json"
            if report.exists() and json.loads(report.read_text(encoding="utf-8"))["login_all_phases"]["count"]:
                logins.append(entry["run_id"])
        if logins:
            problems.append(f"{candidate}: run masih memuat login {logins}")
            continue
        passes = [entry["vus"] for entry in entries if entry["verdict"].startswith("PASS")]
        fails = [entry["vus"] for entry in entries if entry["verdict"] == "FAIL"]
        last_pass = max(passes) if passes else None
        above = [vus for vus in fails if last_pass is None or vus > last_pass]
        first_fail = min(above) if above else None
        if last_pass is not None and any(vus < last_pass for vus in fails):
            problems.append(f"{candidate}: gagal di bawah last-pass {sorted(v for v in fails if v < last_pass)}, hasil tidak monoton")
        if first_fail is None:
            problems.append(f"{candidate}: first-fail belum ada (last-pass {last_pass}, berhenti {stopped[candidate] or '-'})")
        steps = [vus for vus in (last_pass, first_fail) if vus is not None]
        if steps:
            points.append(f"{candidate}={','.join(map(str, steps))}")
    for problem in problems:
        print(problem, file=sys.stderr)
    print(" ".join(points))
    return 0 if points else 1


if __name__ == "__main__":
    raise SystemExit(main())
