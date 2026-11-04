"""Sampler diagnosis yang berjalan berdampingan dengan load.py: CPU dan socket tiap proses SUT, plus backend database.

`record` menunggu container kandidat muncul, lalu mencatat setiap detik sampai container itu hilang:
- CPU tiap proses di dalam container (utime+stime dari /proc host, dipetakan ke pid container lewat NSpid);
- koneksi HTTP masuk (port 8000) dan port klien koneksi database (ke 5432) milik tiap proses;
- setiap backend database run: port klien, state, wait event, umur transaksi, dan kunci yang ditunggu.

`summarize` menggabungkan keduanya per detik: CPU tiap proses dan state koneksi database milik proses itu.
Tujuannya membedakan dua dugaan untuk Bun (beban HTTP tidak terbagi rata ke dua proses, atau pool 4 milik satu
proses habis tertahan kunci) dan melihat apa yang ditunggu backend saat stall (kunci baris atau flush WAL).
"""

from __future__ import annotations

import argparse
from collections import Counter, defaultdict
import json
import os
from pathlib import Path
import subprocess
import time

from sql_observer import sql, validate_database

TICKS = os.sysconf("SC_CLK_TCK") if hasattr(os, "sysconf") else 100
# Dibaca sebagai root di dalam container: pemilik tiap inode socket, lalu tabel TCP namespace container.
SOCKETS = r"""
for d in /proc/[0-9]*; do
  p=${d#/proc/}
  for f in $d/fd/*; do
    l=$(readlink "$f" 2>/dev/null) || continue
    case "$l" in socket:*) i=${l#socket:[}; echo "fd $p ${i%]}";; esac
  done
done
cat /proc/net/tcp /proc/net/tcp6 2>/dev/null | while read -r n local remote state rest; do
  set -- $rest; echo "tcp $local $remote $state $6"
done
"""


def host_processes(container: str) -> dict[int, int]:
    """pid host -> pid container untuk setiap proses container."""
    result = subprocess.run(["docker", "top", container, "-eo", "pid"], capture_output=True, text=True)
    mapping = {}
    for line in result.stdout.split()[1:]:
        try:
            with open(f"/proc/{line}/status", encoding="utf-8") as handle:
                for row in handle:
                    if row.startswith("NSpid:"):
                        mapping[int(line)] = int(row.split()[-1])
        except (OSError, ValueError):
            pass
    return mapping


def cpu_ticks(host_pid: int) -> int | None:
    try:
        with open(f"/proc/{host_pid}/stat", encoding="utf-8") as handle:
            fields = handle.read().rsplit(")", 1)[1].split()
        return int(fields[11]) + int(fields[12])
    except (OSError, IndexError, ValueError):
        return None


def sockets(container: str) -> dict[int, dict]:
    """pid container -> jumlah koneksi HTTP masuk dan port klien database."""
    result = subprocess.run(["docker", "exec", container, "sh", "-c", SOCKETS], capture_output=True, text=True)
    owner, rows = {}, []
    for line in result.stdout.splitlines():
        parts = line.split()
        if parts[:1] == ["fd"] and len(parts) == 3:
            owner[parts[2]] = int(parts[1])
        elif parts[:1] == ["tcp"] and len(parts) == 5 and parts[3] == "01":
            rows.append(parts[1:])
    usage: dict[int, dict] = defaultdict(lambda: {"http": 0, "db_ports": []})
    for local, remote, _, inode in rows:
        pid = owner.get(inode)
        if pid is None:
            continue
        local_port, remote_port = int(local.rsplit(":", 1)[1], 16), int(remote.rsplit(":", 1)[1], 16)
        if local_port == 8000:
            usage[pid]["http"] += 1
        elif remote_port == 5432:
            usage[pid]["db_ports"].append(local_port)
    return dict(usage)


def backends(database: str) -> list:
    statement = (
        "SELECT coalesce(json_agg(json_build_object('port', a.client_port, 'state', a.state, 'wait', "
        "coalesce(a.wait_event_type || ':' || a.wait_event, ''), 'xact_ms', round(extract(epoch FROM now() - a.xact_start) * 1000), "
        "'lock', (SELECT l.locktype || ':' || coalesce(l.relation::regclass::text, '') FROM pg_locks l WHERE l.pid = a.pid AND NOT l.granted LIMIT 1), "
        "'blocked_by', cardinality(pg_blocking_pids(a.pid)))), '[]'::json) "
        f"FROM pg_stat_activity a WHERE a.datname = '{database}' AND a.pid <> pg_backend_pid();")
    return json.loads(sql(statement))


