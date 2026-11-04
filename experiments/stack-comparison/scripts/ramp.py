"""Cari last-pass/first-fail per stack: langkah VU profil, urutan stack diputar, clone baru per run."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import sys

LAB = Path(__file__).resolve().parents[1]
PRIVATE = LAB / "private"
SCRIPTS = Path(__file__).resolve().parent
CANDIDATES = ["php", "go", "hono", "elysia", "rust"]


def database(prefix: str, candidate: str, step: int) -> str:
    # Validator Bun hanya menerima erp_compare_ts_<alnum>.
    if candidate in {"hono", "elysia"}:
        return f"erp_compare_ts_{prefix}{step}{candidate}"
    return f"erp_compare_{prefix}{step}_{candidate}"


def run(script: str, *args: str) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, "-B", str(SCRIPTS / script), *args], capture_output=True, text=True)


# Run yang gagal latensi di tengah I/O disk dari proses lain (job backup di dev1) bukan bukti batas stack.
HOST_RETRIES = 2


def measure(prefix: str, candidate: str, index: int, vus: int, run_id: str, args: argparse.Namespace) -> dict:
    """Satu run pada clone baru; seed dan sesi diambil dari folder --prepared bila ada."""
    seed, baseline, extra = args.seed, args.baseline, []
    if args.prepared:
        seed, baseline = args.prepared / f"{candidate}.dump", args.prepared / f"{candidate}-baseline.json"
        extra = ["--sessions", str(args.prepared / f"{candidate}-sessions.json")]
    db = database(prefix, candidate, index)
    cloned = run("lab.py", "clone", "--database", db, "--seed", str(seed))
    result = run("load.py", "--candidate", candidate, "--database", db, "--vus", str(vus), "--run-id", run_id,
                 "--fixture", str(args.fixture), "--baseline", str(baseline), *extra) if cloned.returncode == 0 else cloned
    report_path = PRIVATE / "load" / run_id / "report.json"
    report = json.loads(report_path.read_text(encoding="utf-8")) if report_path.exists() else {"verdict": "ERROR"}
    verdict = "INCONCLUSIVE_LOADGEN" if report.get("loadgen_saturated") else report["verdict"]
    return {"run_id": run_id, "verdict": verdict, "report": report,
            "error": None if report_path.exists() else (result.stderr or result.stdout)[-800:]}


def measure_retrying(prefix: str, candidate: str, index: int, vus: int, run_id: str, args: argparse.Namespace) -> list[dict]:
    """Ulangi run yang terganggu I/O host dengan clone baru; percobaan terganggu tetap tercatat."""
    attempts = []
    for attempt in range(HOST_RETRIES + 1):
        suffix = f"r{attempt}" if attempt else ""
        attempts.append(measure(prefix + suffix, candidate, index, vus, run_id + (f"-{suffix}" if suffix else ""), args))
        if attempts[-1]["verdict"] != "INCONCLUSIVE_HOST_IO":
            break
    return attempts


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--name", required=True, help="Awalan run, huruf kecil dan angka.")
    parser.add_argument("--seed", type=Path, default=PRIVATE / "seed-128.dump")
    parser.add_argument("--fixture", type=Path, default=PRIVATE / "fixture-128.json")
    parser.add_argument("--baseline", type=Path, default=PRIVATE / "seed-128-baseline.json")
    parser.add_argument("--candidates", nargs="+", default=CANDIDATES, choices=CANDIDATES)
    parser.add_argument("--steps", nargs="+", type=int)
    parser.add_argument("--prepared", type=Path, help="Folder scripts/sessions.py: <stack>.dump, -sessions.json, -baseline.json per stack.")
    args = parser.parse_args()
    profile = json.loads((LAB / "profile.json").read_text(encoding="utf-8"))
    steps = args.steps or profile["load"]["capacity_steps"]
    state = {candidate: {"last_pass": None, "first_fail": None, "stopped": None, "runs": []} for candidate in args.candidates}
    summary_path = PRIVATE / "load" / f"{args.name}-ramp.json"
    for index, vus in enumerate(steps):
        order = args.candidates[index % len(args.candidates):] + args.candidates[: index % len(args.candidates)]
        for candidate in order:
            if state[candidate]["first_fail"] is not None or state[candidate]["stopped"] is not None:
                continue
            attempts = measure_retrying(args.name, candidate, index, vus, f"{args.name}-{candidate}-{vus}", args)
            final, report = attempts[-1], attempts[-1]["report"]
            verdict = final["verdict"]
            entry = {"vus": vus, "run_id": final["run_id"], "verdict": verdict, "read_p95": report.get("read", {}).get("p95"),
                     "write_p95": report.get("write", {}).get("p95"), "rps": report.get("plateau_successful_rps"),
                     "host_io_retries": [attempt["run_id"] for attempt in attempts[:-1]], "error": final["error"]}
            state[candidate]["runs"].append(entry)
            # Hanya FAIL yang menjadi batas; run rusak atau tidak meyakinkan bukan bukti kapasitas.
            if verdict.startswith("PASS"):
                state[candidate]["last_pass"] = vus
            elif verdict == "FAIL":
                state[candidate]["first_fail"] = vus
            else:
                state[candidate]["stopped"] = {"vus": vus, "reason": verdict}
            summary_path.write_text(json.dumps({"name": args.name, "steps": steps, "candidates": state}, indent=2) + "\n", encoding="utf-8")
            print(json.dumps({"candidate": candidate, **entry}), flush=True)
            if verdict == "ERROR":
                print("RUN-ERROR: ramp dihentikan karena runner gagal; perbaiki sebelum melanjutkan.", flush=True)
                print("ALL-DONE", flush=True)
                raise SystemExit(1)
        if all(entry["first_fail"] is not None or entry["stopped"] is not None for entry in state.values()):
            break
    print("ALL-DONE", flush=True)
