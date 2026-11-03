"""Membuat fixture eksperimen lokal tanpa memakai konfigurasi runtime bersama."""

from __future__ import annotations

import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
import urllib.error
import urllib.request

from sql_observer import sql, validate_database

ROOT = Path(__file__).resolve().parents[3]
LAB = ROOT / "experiments" / "stack-comparison"
PRIVATE = LAB / "private"
COMPOSE = LAB / "infra" / "compose.yaml"


def command(*args: str, capture: bool = False) -> subprocess.CompletedProcess:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=capture, text=True)


def compose(*args: str) -> None:
    command("docker", "compose", "--env-file", str(PRIVATE / "runtime.env"), "-f", str(COMPOSE), *args)


def initialize() -> None:
    PRIVATE.mkdir(parents=True, exist_ok=True)
    environment = PRIVATE / "runtime.env"
    if environment.exists():
        raise SystemExit("Fixture sudah dikonfigurasi. Gunakan konfigurasi tersebut.")
    php_image = command("docker", "image", "inspect", "coreerp-pf01-test:local", "--format", "{{.Id}}", capture=True).stdout.strip()
    postgres_image = command("docker", "image", "inspect", "postgres:17", "--format", "{{index .RepoDigests 0}}", capture=True).stdout.strip()
    command("docker", "volume", "inspect", "coreerp-pf01-vendor", capture=True)
    secret = secrets.token_hex(32)
    values = {
        "PHP_IMAGE": php_image,
        "POSTGRES_IMAGE": postgres_image,
        "PHP_VENDOR_VOLUME": "coreerp-pf01-vendor",
        "DB_PASSWORD": secret,
        "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
        "COMPARE_PASSWORD": secrets.token_urlsafe(24),
        "COREERP_PROVIDER_EMAIL": "operator@stack-comparison.example.invalid",
        "COREERP_PROVIDER_PASSWORD": secrets.token_urlsafe(24),
    }
    environment.write_text("\n".join(f"{key}={value}" for key, value in values.items()) + "\n", encoding="utf-8")
    if os.name != "nt":
        PRIVATE.chmod(0o700)
        environment.chmod(0o600)
    manifest = {
        "source_sha": command("git", "rev-parse", "HEAD", capture=True).stdout.strip(),
        "php_image": php_image,
        "postgres_image": postgres_image,
        "database": "erp_compare",
        "project": "coreerp-stack-comparison",
        "purpose": "local correctness only; no capacity claim",
    }
    (PRIVATE / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    command("git", "archive", "--format=tar", "--output=" + str(PRIVATE / "source.tar"), "HEAD")
    print(json.dumps(manifest, indent=2))


def seed_source() -> None:
    compose("up", "-d", "--wait", "database", "tools")
    result = command("docker", "compose", "--env-file", str(PRIVATE / "runtime.env"), "-f", str(COMPOSE), "ps", "-q", "tools", capture=True)
    container = result.stdout.strip()
    if not container:
        raise SystemExit("Container tools tidak ditemukan.")
    command("docker", "cp", str(PRIVATE / "source.tar"), container + ":/tmp/compare-source.tar")
    command("docker", "exec", container, "tar", "-xf", "/tmp/compare-source.tar", "-C", "/repo")
    command("docker", "exec", container, "sh", "-c", "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache")
    print("Source snapshot dimuat; belum ada fixture bisnis atau uji kapasitas.")


def clone(database: str, seed: Path = PRIVATE / "seed.dump") -> None:
    validate_database(database)
    exists = sql("SELECT count(*) FROM pg_database WHERE datname='" + database + "';")
    if exists != "0":
        raise SystemExit("Database sudah ada. Alat tidak menimpa atau menghapus database.")
    command("docker", "cp", str(seed), "coreerp-stack-comparison-database-1:/tmp/compare-baseline.dump")
    sql('CREATE DATABASE "' + database + '";')
    command("docker", "exec", "coreerp-stack-comparison-database-1", "pg_restore", "-U", "compare_admin", "-d", database, "--no-owner", "--no-acl", "--exit-on-error", "/tmp/compare-baseline.dump")
    print(json.dumps({"database_created": database, "source": seed.name, "php_used": False}))


def wait_php() -> None:
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with urllib.request.urlopen("http://127.0.0.1:18641/up", timeout=2) as response:
                if response.status == 200:
                    return
        except (OSError, urllib.error.URLError):
            pass
        time.sleep(0.25)
    raise RuntimeError("PHP eksperimen belum siap setelah 30 detik.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["init", "source", "up", "stop", "clone"])
    parser.add_argument("--database")
    parser.add_argument("--seed", type=Path, default=PRIVATE / "seed.dump")
    arguments = parser.parse_args()
    if arguments.action == "init":
        initialize()
    elif arguments.action == "source":
        seed_source()
    elif arguments.action == "up":
        if arguments.database:
            validate_database(arguments.database)
            os.environ["COMPARE_DATABASE"] = arguments.database
        compose("up", "-d", "php")
        wait_php()
    elif arguments.action == "clone":
        if not arguments.database:
            parser.error("clone membutuhkan --database.")
        clone(arguments.database, arguments.seed)
    elif arguments.action == "stop":
        compose("stop")
