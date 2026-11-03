"""Urutan SQL skenario login dari log PostgreSQL, dikelompokkan per transaksi.

`pg_stat_statements` tidak menyimpan urutan maupun batas transaksi. Alat ini menyalakan log_statement=all
hanya pada clone uji (berlaku untuk koneksi baru, jadi nyalakan sebelum aplikasi start), menjalankan
skenario login di antara statement penanda, lalu membandingkan isi tiap transaksi. Cluster memakai
log_parameter_max_length=0, jadi nilai parameter tidak masuk log.
"""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import subprocess
import uuid

from compare_queries import LOCK, tables, verb
from http_baseline import Client, fixture_password
from sql_observer import CONTAINER, sql, validate_database

LAB = Path(__file__).resolve().parents[1]
LINE = re.compile(r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d+ UTC \[(\d+)\] (\w+):\s+(.*)$")
STATEMENT = re.compile(r"^(?:statement|execute [^:]+):\s*(.*)$", re.S)


def scenarios(base: str, fixture: dict, password: str, email: str) -> dict:
    account = fixture["accounts"][0]
    client, guest = Client(base), Client(base)
    steps = {
        "login-page": lambda: client.request("GET", "/login"),
        "login-success": lambda: client.request("POST", "/login", {"email": account["email"], "password": password}),
        "guest-page": lambda: guest.request("GET", "/login"),
        "login-failed": lambda: guest.request("POST", "/login", {"email": email, "password": "wrong-password"}),
    }
    for attempt in range(2, 6):
        steps[f"login-failed-{attempt}"] = lambda: guest.request("POST", "/login", {"email": email, "password": "wrong-password"})
    steps["login-throttled"] = lambda: guest.request("POST", "/login", {"email": email, "password": "wrong-password"})
    statuses = {}
    for name, step in steps.items():
        marker(name, "start")
        statuses[name] = step().status
        marker(name, "end")
    return statuses


DATABASE = ""


def marker(name: str, edge: str) -> None:
    sql(f"SELECT 'login-sequence:{name}:{edge}';", DATABASE)


def parse(log: str) -> list[tuple[int, str, str]]:
    entries: list[list] = []
    for line in log.splitlines():
        match = LINE.match(line)
        if match:
            entries.append([int(match.group(1)), match.group(2), match.group(3)])
        elif entries and line.startswith(("\t", " ")):
            entries[-1][2] += " " + line.strip()
    return [(pid, level, text) for pid, level, text in entries]


def shape(text: str) -> str:
    text = re.sub(r"\s+", " ", text).strip().lower()
    kind = verb(text)
    names = tables(text)
    lock = LOCK.search(text)
    if kind in {"begin", "commit", "rollback", "savepoint", "release", "start", "reset", "set", "discard"}:
        return text.split(";")[0].replace("start transaction", "begin")
    if names == ["sessions"] and kind in {"insert", "update", "upsert"}:
        return "write sessions"
    # Lotere GC sesi Laravel (2/100 per request) acak; kandidat menirunya dengan peluang yang sama.
    if names == ["sessions"] and kind == "delete" and "last_activity" in text:
        return "gc sessions"
    return " ".join([kind, ",".join(names) or "-"] + (["for " + lock.group(1)] if lock else []) + (["set_config"] if "set_config(" in text else []))


def windows(log: str) -> dict:
    """Statement aplikasi di antara penanda start dan end tiap skenario; koneksi psql penanda dibuang."""
    result, current, markers = {}, None, set()
    for pid, level, text in parse(log):
        found = re.search(r"'login-sequence:([a-z0-9-]+):(start|end)'", text)
        if found:
            markers.add(pid)
            current = found.group(1) if found.group(2) == "start" else None
            if current:
                result[current] = []
            continue
        statement = STATEMENT.match(text) if level == "LOG" else None
        if current and statement and pid not in markers:
            for part in [piece for piece in statement.group(1).split(";") if piece.strip()]:
                result[current].append((pid, shape(part)))
    return result


def transactions(statements: list[tuple[int, str]]) -> dict:
    """Pisahkan statement di luar transaksi dari isi tiap transaksi; urutan baca dalam blok diabaikan."""
    outside, blocks, open_blocks = Counter(), [], {}
    for pid, text in statements:
        if text == "begin":
            open_blocks[pid] = Counter()
        elif text in {"commit", "rollback"} and pid in open_blocks:
            blocks.append(dict(sorted(open_blocks.pop(pid).items())))
        elif text.startswith(("savepoint", "release", "rollback to")) or text == "gc sessions":
            continue
        elif pid in open_blocks:
            open_blocks[pid][text] += 1
        else:
            outside[text] += 1
    writes = {text: count for text, count in outside.items() if text.split()[0] in {"insert", "update", "delete", "upsert"}}
    reads = sorted({text for text in outside if text.split()[0] == "select"})
    return {"outside_writes": dict(sorted(writes.items())), "outside_reads": reads,
            "transactions": sorted(blocks, key=json.dumps), "open_at_end": len(open_blocks)}


def cache_rows(database: str, fixture: dict, email: str) -> dict:
    """Isi tabel cache sesudah skenario, dengan email/IP/hash/epoch dinormalisasi; kunci route dihitung ulang."""
    rows = json.loads(sql("SELECT coalesce(json_agg(json_build_array(key,value) ORDER BY key),'[]') FROM cache;", database))
    ips = {key.split("|", 1)[1].removesuffix(":timer") for key, _ in rows if email in key and "|" in key}
    if len(ips) != 1:
        return {"rows": [], "route_keys_verified": False, "reason": "kunci Fortify tidak ditemukan"}
    ip = ips.pop()
    route = {hashlib.md5(("login" + address + "|" + ip).encode()).hexdigest(): label
             for address, label in [(fixture["accounts"][0]["email"].lower(), "ACCOUNT"), (email, "GUEST")]}
    shaped = []
    for key, value in rows:
        key = key.replace(email, "EMAIL").replace(ip, "IP")
        for digest, label in route.items():
            key = key.replace(digest, "MD5(login+" + label + ")")
        number = re.fullmatch(r"i:(\d+);", value)
        shaped.append([key, "i:TS;" if number and int(number.group(1)) > 10**9 else value])
    unexplained = [key for key, _ in shaped if re.search(r"[0-9a-f]{32}", key)]
    return {"rows": sorted(shaped), "route_keys_verified": not unexplained}


def compare(expected: dict, actual: dict) -> list[dict]:
    differences = []
    if expected.get("cache") != actual.get("cache"):
        differences.append({"field": "cache", "expected": expected.get("cache"), "actual": actual.get("cache")})
    for scenario in expected["scenarios"]:
        left, right = expected["scenarios"][scenario], actual["scenarios"].get(scenario)
        if right is None:
            differences.append({"scenario": scenario, "reason": "missing"})
            continue
        for key in ["status", "outside_writes", "outside_reads", "transactions", "open_at_end"]:
            if left[key] != right[key]:
                differences.append({"scenario": scenario, "field": key, "expected": left[key], "actual": right[key]})
    return differences


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["enable", "capture", "disable"])
    parser.add_argument("--database", required=True)
    parser.add_argument("--base")
    parser.add_argument("--fixture", type=Path, default=LAB / "private" / "fixture.json")
    parser.add_argument("--env", type=Path, default=LAB / "private" / "runtime.env")
    parser.add_argument("--expected", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    validate_database(args.database)
    DATABASE = args.database
    if args.action == "enable":
        sql(f"ALTER DATABASE \"{args.database}\" SET log_statement='all';")
    elif args.action == "disable":
        sql(f"ALTER DATABASE \"{args.database}\" RESET log_statement;")
    else:
        started = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")
        fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
        email = f"sequence-{uuid.uuid4().hex}@stack-comparison.example.invalid"
        statuses = scenarios(args.base, fixture, fixture_password(args.env), email)
        log = subprocess.run(["docker", "logs", "--since", started, CONTAINER], capture_output=True, text=True, encoding="utf-8", errors="replace")
        captured = windows(log.stdout + log.stderr)
        report = {"database": args.database, "base": args.base,
                  "scenarios": {name: {"status": statuses[name], **transactions(captured.get(name, [])),
                                       "sequence": [text for _, text in captured.get(name, [])]} for name in statuses},
                  "cache": cache_rows(args.database, fixture, email)}
        if args.expected:
            report["differences"] = compare(json.loads(args.expected.read_text(encoding="utf-8")), report)
            report["verdict"] = "NOT VERIFIED" if report["differences"] else "VERIFIED"
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"verdict": report.get("verdict", "RECORDED"), "differences": len(report.get("differences", [])),
                          "statements": {name: len(scenario["sequence"]) for name, scenario in report["scenarios"].items()}}))
        if report.get("differences"):
            raise SystemExit(1)
