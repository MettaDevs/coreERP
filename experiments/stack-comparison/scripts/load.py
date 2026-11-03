"""Satu run beban pengguna untuk satu stack: SUT, sampler resource, k6, lalu oracle rekonsiliasi."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import math
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


def cgroups(names: list[str], target: Path, stop: threading.Event) -> threading.Thread:
    """CPU kumulatif dan memori per container dari cgroup v2 setiap dua detik; container yang belum ada dicari ulang."""
    def run() -> None:
        found: dict[str, Path] = {}
        with target.open("w", encoding="utf-8") as handle:
            while not stop.is_set():
                for name in names:
                    if name not in found:
                        identifier = subprocess.run(["docker", "ps", "-q", "--no-trunc", "--filter", f"name=^{name}$"], capture_output=True, text=True).stdout.strip()
                        if identifier:
                            found[name] = Path(f"/sys/fs/cgroup/system.slice/docker-{identifier}.scope")
                for name, base in found.items():
                    try:
                        stat = dict(line.split() for line in (base / "cpu.stat").read_text().splitlines())
                        memory = int((base / "memory.current").read_text())
                    except (OSError, ValueError):
                        continue
                    # nr_throttled dan throttled_usec membuktikan container mentok di kuota CPU-nya.
                    handle.write(json.dumps({"t": time.time(), "name": name, "cpu_usec": int(stat["usage_usec"]), "memory": memory,
                                             "nr_periods": int(stat.get("nr_periods", 0)), "nr_throttled": int(stat.get("nr_throttled", 0)),
                                             "throttled_usec": int(stat.get("throttled_usec", 0))}) + "\n")
                handle.flush()
                stop.wait(2)
    thread = threading.Thread(target=run, daemon=True)
    thread.start()
    return thread


def connections(database: str, target: Path, stop: threading.Event) -> threading.Thread:
    """Koneksi database run per state setiap dua detik, termasuk transaksi idle yang menahan snapshot."""
    statement = ("SELECT json_build_object('states', coalesce((SELECT json_object_agg(state, n) FROM (SELECT coalesce(state, 'none') AS state, count(*) AS n "
                 f"FROM pg_stat_activity WHERE datname='{database}' GROUP BY 1) s), '{{}}'::json), 'waiting', (SELECT count(*) FROM pg_stat_activity "
                 f"WHERE datname='{database}' AND wait_event_type IN ('Lock','LWLock')), 'oldest_idle_xact_s', (SELECT coalesce(max(extract(epoch FROM now()-xact_start)), 0) "
                 f"FROM pg_stat_activity WHERE datname='{database}' AND state LIKE 'idle in transaction%'));")
    def run() -> None:
        with target.open("w", encoding="utf-8") as handle:
            while not stop.is_set():
                try:
                    handle.write(json.dumps({"t": time.time(), **json.loads(sql(statement))}) + "\n")
                    handle.flush()
                except (subprocess.CalledProcessError, json.JSONDecodeError):
                    pass
                stop.wait(2)
    thread = threading.Thread(target=run, daemon=True)
    thread.start()
    return thread


def database_counters(database: str) -> dict:
    """Checkpoint, WAL, dan statistik database sebelum/sesudah run; selisihnya dihitung di laporan."""
    return json.loads(sql(
        "SELECT json_build_object("
        "'checkpointer', (SELECT row_to_json(c) FROM (SELECT num_timed, num_requested, write_time, sync_time, buffers_written FROM pg_stat_checkpointer) c), "
        "'wal', (SELECT row_to_json(w) FROM (SELECT wal_records, wal_fpi, wal_bytes::bigint, wal_buffers_full FROM pg_stat_wal) w), "
        f"'database', (SELECT row_to_json(d) FROM (SELECT xact_commit, xact_rollback, blks_read, blks_hit, deadlocks, temp_bytes, conflicts FROM pg_stat_database WHERE datname='{database}') d));"))


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
    parser.add_argument("--login", type=int, help="Jendela login detik; bawaan max(60, 0.4 x VU) karena bcrypt dua kali per login.")
    parser.add_argument("--warmup", type=int, default=30)
    parser.add_argument("--plateau", type=int, default=90)
    parser.add_argument("--drain", type=int, default=300)
    parser.add_argument("--loadgen-cpus", default="2")
    parser.add_argument("--fixture", type=Path, default=PRIVATE / "fixture.json")
    parser.add_argument("--baseline", type=Path, default=PRIVATE / "seed-baseline.json")
    args = parser.parse_args()
    validate_database(args.database)
    if sql(f"SELECT count(*) FROM pg_database WHERE datname='{args.database}';") != "1":
        raise SystemExit("Database run belum ada; buat clone dulu agar run tidak berjalan terhadap database kosong.")
    login_seconds = args.login or max(60, math.ceil(0.4 * args.vus))
    out = PRIVATE / "load" / args.run_id
    if out.exists():
        raise SystemExit("Folder run sudah ada; run beban tidak menimpa evidence lama.")
    out.mkdir(parents=True)
    manifest = {"run_id": args.run_id, "candidate": args.candidate, "vus": args.vus, "phases": {"login": login_seconds, "warmup": args.warmup, "plateau": args.plateau, "drain": args.drain},
                "fixture": str(args.fixture), "baseline": str(args.baseline),
                "think_time": "Uniform(1,9) s sesudah setiap aksi", "login_offset": "Uniform(0, 0.5 x jendela login) s", "initial_offset": "Uniform(0,10) s sesudah jendela login", "k6_image": K6_IMAGE,
                "loadgen": {"cpus": args.loadgen_cpus, "memory": "2g", "network": native.NETWORK}, "host": os.uname().nodename if hasattr(os, "uname") else ""}
    sut = start(args.candidate, args.database)
    manifest["sut"] = sut
    limits = json.loads(subprocess.run(["docker", "inspect", "coreerp-stack-comparison-database-1", "--format", "{{json .HostConfig}}"],
                                       capture_output=True, text=True, check=True).stdout)
    settings = sql("SELECT json_object_agg(name, setting || coalesce(unit, '')) FROM pg_settings WHERE name IN "
                   "('shared_buffers','effective_cache_size','max_connections','synchronous_commit','work_mem');")
    manifest["database"] = {"cpus": limits["NanoCpus"] / 1e9, "memory_bytes": limits["Memory"], "settings": json.loads(settings)}
    stop_sampling = threading.Event()
    samplers = [cgroups([sut["container"], "coreerp-stack-comparison-database-1", LOADGEN], out / "cgroups.jsonl", stop_sampling),
                connections(args.database, out / "connections.jsonl", stop_sampling)]
    manifest["database_counters_before"] = database_counters(args.database)
    if sys.platform != "win32":
        samplers.append(stamped(["vmstat", "-n", "2"], out / "vmstat.tsv", stop_sampling))
    environment = {**os.environ, "COMPARE_PASSWORD": secret("COMPARE_PASSWORD")}
    command = ["docker", "run", "--rm", "--name", LOADGEN, "--network", native.NETWORK, "--cpus", args.loadgen_cpus, "--memory", "2g",
               "--user", f"{os.getuid()}:{os.getgid()}" if hasattr(os, "getuid") else "0:0", "--ulimit", "nofile=65536:65536",
               "-e", "BASE=" + sut["internal"], "-e", "RUN_ID=" + args.run_id, "-e", f"VUS={args.vus}", "-e", "COMPARE_PASSWORD",
               "-e", f"LOGIN_SECONDS={login_seconds}", "-e", f"WARMUP_SECONDS={args.warmup}", "-e", f"PLATEAU_SECONDS={args.plateau}", "-e", f"DRAIN_SECONDS={args.drain}",
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
    manifest["database_counters_after"] = database_counters(args.database)
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
