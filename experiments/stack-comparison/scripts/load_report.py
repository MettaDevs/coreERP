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
    start = datetime.fromisoformat(manifest["started_at"]).timestamp() + manifest["phases"].get("login", 0) + manifest["phases"]["warmup"]
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
        window = [row for row in rows if start <= row["t"] <= finish]
        if rates:
            periods = window[-1].get("nr_periods", 0) - window[0].get("nr_periods", 0) if len(window) > 1 else 0
            throttled = window[-1].get("nr_throttled", 0) - window[0].get("nr_throttled", 0) if len(window) > 1 else 0
            result[name] = {"samples": len(rates), "mean": round(statistics.fmean(rates), 1), "max": round(max(rates), 1),
                            "memory_max_mb": round(max(memory) / 2**20, 1) if memory else None,
                            "throttled_period_share": round(throttled / periods, 3) if periods else None}
    return result


def connection_states(run: Path, manifest: dict) -> dict:
    path = run / "connections.jsonl"
    if not path.exists():
        return {}
    start = datetime.fromisoformat(manifest["started_at"]).timestamp() + manifest["phases"].get("login", 0) + manifest["phases"]["warmup"]
    finish = start + manifest["phases"]["plateau"]
    rows = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]
    rows = [row for row in rows if start <= row["t"] <= finish]
    if not rows:
        return {}
    states = sorted({state for row in rows for state in row["states"]})
    return {"samples": len(rows), "max_by_state": {state: max(row["states"].get(state, 0) for row in rows) for state in states},
            "max_lock_waiting": max(row["waiting"] for row in rows), "max_idle_in_transaction_s": round(max(row["oldest_idle_xact_s"] for row in rows), 3)}


# Database run hampir tidak membaca disk (blks_read kecil); baca disk host di atas batas ini berasal dari proses
# lain di server (job backup dev1, 4 November) dan mengganggu bottleneck fsync yang sedang diukur.
HOST_READ_LIMIT_MB_S = 5


def host_io(run: Path, manifest: dict) -> dict:
    """Baca/tulis disk dan iowait host selama plateau dari vmstat (kolom bi/bo dalam KiB per detik)."""
    path = run / "vmstat.tsv"
    if not path.exists():
        return {}
    start = datetime.fromisoformat(manifest["started_at"]).timestamp() + manifest["phases"].get("login", 0) + manifest["phases"]["warmup"]
    finish = start + manifest["phases"]["plateau"]
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        stamp, _, values = line.partition("	")
        fields = values.split()
        if len(fields) < 17 or not fields[0].isdigit():
            continue
        if start <= datetime.fromisoformat(stamp).timestamp() <= finish:
            rows.append([int(value) for value in fields])
    if not rows:
        return {}
    read = [row[8] / 1024 for row in rows]
    write = [row[9] / 1024 for row in rows]
    iowait = [row[15] for row in rows]
    result = {"samples": len(rows), "read_mb_s_mean": round(statistics.fmean(read), 1), "read_mb_s_max": round(max(read), 1),
              "write_mb_s_mean": round(statistics.fmean(write), 1), "iowait_mean": round(statistics.fmean(iowait), 1), "iowait_max": max(iowait)}
    result["noisy"] = result["read_mb_s_mean"] > HOST_READ_LIMIT_MB_S
    return result


def counter_delta(manifest: dict) -> dict:
    before, after = manifest.get("database_counters_before"), manifest.get("database_counters_after")
    if not before or not after:
        return {}
    return {group: {key: (round(after[group][key] - before[group][key], 3) if isinstance(after[group][key], (int, float)) else None)
                    for key in after[group]} for group in after if after[group] and before.get(group)}


