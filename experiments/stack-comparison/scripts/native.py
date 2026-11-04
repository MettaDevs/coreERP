"""Jalankan image kandidat native di jaringan cluster uji dengan budget resource yang sama."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.error
import urllib.request

import lab
from sql_observer import validate_database

LAB = Path(__file__).resolve().parents[1]
NETWORK = "coreerp-stack-comparison_default"
# Port internal 8000 untuk semua kandidat. Budget sama: 2 CPU, 1 GiB, maksimal 8 koneksi database per
# container. Go dan Rust memakai 2 thread dari kuota cgroup; Bun satu thread per proses, jadi dua proses
# reusePort dengan pool 4. PHP memakai 8 worker Octane, satu koneksi per worker (lihat load.py).
BUN_PROCESSES = 2
CANDIDATES = {
    "go": {"image": "coreerp-native-go:local", "port": 18642, "command": [],
           "environment": {"LISTEN_ADDR": "0.0.0.0:8000", "DB_POOL_SIZE": "8"}},
    "hono": {"image": "coreerp-native-ts:local", "port": 18644,
             "command": ["sh", "-c", f"for i in $(seq {BUN_PROCESSES}); do bun dist/hono.js & done; wait"],
             "environment": {"HOST": "0.0.0.0", "PORT": "8000", "DB_POOL_SIZE": str(8 // BUN_PROCESSES)}},
    "elysia": {"image": "coreerp-native-ts:local", "port": 18645,
               "command": ["sh", "-c", f"for i in $(seq {BUN_PROCESSES}); do bun dist/elysia.js & done; wait"],
               "environment": {"HOST": "0.0.0.0", "PORT": "8000", "DB_POOL_SIZE": str(8 // BUN_PROCESSES)}},
    "rust": {"image": "coreerp-native-rust:local", "port": 18643, "command": [],
             "environment": {"BIND_HOST": "0.0.0.0", "PORT": "8000", "DB_POOL": "8"}},
    # Kandidat Elysia yang sudah dipindah ke repo operations-metta; dipakai untuk membuktikan pemindahannya
    # tidak mengubah perilaku.
    "operations": {"image": "operations-metta:local", "port": 18647,
                   "command": ["sh", "-c", f"for i in $(seq {BUN_PROCESSES}); do bun dist/main.js & done; wait"],
                   "environment": {"HOST": "0.0.0.0", "PORT": "8000", "DB_POOL_SIZE": str(8 // BUN_PROCESSES)}},
}
SECRETS = ["DB_PASSWORD", "APP_KEY"]
# Varian teroptimasi (optimized.md): image yang sama, saklar lewat environment.
OPTIMIZED = {"SESSION_BACKEND": "valkey", "CONTEXT_CACHE": "on", "VALKEY_HOST": "valkey", "VALKEY_PORT": "6379"}


def variant() -> str:
    return os.environ.get("COMPARE_VARIANT", "baseline")


def docker(*args: str, environment: dict | None = None) -> str:
    return subprocess.run(["docker", *args], check=True, capture_output=True, text=True, env=environment).stdout.strip()


def listening(container: str) -> int:
    logs = subprocess.run(["docker", "logs", container], capture_output=True, text=True)
    return (logs.stdout + logs.stderr).count('"event":"listening"')


def php_state() -> dict:
    status = docker("ps", "-a", "--filter", "name=^coreerp-stack-comparison-php-1$", "--format", "{{.Status}}")
    return {"container": "coreerp-stack-comparison-php-1", "status": status or "absent", "running": status.startswith("Up")}


def up(name: str, database: str, runtime_env: Path) -> dict:
    validate_database(database)
    candidate = CANDIDATES[name]
    php = php_state()
    if php["running"]:
        raise SystemExit("Runtime PHP uji masih berjalan; kandidat native wajib diuji dengan PHP mati.")
    environment = dict(os.environ)
    for line in runtime_env.read_text(encoding="utf-8").splitlines():
        key, _, value = line.partition("=")
        if key in SECRETS:
            environment[key] = value
    settings = {"DB_HOST": "database", "DB_PORT": "5432", "DB_USERNAME": "compare_admin", "DB_DATABASE": database, **candidate["environment"]}
    if variant() == "optimized":
        settings.update(OPTIMIZED)
        lab.compose("up", "-d", "--wait", "valkey")
    # Batas berkas terbuka yang sama untuk semua kandidat. Bawaan container soft 1024: runtime Go dan Bun menaikkannya
    # sendiri ke hard limit, Rust tidak, sehingga Rust menolak koneksi ke-±1.015 (r9n-rust-1100: 115 dial timeout).
    arguments = ["run", "-d", "--name", "coreerp-native-" + name, "--network", NETWORK, "--cpus", "2", "--memory", "1g",
                 "--ulimit", "nofile=65536:65536", "-p", f"127.0.0.1:{candidate['port']}:8000"]
    for key, value in settings.items():
        arguments += ["-e", f"{key}={value}"]
    for key in SECRETS:
        arguments += ["-e", key]
    # Uji A/B memasang image lain lewat <KANDIDAT>_IMAGE tanpa mengubah tabel kandidat.
    image = os.environ.get(f"{name.upper()}_IMAGE", candidate["image"])
    container = docker(*arguments, image, *candidate["command"], environment=environment)
    deadline = time.monotonic() + 30
    while True:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{candidate['port']}/up", timeout=2):
                break
        except urllib.error.HTTPError as error:
            if error.code < 500:
                break
        except OSError:
            pass
        if time.monotonic() > deadline:
            logs = subprocess.run(["docker", "logs", "--tail", "20", container], capture_output=True, text=True)
            raise SystemExit("Kandidat tidak menjawab HTTP dalam 30 detik:\n" + logs.stdout + logs.stderr)
        time.sleep(0.25)
    # Dua proses Bun berbagi port lewat reusePort; satu respons belum membuktikan proses kedua siap.
    expected = BUN_PROCESSES if name in {"hono", "elysia", "operations"} else 1
    while expected > 1 and listening(container) < expected:
        if time.monotonic() > deadline:
            raise SystemExit(f"Hanya sebagian proses {name} yang siap dalam 30 detik.")
        time.sleep(0.25)
    inspect = json.loads(docker("inspect", container))[0]
    return {
        "candidate": name, "container": container[:12], "image": image, "image_id": inspect["Image"],
        "database": database, "base": f"http://127.0.0.1:{candidate['port']}", "settings": settings,
        "cpus": 2, "memory": "1g", "nofile": 65536, "network": NETWORK, "php_before_start": php, "variant": variant(),
    }


def down(name: str) -> None:
    subprocess.run(["docker", "rm", "-f", "coreerp-native-" + name], check=True, capture_output=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["up", "down"])
    parser.add_argument("candidate", choices=sorted(CANDIDATES))
    parser.add_argument("--database")
    parser.add_argument("--env", type=Path, default=LAB / "private" / "runtime.env")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.action == "down":
        down(args.candidate)
    else:
        if not args.database:
            parser.error("up membutuhkan --database.")
        evidence = up(args.candidate, args.database, args.env)
        if args.output:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(evidence))
