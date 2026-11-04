"""Ulangi titik batas tiap stack: lima putaran, urutan stack diputar (Latin square), clone baru per run.

Titik diberikan per stack sebagai `stack=VU[,VU...]`, biasanya last-pass dan first-fail dari ramp. Ringkasan
per titik memuat jumlah lulus, median dan rentang persentil, serta throughput, agar variasi antar putaran
terlihat dan satu run yang kebetulan lulus atau gagal tidak menjadi batas.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from statistics import median

from ramp import CANDIDATES, PRIVATE, measure_retrying


def points(values: list[str]) -> dict[str, list[int]]:
    result = {}
    for value in values:
        candidate, _, steps = value.partition("=")
        if candidate not in CANDIDATES or not steps:
            raise SystemExit(f"Titik tidak valid: {value}; bentuknya stack=VU[,VU...].")
        result[candidate] = [int(step) for step in steps.split(",")]
    return result


def spread(values: list[float]) -> dict:
    present = [value for value in values if value is not None]
    if not present:
        return {"median": None, "min": None, "max": None}
    return {"median": round(median(present), 2), "min": min(present), "max": max(present)}


def summarize(runs: list[dict]) -> dict:
    summary: dict[str, dict] = {}
    for entry in runs:
        point = summary.setdefault(entry["candidate"], {}).setdefault(str(entry["vus"]), {"verdicts": []})
        point["verdicts"].append(entry["verdict"])
    for candidate, by_vus in summary.items():
        for vus, point in by_vus.items():
            chosen = [entry for entry in runs if entry["candidate"] == candidate and str(entry["vus"]) == vus]
            point["passes"] = sum(verdict.startswith("PASS") for verdict in point["verdicts"])
            for metric in ("read_p95", "read_p99", "write_p95", "write_p99", "rps", "write_rate_cv"):
                point[metric] = spread([entry[metric] for entry in chosen])
    return summary


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--name", required=True, help="Awalan run, huruf kecil dan angka.")
    parser.add_argument("--points", nargs="+", required=True, help="stack=VU[,VU...], misalnya rust=800,900")
    parser.add_argument("--repeats", type=int, default=5)
    parser.add_argument("--seed", type=Path, default=PRIVATE / "seed-128.dump")
    parser.add_argument("--fixture", type=Path, default=PRIVATE / "fixture-128.json")
    parser.add_argument("--baseline", type=Path, default=PRIVATE / "seed-128-baseline.json")
    parser.add_argument("--prepared", type=Path, help="Folder scripts/sessions.py: <stack>.dump, -sessions.json, -baseline.json per stack.")
    args = parser.parse_args()
    plan = points(args.points)
    candidates = list(plan)
    summary_path = PRIVATE / "load" / f"{args.name}-repeat.json"
    runs: list[dict] = []
    for repeat in range(args.repeats):
        order = candidates[repeat % len(candidates):] + candidates[: repeat % len(candidates)]
        for candidate in order:
            # Arah titik juga dibalik tiap putaran agar tidak selalu run kecil lebih dulu.
            steps = plan[candidate] if repeat % 2 == 0 else list(reversed(plan[candidate]))
            for vus in steps:
                attempts = measure_retrying(f"{args.name}{repeat + 1}", candidate, plan[candidate].index(vus), vus,
                                            f"{args.name}-{candidate}-{vus}-{repeat + 1}", args)
                final, report = attempts[-1], attempts[-1]["report"]
                verdict = final["verdict"]
                entry = {"repeat": repeat + 1, "candidate": candidate, "vus": vus, "run_id": final["run_id"], "verdict": verdict,
                         "read_p95": report.get("read", {}).get("p95"), "read_p99": report.get("read", {}).get("p99"),
                         "write_p95": report.get("write", {}).get("p95"), "write_p99": report.get("write", {}).get("p99"),
                         "rps": report.get("plateau_successful_rps"),
                         "write_rate_cv": report.get("plateau_rates", {}).get("write", {}).get("cv"),
                         "host_io_retries": [attempt["run_id"] for attempt in attempts[:-1]], "error": final["error"]}
                runs.append(entry)
                summary_path.write_text(json.dumps({"name": args.name, "points": plan, "repeats": args.repeats, "runs": runs,
                                                    "summary": summarize(runs)}, indent=2) + "\n", encoding="utf-8")
                print(json.dumps(entry), flush=True)
                if verdict == "ERROR":
                    print("RUN-ERROR: pengulangan dihentikan karena runner gagal; perbaiki sebelum melanjutkan.", flush=True)
                    print("ALL-DONE", flush=True)
                    raise SystemExit(1)
    print("ALL-DONE", flush=True)