def plateau_rates(arrivals: dict[tuple, int]) -> dict:
    """Laju request per 10 detik di plateau dan koefisien variasinya. Plateau yang sefase (gelombang) terlihat
    dari CV laju tulis yang besar; dengan awal siklus tersebar laju seharusnya hampir rata."""
    if not arrivals:
        return {}
    start = min(second for _, second in arrivals)
    result = {}
    for kind in ("read", "write"):
        buckets: dict[int, int] = defaultdict(int)
        for (k, second), count in arrivals.items():
            if k == kind:
                buckets[(second - start) // 10] += count
        series = [round(buckets[index] / 10, 1) for index in range(max(buckets, default=-1) + 1)]
        full = series[:-1] if len(series) > 1 else series
        mean = sum(full) / len(full) if full else 0
        spread = (sum((value - mean) ** 2 for value in full) / len(full)) ** 0.5 if full else 0
        result[kind] = {"per_second_by_10s": series, "cv": round(spread / mean, 3) if mean else None}
    return result


def report(run: Path) -> dict:
    profile = json.loads((LAB / "profile.json").read_text(encoding="utf-8"))
    manifest = json.loads((run / "manifest.json").read_text(encoding="utf-8"))
    plateau_seconds = manifest["phases"]["plateau"]
    latency: dict[tuple, list[float]] = defaultdict(list)
    failed: dict[tuple, int] = defaultdict(int)
    statuses: dict[str, int] = defaultdict(int)
    session_statuses: dict[str, int] = defaultdict(int)
    cycles: dict[str, list[float]] = defaultdict(list)
    arrivals: dict[tuple, int] = defaultdict(int)
    for row in points(run):
        metric = row["metric_name"]
        if metric == "http_req_duration":
            key = (row.get("phase"), row.get("kind"), row.get("op"))
            if key[0] == "plateau" and key[1] in {"read", "write"}:
                arrivals[(key[1], int(row["timestamp"]))] += 1
            if row["expected_response"] == "true":
                latency[key].append(float(row["metric_value"]))
            else:
                failed[key] += 1
                statuses[row["status"]] += 1
                if row.get("kind") in {"read", "write"}:
                    session_statuses[row["status"]] += 1
        elif metric in {"cycle_total", "cycle_busy"} and row.get("phase") == "plateau":
            cycles[metric].append(float(row["metric_value"]))
    per_op = {f"{kind}:{op}": {**summary(values), "failed": failed[(phase, kind, op)]}
              for (phase, kind, op), values in sorted(latency.items()) if phase == "plateau"}
    by_kind = {kind: summary([value for (phase, k, _), values in latency.items() if phase == "plateau" and k == kind for value in values]) for kind in ["read", "write"]}
    login = summary([value for (_, kind, _), values in latency.items() if kind == "login" for value in values])
    login_in_plateau = sum(len(values) + failed[(phase, kind, op)] for (phase, kind, op), values in latency.items() if kind == "login" and phase == "plateau")
    slo = profile["slo"]
    minimum = slo["percentile_minimum_samples_per_operation"]
    plateau_failed = sum(count for (phase, _, _), count in failed.items() if phase == "plateau")
    # Kapasitas sesi steady dinilai dari request baca/tulis; login (bcrypt) adalah metrik cold-login tersendiri.
    session_failed = sum(count for (_, kind, _), count in failed.items() if kind in {"read", "write"})
    login_failed = sum(count for (_, kind, _), count in failed.items() if kind == "login")
    server_errors = sum(count for status, count in session_statuses.items() if status.startswith("5"))
    oracle = json.loads((run / "oracle.json").read_text(encoding="utf-8")) if (run / "oracle.json").exists() else {"failures": ["missing"]}
    checks = {
        "read_p95": by_kind["read"]["p95"] is not None and by_kind["read"]["p95"] < slo["read_ms"]["p95"],
        "read_p99": by_kind["read"]["p99"] is not None and by_kind["read"]["p99"] < slo["read_ms"]["p99"],
        "write_p95": by_kind["write"]["p95"] is not None and by_kind["write"]["p95"] < slo["write_ms"]["p95"],
        "write_p99": by_kind["write"]["p99"] is not None and by_kind["write"]["p99"] < slo["write_ms"]["p99"],
        "no_unexpected_responses": session_failed == 0,
        "no_server_errors": server_errors == 0,
        "oracle_clean": not oracle["failures"],
        "k6_exit_zero": manifest.get("k6_exit") == 0,
    }
    enough = all(entry["count"] >= minimum for entry in per_op.values()) and bool(per_op)
    host = host_io(run, manifest)
    latency_ok = all(checks[key] for key in ("read_p95", "read_p99", "write_p95", "write_p99"))
    correct = all(value for key, value in checks.items() if key not in {"read_p95", "read_p99", "write_p95", "write_p99"})
    # Gangguan disk dari proses lain hanya dapat membuat latensi lebih buruk: run yang lulus tetap sah, run yang gagal
    # latensi di tengah gangguan tidak membuktikan batas stack. Pelanggaran kebenaran tetap FAIL.
    if not correct:
        verdict = "FAIL"
    elif not latency_ok:
        verdict = "INCONCLUSIVE_HOST_IO" if host.get("noisy") else "FAIL"
    else:
        verdict = "INCONCLUSIVE_LOGIN_OVERLAP" if login_in_plateau else "PASS" if enough else "PASS_LOW_SAMPLES"
    successful = sum(len(values) for (phase, _, _), values in latency.items() if phase == "plateau")
    cpu = cpu_series(run, manifest)
    loadgen = cpu.get("coreerp-loadgen", {})
    stationarity = plateau_rates(arrivals)
    return {
        "run_id": manifest["run_id"], "candidate": manifest["candidate"], "vus": manifest["vus"], "verdict": verdict,
        "checks": checks, "samples_sufficient": enough, "minimum_samples_per_operation": minimum,
        "plateau_successful_rps": round(successful / plateau_seconds, 2), "plateau_requests": successful,
        "read": by_kind["read"], "write": by_kind["write"], "login_all_phases": login, "login_failures": login_failed, "login_requests_in_plateau": login_in_plateau, "per_operation": per_op,
        "failed_by_status": dict(statuses), "acknowledged_receipts": manifest.get("acknowledged_receipts"),
        "completed_cycles": oracle.get("acknowledged_completed"),
        "cycle_ms_with_think": summary(cycles["cycle_total"]), "cycle_ms_without_think": summary(cycles["cycle_busy"]),
        "sessions_prepared": manifest.get("sessions") is not None, "host_io": host, "plateau_rates": stationarity, "cpu_percent": cpu, "connections": connection_states(run, manifest), "database_delta": counter_delta(manifest),
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
