"""Snapshot sesi siap pakai per stack: pengguna dianggap sudah berada di dalam sistem saat run beban dimulai.

`prepare` meng-clone seed, menyalakan stack, me-login setiap akun fixture sekali lewat stack itu sendiri
(`load/prepare-sessions.js`), mematikan stack, lalu menyimpan:
- `<dir>/<stack>.dump`: seed beserta isi tabel `sessions` (cache tetap dibuang seperti seed);
- `<dir>/<stack>-sessions.json`: cookie sesi per indeks akun, rahasia, mode 600;
- `<dir>/<stack>-baseline.json`: baseline oracle dari database yang sama dengan dump.

Sesi dibuat oleh stack yang memakainya, jadi tidak ada asumsi format payload sesi lintas stack. Login tidak
menyentuh tabel yang diperiksa oracle; baseline tetap direkam ulang agar dump dan baseline berpasangan.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import subprocess
import sys
import time

import lab
import native
from load import K6_IMAGE, LOADGEN, PRIVATE, SCRIPTS, LAB, secret, start, stop
from ramp import database

DATABASE_CONTAINER = "coreerp-stack-comparison-database-1"


def private_write(path: Path, text: str) -> None:
    path.write_text(text, encoding="utf-8")
    if os.name != "nt":
        path.chmod(0o600)


def login_all(base: str, fixture: Path, out: Path, vus: int) -> None:
    environment = {**os.environ, "COMPARE_PASSWORD": secret("COMPARE_PASSWORD")}
    command = ["docker", "run", "--rm", "--name", LOADGEN, "--network", native.NETWORK, "--cpus", "2", "--memory", "2g",
               "--user", f"{os.getuid()}:{os.getgid()}" if hasattr(os, "getuid") else "0:0",
               "-e", "BASE=" + base, "-e", f"VUS={vus}", "-e", "COMPARE_PASSWORD",
               "-v", f"{LAB / 'load'}:/scripts:ro", "-v", f"{fixture.resolve()}:/fixture/fixture.json:ro", "-v", f"{out}:/out",
               K6_IMAGE, "run", "--quiet", "--log-format", "json", "--log-output", "file=/out/k6.log", "/scripts/prepare-sessions.js"]
    result = subprocess.run(command, env=environment, capture_output=True, text=True)
    (out / "k6.stderr").write_text(result.stdout + result.stderr, encoding="utf-8")
    if result.returncode != 0:
        raise SystemExit(f"k6 login gagal dengan exit {result.returncode}; lihat {out / 'k6.stderr'}.")


def collect(log: Path, accounts: int) -> list[dict]:
    sessions: dict[int, dict] = {}
    for line in log.read_text(encoding="utf-8").splitlines():
        try:
            message = json.loads(line).get("msg", "")
        except json.JSONDecodeError:
            continue
        if message.startswith("SESSION "):
            entry = json.loads(message[8:])
            sessions[entry["index"]] = entry
    missing = [index for index in range(accounts) if index not in sessions]
    if missing:
        raise SystemExit(f"{len(missing)} akun tanpa sesi, contoh indeks {missing[:5]}; snapshot tidak dibuat.")
    return [sessions[index] for index in range(accounts)]


def dump(database_name: str, target: Path) -> None:
    inside = f"/tmp/{target.name}"
    subprocess.run(["docker", "exec", DATABASE_CONTAINER, "pg_dump", "-U", "compare_admin", "-d", database_name, "-Fc",
                    "--exclude-table-data=cache", "--exclude-table-data=cache_locks", "-f", inside], check=True)
    subprocess.run(["docker", "cp", f"{DATABASE_CONTAINER}:{inside}", str(target)], check=True)
    subprocess.run(["docker", "exec", DATABASE_CONTAINER, "rm", "-f", inside], check=True)
    if os.name != "nt":
        target.chmod(0o600)


def prepare(candidate: str, name: str, seed: Path, fixture: Path, directory: Path, vus: int) -> dict:
    directory.mkdir(parents=True, exist_ok=True)
    if os.name != "nt":
        directory.chmod(0o700)
    db = database(name, candidate, 0)
    lab.clone(db, seed)
    out = directory / f"{name}-{candidate}-login"
    out.mkdir(exist_ok=True)
    started = time.monotonic()
    sut = start(candidate, db)
    try:
        login_all(sut["internal"], fixture, out, vus)
    finally:
        stop(candidate)
    accounts = len(json.loads(fixture.read_text(encoding="utf-8"))["accounts"])
    sessions = collect(out / "k6.log", accounts)
    (out / "k6.log").unlink()
    target = directory / f"{candidate}.dump"
    dump(db, target)
    baseline = directory / f"{candidate}-baseline.json"
    oracle = subprocess.run([sys.executable, "-B", str(SCRIPTS / "sql_oracle.py"), "baseline", "--database", db, "--seed", str(target),
                             "--output", str(baseline)], capture_output=True, text=True)
    if oracle.returncode != 0:
        raise SystemExit("Baseline oracle gagal: " + (oracle.stderr or oracle.stdout)[-800:])
    private_write(directory / f"{candidate}-sessions.json", json.dumps({
        "candidate": candidate, "database": db, "seed": seed.name, "dump": target.name,
        "created_at": datetime.now(timezone.utc).isoformat(), "sessions": sessions}) + "\n")
    return {"candidate": candidate, "database": db, "sessions": len(sessions), "dump": str(target), "baseline": str(baseline),
            "seconds": round(time.monotonic() - started, 1), "sut_image": sut.get("image_id") or sut.get("image")}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["prepare"])
    parser.add_argument("--candidates", nargs="+", required=True)
    parser.add_argument("--name", required=True, help="Awalan database clone, huruf kecil dan angka.")
    parser.add_argument("--seed", type=Path, default=PRIVATE / "seed-128.dump")
    parser.add_argument("--fixture", type=Path, default=PRIVATE / "fixture-128.json")
    parser.add_argument("--directory", type=Path, default=PRIVATE / "prepared")
    parser.add_argument("--vus", type=int, default=8, help="Login serentak; bukan beban yang diukur.")
    args = parser.parse_args()
    for candidate in args.candidates:
        print(json.dumps(prepare(candidate, args.name, args.seed, args.fixture, args.directory, args.vus)), flush=True)
