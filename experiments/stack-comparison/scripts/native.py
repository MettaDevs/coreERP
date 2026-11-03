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

from sql_observer import validate_database

LAB = Path(__file__).resolve().parents[1]
NETWORK = "coreerp-stack-comparison_default"
# Port internal 8000 untuk semua kandidat; hanya nama variabel listener dan pool yang berbeda per bahasa.
CANDIDATES = {
    "go": {"image": "coreerp-native-go:local", "port": 18642, "command": [],
           "environment": {"LISTEN_ADDR": "0.0.0.0:8000", "DB_POOL_SIZE": "10"}},
    "hono": {"image": "coreerp-native-ts:local", "port": 18644, "command": ["bun", "dist/hono.js"],
             "environment": {"HOST": "0.0.0.0", "PORT": "8000", "DB_POOL_SIZE": "10"}},
    "elysia": {"image": "coreerp-native-ts:local", "port": 18645, "command": ["bun", "dist/elysia.js"],
               "environment": {"HOST": "0.0.0.0", "PORT": "8000", "DB_POOL_SIZE": "10"}},
    "rust": {"image": "coreerp-native-rust:local", "port": 18643, "command": [],
             "environment": {"BIND_HOST": "0.0.0.0", "PORT": "8000", "DB_POOL": "8"}},
}
SECRETS = ["DB_PASSWORD", "APP_KEY"]


def docker(*args: str, environment: dict | None = None) -> str:
    return subprocess.run(["docker", *args], check=True, capture_output=True, text=True, env=environment).stdout.strip()


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
    arguments = ["run", "-d", "--name", "coreerp-native-" + name, "--network", NETWORK, "--cpus", "2", "--memory", "1g",
                 "-p", f"127.0.0.1:{candidate['port']}:8000"]
    for key, value in settings.items():
        arguments += ["-e", f"{key}={value}"]
    for key in SECRETS:
        arguments += ["-e", key]
    container = docker(*arguments, candidate["image"], *candidate["command"], environment=environment)
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
    inspect = json.loads(docker("inspect", container))[0]
    return {
        "candidate": name, "container": container[:12], "image": candidate["image"], "image_id": inspect["Image"],
        "database": database, "base": f"http://127.0.0.1:{candidate['port']}", "settings": settings,
        "cpus": 2, "memory": "1g", "network": NETWORK, "php_before_start": php,
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