def wal_counters(database: str) -> dict:
    """Hitungan kumulatif fsync/write WAL cluster dan commit database run; selisih per detik = laju commit dan fsync."""
    return json.loads(sql(
        "SELECT json_build_object('wal_sync', w.wal_sync, 'wal_write', w.wal_write, 'xact_commit', d.xact_commit) "
        f"FROM pg_stat_wal w, pg_stat_database d WHERE d.datname = '{database}';"))


def record(container: str, database: str, output: Path, interval: float, socket_every: int) -> None:
    validate_database(database)
    deadline = time.monotonic() + 600
    while not host_processes(container):
        if time.monotonic() > deadline:
            raise SystemExit(f"Container {container} tidak muncul dalam 10 menit.")
        time.sleep(1)
    output.parent.mkdir(parents=True, exist_ok=True)
    tick = 0
    usage: dict[int, dict] = {}
    with output.open("w", encoding="utf-8") as handle:
        while True:
            processes = host_processes(container)
            if not processes:
                break
            if tick % socket_every == 0:
                usage = sockets(container)
            sample = {"t": time.time(), "cpu": {str(inner): cpu_ticks(host) for host, inner in processes.items()},
                      "sockets": {str(pid): value for pid, value in usage.items()}}
            try:
                sample["backends"] = backends(database)
                sample["wal"] = wal_counters(database)
            except (subprocess.CalledProcessError, json.JSONDecodeError):
                sample["backends"] = None
            handle.write(json.dumps(sample) + "\n")
            handle.flush()
            tick += 1
            time.sleep(interval)


def summarize(path: Path, start: float | None, end: float | None) -> None:
    samples = [json.loads(line) for line in path.open(encoding="utf-8")]
    if start is not None:
        samples = [s for s in samples if start <= s["t"] <= (end or float("inf"))]
    waits: Counter = Counter()
    locks: Counter = Counter()
    print("t      " + "  ".join("cpu%  http  act/idle/itx/lock" for _ in range(2)))
    previous = None
    port_owner: dict[int, str] = {}
    for sample in samples:
        for pid, value in sample["sockets"].items():
            for port in value["db_ports"]:
                port_owner[port] = pid
        if previous is None:
            previous = sample
            continue
        seconds = sample["t"] - previous["t"]
        columns = []
        per_process: dict[str, Counter] = defaultdict(Counter)
        # Image tanpa shell (Rust) tidak bisa dipetakan socket-nya; kandidat satu proses memiliki semua backend.
        single = next(iter(sample["cpu"])) if len(sample["cpu"]) == 1 else "?"
        for backend in sample["backends"] or []:
            owner = port_owner.get(backend["port"], single)
            state = backend["state"] or "none"
            if backend["wait"].startswith("Lock:"):
                state = "lock"
            per_process[owner][state] += 1
            if backend["state"] == "active" and backend["wait"]:
                waits[backend["wait"]] += 1
            if backend["lock"]:
                locks[backend["lock"]] += 1
        for pid, ticks in sorted(sample["cpu"].items(), key=lambda kv: int(kv[0])):
            before = previous["cpu"].get(pid)
            if ticks is None or before is None or sample["sockets"].get(pid, {}).get("http", 0) == 0 and not per_process.get(pid):
                continue
            cpu = round(100 * (ticks - before) / TICKS / seconds)
            states = per_process.get(pid, Counter())
            columns.append(f"{cpu:4d} {sample['sockets'].get(pid, {}).get('http', 0):5d}  "
                           f"{states['active']}/{states['idle']}/{states['idle in transaction']}/{states['lock']}")
        rates = ""
        if sample.get("wal") and previous.get("wal"):
            rates = "  " + " ".join(f"{key}/s={round((sample['wal'][key] - previous['wal'][key]) / seconds)}" for key in ("xact_commit", "wal_sync"))
        print(f"{sample['t'] % 1000:7.1f} " + "   ".join(columns) + rates)
        previous = sample
    print("active wait events:", dict(waits.most_common(8)))
    print("ungranted locks:", dict(locks.most_common(8)))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="action", required=True)
    rec = commands.add_parser("record")
    rec.add_argument("--container", required=True)
    rec.add_argument("--database", required=True)
    rec.add_argument("--output", type=Path, required=True)
    rec.add_argument("--interval", type=float, default=1.0)
    rec.add_argument("--socket-every", type=int, default=5)
    summary = commands.add_parser("summarize")
    summary.add_argument("--input", type=Path, required=True)
    summary.add_argument("--start", type=float)
    summary.add_argument("--end", type=float)
    args = parser.parse_args()
    if args.action == "record":
        record(args.container, args.database, args.output, args.interval, args.socket_every)
    else:
        summarize(args.input, args.start, args.end)
