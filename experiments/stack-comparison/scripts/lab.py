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
# COMPARE_VARIANT=optimized menambah Valkey dan source PHP ber-overlay (optimized.md).
COMPOSE_OPTIMIZED = LAB / "infra" / "compose.optimized.yaml"
SOURCE_VOLUME = "coreerp-stack-comparison-source"
OPTIMIZED_SOURCE_VOLUME = "coreerp-stack-comparison-source-optimized"
DATABASE_CONTAINER = "coreerp-stack-comparison-database-1"


def command(*args: str, capture: bool = False) -> subprocess.CompletedProcess:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=capture, text=True)


def compose(*args: str) -> None:
    files = ["-f", str(COMPOSE)]
    if os.environ.get("COMPARE_VARIANT") == "optimized":
        files += ["-f", str(COMPOSE_OPTIMIZED)]
    command("docker", "compose", "--env-file", str(PRIVATE / "runtime.env"), *files, *args)


def runtime_value(name: str) -> str:
    for line in (PRIVATE / "runtime.env").read_text(encoding="utf-8").splitlines():
        key, _, value = line.partition("=")
        if key == name:
            return value.strip()
    raise KeyError(name)


def optimized_source() -> None:
    """Salin source snapshot ke volume terpisah lalu timpa dengan overlay php/optimized; source dasar tidak disentuh."""
    overlay = LAB / "php" / "optimized"
    if not (overlay / "apps").is_dir():
        raise SystemExit("Overlay php/optimized/apps belum ada.")
    exists = subprocess.run(["docker", "volume", "inspect", OPTIMIZED_SOURCE_VOLUME], capture_output=True).returncode == 0
    if exists:
        raise SystemExit(f"Volume {OPTIMIZED_SOURCE_VOLUME} sudah ada; alat tidak menimpanya.")
    command("docker", "volume", "create", OPTIMIZED_SOURCE_VOLUME, capture=True)
    command("docker", "run", "--rm", "--entrypoint", "sh", "-v", f"{SOURCE_VOLUME}:/from:ro", "-v", f"{OPTIMIZED_SOURCE_VOLUME}:/to",
            "-v", f"{overlay}:/overlay:ro", runtime_value("PHP_IMAGE"), "-c", "cp -a /from/. /to/ && cp -a /overlay/apps/. /to/apps/")
    print(json.dumps({"volume": OPTIMIZED_SOURCE_VOLUME, "overlay": str(overlay)}))


# Setelan PostgreSQL varian teroptimasi (optimized.md butir 7); berlaku untuk seluruh cluster, jadi hanya dipasang
# sesudah run dasar selesai dan dikembalikan sesudah run teroptimasi.
PG_TUNING = {"max_wal_size": "4GB", "checkpoint_timeout": "15min", "random_page_cost": "1.1"}


def pg_tuning(action: str) -> None:
    def current() -> dict:
        names = ",".join(f"'{name}'" for name in PG_TUNING)
        return json.loads(sql(f"SELECT json_object_agg(name, setting || coalesce(unit, '')) FROM pg_settings WHERE name IN ({names});"))
    before = current()
    for name, value in PG_TUNING.items():
        sql(f"ALTER SYSTEM SET {name} = '{value}';" if action == "apply" else f"ALTER SYSTEM RESET {name};")
    sql("SELECT pg_reload_conf();")
    time.sleep(1)
    print(json.dumps({"action": action, "before": before, "after": current()}))


def optimized_seed(database: str, seed: Path, output: Path) -> None:
    """Seed versi teroptimasi: clone seed dasar, pasang skema versi konteks, lalu dump dengan format yang sama."""
    # Perintah docker berjalan dari akar repo (command() memakai cwd=ROOT), jadi path relatif harus dibuat absolut.
    output = output.resolve()
    clone(database, seed)
    schema = LAB / "infra" / "optimized-schema.sql"
    subprocess.run(["docker", "exec", "-i", DATABASE_CONTAINER, "psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-U", "compare_admin", "-d", database],
                   input=schema.read_text(encoding="utf-8"), text=True, check=True)
    inside = f"/tmp/{output.name}"
    command("docker", "exec", DATABASE_CONTAINER, "pg_dump", "-U", "compare_admin", "-d", database, "-Fc",
            "--exclude-table-data=sessions", "--exclude-table-data=cache", "--exclude-table-data=cache_locks", "-f", inside)
    command("docker", "cp", f"{DATABASE_CONTAINER}:{inside}", str(output))
    command("docker", "exec", DATABASE_CONTAINER, "rm", "-f", inside)
    if os.name != "nt":
        output.chmod(0o600)
    print(json.dumps({"seed": str(output), "database": database, "schema": schema.name}))


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
    command("docker", "cp", str(seed.resolve()), "coreerp-stack-comparison-database-1:/tmp/compare-baseline.dump")
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
    parser.add_argument("action", choices=["init", "source", "up", "stop", "clone", "optimized-source", "optimized-seed",
                                           "pg-tuning-apply", "pg-tuning-reset"])
    parser.add_argument("--database")
    parser.add_argument("--seed", type=Path, default=PRIVATE / "seed.dump")
    parser.add_argument("--output", type=Path, help="optimized-seed: berkas dump hasil.")
    arguments = parser.parse_args()
    if arguments.action == "init":
        initialize()
    elif arguments.action == "source":
        seed_source()
    elif arguments.action == "up":
        if arguments.database:
            validate_database(arguments.database)
            os.environ["COMPARE_DATABASE"] = arguments.database
        if os.environ.get("COMPARE_VARIANT") == "optimized":
            compose("up", "-d", "--wait", "valkey")
        compose("up", "-d", "php")
        wait_php()
    elif arguments.action == "clone":
        if not arguments.database:
            parser.error("clone membutuhkan --database.")
        clone(arguments.database, arguments.seed)
    elif arguments.action == "stop":
        compose("stop")
    elif arguments.action in {"pg-tuning-apply", "pg-tuning-reset"}:
        pg_tuning(arguments.action.removeprefix("pg-tuning-"))
    elif arguments.action == "optimized-source":
        optimized_source()
    elif arguments.action == "optimized-seed":
        if not arguments.database or not arguments.output:
            parser.error("optimized-seed membutuhkan --database dan --output.")
        optimized_seed(arguments.database, arguments.seed, arguments.output)
