"""Ringkas satu run beban: persentil plateau per aksi, SLO profil, error, siklus, resource, dan oracle."""

from __future__ import annotations

import argparse
from collections import defaultdict
import csv
from datetime import datetime
import gzip
import json
import math
from pathlib import Path
import statistics
from urllib.parse import parse_qsl

LAB = Path(__file__).resolve().parents[1]


def percentile(values: list[float], fraction: float) -> float | None:
    """Nearest-rank: nilai ke-ceil(p*n) dari data terurut."""
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(fraction * len(ordered)) - 1)], 2)


def summary(values: list[float]) -> dict:
    return {"count": len(values), "p50": percentile(values, 0.5), "p95": percentile(values, 0.95),
            "p99": percentile(values, 0.99), "max": round(max(values), 2) if values else None}


def points(run: Path):
    with gzip.open(run / "points.csv.gz", "rt", encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            row.update(dict(parse_qsl(row.get("extra_tags") or "")))
            yield row


def cpu_series(run: Path, manifest: dict) -> dict:
    """CPU per container di jendela plateau dari cgroup; 100% sama dengan satu core. Jendela dihitung dari
    waktu start k6, jadi selisih start container k6 (beberapa detik) ikut menjadi ketidakpastian."""
    path = run / "cgroups.jsonl"
    if not path.exists():
        return {}
    start = datetime.fromisoformat(manifest["started_at"]).timestamp() + manifest["phases"]["warmup"]
    finish = start + manifest["phases"]["plateau"]
    samples: dict[str, list[dict]] = defaultdict(list)
    for line in path.read_text(encoding="utf-8").splitlines():
        sample = json.loads(line)
        samples[sample["name"]].append(sample)
    result = {}
    for name, rows in samples.items():
        rates = [(b["cpu_usec"] - a["cpu_usec"]) / (b["t"] - a["t"]) / 1e4 for a, b in zip(rows, rows[1:])
                 if start <= a["t"] and b["t"] <= finish and b["t"] > a["t"]]
        memory = [row["memory"] for row in rows if start <= row["t"] <= finish]
        if rates:
            result[name] = {"samples": len(rates), "mean": round(statistics.fmean(rates), 1), "max": round(max(rates), 1),
                            "memory_max_mb": round(max(memory) / 2**20, 1) if memory else None}
    return result


def report(run: Path) -> dict:
    profile = json.loads((LAB / "profile.json").read_text(encoding="utf-8"))
    manifest = json.loads((run / "manifest.json").read_text(encoding="utf-8"))
    plateau_seconds = manifest["phases"]["plateau"]
    latency: dict[tuple, list[float]] = defaultdict(list)
    failed: dict[tuple, int] = defaultdict(int)
    statuses: dict[str, int] = defaultdict(int)
    cycles: dict[str, list[float]] = defaultdict(list)
    for row in points(run):
        metric = row["metric_name"]
        if metric == "http_req_duration":
            key = (row.get("phase"), row.get("kind"), row.get("op"))
            if row["expected_response"] == "true":
                latency[key].append(float(row["metric_value"]))
            else:
                failed[key] += 1
                statuses[row["status"]] += 1
        elif metric in {"cycle_total", "cycle_busy"} and row.get("phase") == "plateau":
            cycles[metric].append(float(row["metric_value"]))
    per_op = {f"{kind}:{op}": {**summary(values), "failed": failed[(phase, kind, op)]}
              for (phase, kind, op), values in sorted(latency.items()) if phase == "plateau"}
    by_kind = {kind: summary([value for (phase, k, _), values in latency.items() if phase == "plateau" and k == kind for value in values]) for kind in ["read", "write"]}
    login = summary([value for (_, kind, _), values in latency.items() if kind == "login" for value in values])
    slo = profile["slo"]
    minimum = slo["percentile_minimum_samples_per_operation"]
    plateau_failed = sum(count for (phase, _, _), count in failed.items() if phase == "plateau")
    server_errors = sum(count for status, count in statuses.items() if status.startswith("5"))
    oracle = json.loads((run / "oracle.json").read_text(encoding="utf-8")) if (run / "oracle.json").exists() else {"failures": ["missing"]}
    checks = {
        "read_p95": by_kind["read"]["p95"] is not None and by_kind["read"]["p95"] < slo["read_ms"]["p95"],
        "read_p99": by_kind["read"]["p99"] is not None and by_kind["read"]["p99"] < slo["read_ms"]["p99"],
        "write_p95": by_kind["write"]["p95"] is not None and by_kind["write"]["p95"] < slo["write_ms"]["p95"],
        "write_p99": by_kind["write"]["p99"] is not None and by_kind["write"]["p99"] < slo["write_ms"]["p99"],
        "no_unexpected_responses": plateau_failed == 0 and sum(failed.values()) == 0,
        "no_server_errors": server_errors == 0,
        "oracle_clean": not oracle["failures"],
        "k6_exit_zero": manifest.get("k6_exit") == 0,
    }
    enough = all(entry["count"] >= minimum for entry in per_op.values()) and bool(per_op)
    verdict = "FAIL" if not all(checks.values()) else "PASS" if enough else "PASS_LOW_SAMPLES"
    successful = sum(len(values) for (phase, _, _), values in latency.items() if phase == "plateau")
    cpu = cpu_series(run, manifest)
    loadgen = cpu.get("coreerp-loadgen", {})
    return {
        "run_id": manifest["run_id"], "candidate": manifest["candidate"], "vus": manifest["vus"], "verdict": verdict,
        "checks": checks, "samples_sufficient": enough, "minimum_samples_per_operation": minimum,
        "plateau_successful_rps": round(successful / plateau_seconds, 2), "plateau_requests": successful,
        "read": by_kind["read"], "write": by_kind["write"], "login_all_phases": login, "per_operation": per_op,
        "failed_by_status": dict(statuses), "acknowledged_receipts": manifest.get("acknowledged_receipts"),
        "completed_cycles": oracle.get("acknowledged_completed"),
        "cycle_ms_with_think": summary(cycles["cycle_total"]), "cycle_ms_without_think": summary(cycles["cycle_busy"]),
        "cpu_percent": cpu,
        "loadgen_saturated": bool(loadgen) and loadgen["mean"] > 0.8 * 100 * float(manifest["loadgen"]["cpus"]),
        "oracle_failures": oracle["failures"],
        "limitations": "Closed model with think time; VU count is not concurrent request count. Percentiles use nearest rank. PASS_LOW_SAMPLES means some operation had fewer samples than the profile minimum, so p99 is not claimable.",
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", required=True, type=Path)
    args = parser.parse_args()
    result = report(args.run)
    (args.run / "report.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: result[key] for key in ["run_id", "candidate", "vus", "verdict", "plateau_successful_rps", "acknowledged_receipts", "completed_cycles", "loadgen_saturated"]}
                     | {"read_p95": result["read"]["p95"], "write_p95": result["write"]["p95"], "checks": result["checks"]}))
