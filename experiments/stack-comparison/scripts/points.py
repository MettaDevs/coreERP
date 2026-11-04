"""Titik pengulangan dari hasil ramp: last-pass dan first-fail tiap stack, untuk argumen --points repeat.py.

Stack yang ramp-nya berhenti karena run tidak meyakinkan, atau yang salah satu run-nya masih memuat login
(sesi siap pakai ditolak), tidak diberi titik dan dilaporkan ke stderr agar diperiksa manusia.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

PRIVATE = Path(__file__).resolve().parents[1] / "private"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("ramps", nargs="+", help="Nama ramp, misalnya r9n r9b r9p")
    args = parser.parse_args()
    points, problems = [], []
    for name in args.ramps:
        summary = json.loads((PRIVATE / "load" / f"{name}-ramp.json").read_text(encoding="utf-8"))
        for candidate, state in summary["candidates"].items():
            logins = []
            for entry in state["runs"]:
                report = PRIVATE / "load" / entry["run_id"] / "report.json"
                if report.exists() and json.loads(report.read_text(encoding="utf-8"))["login_all_phases"]["count"]:
                    logins.append(entry["run_id"])
            if logins:
                problems.append(f"{candidate}: run masih memuat login {logins}")
            elif state["stopped"]:
                problems.append(f"{candidate}: ramp berhenti {state['stopped']}")
            else:
                steps = [vus for vus in (state["last_pass"], state["first_fail"]) if vus is not None]
                if state["first_fail"] is None:
                    problems.append(f"{candidate}: lulus semua langkah sampai {state['last_pass']}, first-fail belum ada")
                if steps:
                    points.append(f"{candidate}={','.join(map(str, steps))}")
    for problem in problems:
        print(problem, file=sys.stderr)
    print(" ".join(points))
    return 0 if points else 1


if __name__ == "__main__":
    raise SystemExit(main())
