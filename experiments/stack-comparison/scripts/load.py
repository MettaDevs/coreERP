"""Satu run beban pengguna untuk satu stack: SUT, sampler resource, k6, lalu oracle rekonsiliasi."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import subprocess
import sys
import threading
import time

import lab
import native
from sql_observer import sql, validate_database

LAB = Path(__file__).resolve().parents[1]
PRIVATE = LAB / "private"
SCRIPTS = Path(__file__).resolve().parent
K6_IMAGE = "grafana/k6:0.55.0"
LOADGEN = "coreerp-loadgen"
# Satu koneksi database per worker Octane; sama dengan batas 8 koneksi kandidat native di native.py.
PHP_WORKERS = 8


def secret(name: str) -> str:
    for line in (PRIVATE / "runtime.env").read_text(encoding="utf-8").splitlines():
        key, _, value = line.partition("=")
        if key == name:
            return value.strip()
    raise KeyError(name)


def stamped(command: list[str], target: Path, stop: threading.Event) -> threading.Thread:
    """Tulis setiap baris keluaran sampler dengan waktu UTC agar bisa diselaraskan dengan fase k6."""
    def run() -> None:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
        with target.open("w", encoding="utf-8") as handle:
            while not stop.is_set():
                line = process.stdout.readline()
                if not line:
                    break
                handle.write(datetime.now(timezone.utc).isoformat() + "\t" + line)
                handle.flush()
        process.terminate()
    thread = threading.Thread(target=run, daemon=True)
    thread.start()
    return thread


def start(candidate: str, database: str) -> dict:
    if candidate == "php":
        os.environ["COMPARE_DATABASE"] = database
        os.environ["PHP_WORKERS"] = str(PHP_WORKERS)
        lab.compose("up", "-d", "php")
        lab.wait_php()
        return {"candidate": "php", "container": "coreerp-stack-comparison-php-1", "internal": "http://php:8000",
                "database": database, "settings": {"octane_workers": PHP_WORKERS, "max_requests": 500, "cpus": 2, "memory": "1g"}}
    started = native.up(candidate, database, PRIVATE / "runtime.env")
    return {**started, "container": "coreerp-native-" + candidate, "internal": f"http://coreerp-native-{candidate}:8000"}


def stop(candidate: str) -> None:
    if candidate == "php":
        lab.compose("stop", "php")
    else:
        native.down(candidate)


def ledger(log: Path) -> list[dict]:
    """Acknowledgement terakhir per receipt dari log k6; urutan baris mengikuti waktu VU."""
    latest: dict[str, dict] = {}
    for line in log.read_text(encoding="utf-8").splitlines():
        try:
            message = json.loads(line).get("msg", "")
        except json.JSONDecodeError:
            continue
        if message.startswith("ACK "):
            entry = json.loads(message[4:])
            latest[entry["receipt_id"]] = entry
    return list(latest.values())


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--candidate", required=True, choices=["php", *sorted(native.CANDIDATES)])
    parser.add_argument("--database", required=True)
    parser.add_argument("--vus", required=True, type=int)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--warmup", type=int, default=30)
    parser.add_argument("--plateau", type=int, default=90)
    parser.add_argument("--drain", type=int, default=300)
    parser.add_argument("--loadgen-cpus", default="2")
    parser.add_argument("--fixture", type=Path, default=PRIVATE / "fixture.json")
    parser.add_argument("--baseline", type=Path, default=PRIVATE / "seed-baseline.json")
    args = parser.parse_args()
    validate_database(args.database)
    out = PRIVATE / "load" / args.run_id
    if out.exists():
        raise SystemExit("Folder run sudah ada; run beban tidak menimpa evidence lama.")
    out.mkdir(parents=True)
    manifest = {"run_id": args.run_id, "candidate": args.candidate, "vus": args.vus, "phases": {"warmup": args.warmup, "plateau": args.plateau, "drain": args.drain},
                "fixture": str(args.fixture), "baseline": str(args.baseline),
                "think_time": "Uniform(1,9) s sesudah setiap aksi", "initial_offset": "Uniform(0,10) s", "k6_image": K6_IMAGE,
                "loadgen": {"cpus": args.loadgen_cpus, "memory": "2g", "network": native.NETWORK}, "host": os.uname().nodename if hasattr(os, "uname") else ""}
    sut = start(args.candidate, args.database)
    manifest["sut"] = sut
    limits = json.loads(subprocess.run(["docker", "inspect", "coreerp-stack-comparison-database-1", "--format", "{{json .HostConfig}}"],
                                       capture_output=True, text=True, check=True).stdout)
    settings = sql("SELECT json_object_agg(name, setting || coalesce(unit, '')) FROM pg_settings WHERE name IN "
                   "('shared_buffers','effective_cache_size','max_connections','synchronous_commit','work_mem');")
    manifest["database"] = {"cpus": limits["NanoCpus"] / 1e9, "memory_bytes": limits["Memory"], "settings": json.loads(settings)}
    stop_sampling = threading.Event()
    samplers = [stamped(["docker", "stats", "--format", "{{json .}}", sut["container"], "coreerp-stack-comparison-database-1", LOADGEN], out / "docker-stats.tsv", stop_sampling)]
    if sys.platform != "win32":
        samplers.append(stamped(["vmstat", "-n", "2"], out / "vmstat.tsv", stop_sampling))
    environment = {**os.environ, "COMPARE_PASSWORD": secret("COMPARE_PASSWORD")}
    command = ["docker", "run", "--rm", "--name", LOADGEN, "--network", native.NETWORK, "--cpus", args.loadgen_cpus, "--memory", "2g",
               "--user", f"{os.getuid()}:{os.getgid()}" if hasattr(os, "getuid") else "0:0", "--ulimit", "nofile=65536:65536",
               "-e", "BASE=" + sut["internal"], "-e", "RUN_ID=" + args.run_id, "-e", f"VUS={args.vus}", "-e", "COMPARE_PASSWORD",
               "-e", f"WARMUP_SECONDS={args.warmup}", "-e", f"PLATEAU_SECONDS={args.plateau}", "-e", f"DRAIN_SECONDS={args.drain}",
               "-v", f"{LAB / 'load'}:/scripts:ro", "-v", f"{args.fixture.resolve()}:/fixture/fixture.json:ro", "-v", f"{out}:/out",
               K6_IMAGE, "run", "--quiet", "--log-format", "json", "--log-output", "file=/out/k6.log", "--out", "csv=/out/points.csv.gz", "/scripts/user-flow.js"]
    manifest["started_at"] = datetime.now(timezone.utc).isoformat()
    try:
        k6 = subprocess.run(command, env=environment, capture_output=True, text=True)
    finally:
        manifest["finished_at"] = datetime.now(timezone.utc).isoformat()
        logs = subprocess.run(["docker", "logs", "--tail", "300", sut["container"]], capture_output=True, text=True)
        (out / "sut.log").write_text(logs.stdout + logs.stderr, encoding="utf-8")
        stop(args.candidate)
        stop_sampling.set()
        time.sleep(3)
    manifest["k6_exit"] = k6.returncode
    (out / "k6.stderr").write_text(k6.stdout + k6.stderr, encoding="utf-8")
    acknowledgements = ledger(out / "k6.log") if (out / "k6.log").exists() else []
    (out / "acknowledgements.json").write_text(json.dumps({"acknowledgements": acknowledgements}, indent=1) + "\n", encoding="utf-8")
    oracle = subprocess.run([sys.executable, "-B", str(SCRIPTS / "sql_oracle.py"), "check", "--fixture", str(args.fixture), "--database", args.database,
                             "--baseline", str(args.baseline), "--acknowledgements", str(out / "acknowledgements.json"), "--output", str(out / "oracle.json")],
                            capture_output=True, text=True)
    manifest["oracle_exit"] = oracle.returncode
    manifest["acknowledged_receipts"] = len(acknowledgements)
    (out / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    report = subprocess.run([sys.executable, "-B", str(SCRIPTS / "load_report.py"), "--run", str(out)], capture_output=True, text=True)
    print(report.stdout.strip() or report.stderr.strip()[-1500:])
