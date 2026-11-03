"""Satu golden run kandidat native: start dingin dengan PHP mati, alur HTTP, lalu keempat gate."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import sys

import native
import sql_observer

SCRIPTS = Path(__file__).resolve().parent
PRIVATE = SCRIPTS.parent / "private"


def tool(name: str, *args: str | Path) -> dict:
    result = subprocess.run([sys.executable, "-B", str(SCRIPTS / name), *map(str, args)], capture_output=True, text=True)
    return {"tool": name, "exit": result.returncode, "last_line": (result.stdout.strip().splitlines() or [""])[-1],
            "stderr_tail": result.stderr.strip()[-1500:]}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--candidate", required=True, choices=sorted(native.CANDIDATES))
    parser.add_argument("--database", required=True)
    parser.add_argument("--expected", required=True, type=Path, help="Flow PHP pada run-id yang sama.")
    parser.add_argument("--expected-database", required=True)
    parser.add_argument("--expected-traces", required=True, type=Path, help="Folder trace PHP dari flow yang sama.")
    parser.add_argument("--prefix", required=True, help="Awalan berkas evidence di private/, misalnya g2-go.")
    parser.add_argument("--run-id", required=True)
    args = parser.parse_args()
    base = PRIVATE / args.prefix
    flow = Path(str(base) + "-flow.json")
    if flow.exists():
        raise SystemExit("Evidence sudah ada; golden run tidak menimpa artifact lama.")
    # Cluster uji memuat puluhan clone; tanpa reset, pg_stat_statements.max mengusir fingerprint di tengah trace.
    sql_observer.reset()
    started = native.up(args.candidate, args.database, PRIVATE / "runtime.env")
    try:
        steps = [tool("http_baseline.py", "--base", started["base"], "--fixture", PRIVATE / "fixture.json", "--env", PRIVATE / "runtime.env",
                      "--output", flow, "--run-id", args.run_id, "--trace-database", args.database, "--trace-directory", str(base) + "-traces")]
        started["php_after_flow"] = native.php_state()
    finally:
        logs = subprocess.run(["docker", "logs", "--tail", "200", "coreerp-native-" + args.candidate], capture_output=True, text=True)
        Path(str(base) + "-container.log").write_text(logs.stdout + logs.stderr, encoding="utf-8")
        native.down(args.candidate)
    if steps[0]["exit"] == 0:
        steps += [
            tool("compare_http.py", "--expected", args.expected, "--actual", flow, "--output", str(base) + "-http.json"),
            tool("sql_oracle.py", "check", "--fixture", PRIVATE / "fixture.json", "--database", args.database,
                 "--baseline", PRIVATE / "seed-baseline.json", "--acknowledgements", flow, "--output", str(base) + "-oracle.json"),
            tool("compare_posting.py", "--expected-database", args.expected_database, "--actual-database", args.database,
                 "--expected-http", args.expected, "--actual-http", flow, "--output", str(base) + "-posting.json"),
            tool("compare_queries.py", "--expected", args.expected_traces, "--actual", str(base) + "-traces",
                 "--candidate", args.candidate, "--output", str(base) + "-queries.json"),
        ]
    summary = {"verdict": "VERIFIED" if len(steps) == 5 and all(step["exit"] == 0 for step in steps) else "NOT VERIFIED",
               "runtime": started, "steps": steps}
    Path(str(base) + "-summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"verdict": summary["verdict"], "steps": [(step["tool"], step["exit"]) for step in steps]}))
    if summary["verdict"] != "VERIFIED":
        raise SystemExit(1)
