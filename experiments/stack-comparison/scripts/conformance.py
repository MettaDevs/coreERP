"""Probe negatif, race, pencabutan akses, dan rollback yang sama untuk PHP dan kandidat native.

Hanya HTTP dan SQL pada clone uji; tidak memakai kode kandidat. Probe HTTP lama memakai harapan status
yang sudah dibuktikan pada PHP. Probe baru merekam hasil, lalu `--expected` membandingkannya dengan PHP.
"""

from __future__ import annotations

import argparse
import base64
import binascii
import importlib.util
import json
import os
from pathlib import Path
import re
import uuid

from http_baseline import Client, fixture_password
from sql_observer import sql, validate_database
from valkey import Valkey

LAB = Path(__file__).resolve().parents[1]
ID = re.compile(r"[A-Za-z0-9]{26}")


def owns_session(value: bytes, user_id: int) -> bool:
    """Payload sesi milik user: kandidat native menyimpan base64 JSON, Laravel menyimpan serialize PHP."""
    try:
        data = json.loads(base64.b64decode(value, validate=True))
    except (binascii.Error, ValueError):
        data = None
    if isinstance(data, dict):
        return any(key.startswith("login_web_") and str(item) == str(user_id) for key, item in data.items())
    return re.search(rb'login_web_[0-9a-f]{40}";i:%d;' % user_id, value) is not None


def valkey_sessions(user_id: int) -> list[bytes]:
    with Valkey() as store:
        return [key for key in store.keys() if owns_session(store.command("GET", key) or b"", user_id)]


def age_sessions(user_id: int, remaining_seconds: int | None) -> int:
    """Padanan Valkey untuk UPDATE/DELETE sessions: sisa TTL tertentu, atau hapus bila None."""
    keys = valkey_sessions(user_id)
    with Valkey() as store:
        for key in keys:
            store.command("DEL", key) if remaining_seconds is None else store.command("EXPIRE", key, remaining_seconds)
    return len(keys)


def http_probes():
    spec = importlib.util.spec_from_file_location("conformance_http", LAB / "native" / "go" / "conformance.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.run


def revocation(base: str, fixture: dict, password: str, database: str) -> list[dict]:
    """Izin dibaca ulang tiap request: mutasi akses pada sesi yang masih hidup harus langsung berlaku."""
    account = fixture["accounts"][3]
    membership, user_id = account["membership_id"], int(account["user_id"])
    if not ID.fullmatch(membership):
        raise ValueError("ID fixture tidak sah.")
    client = Client(base)
    client.login(account, password)
    path = "/api/modules/" + fixture["module_id"] + "/v1/penerimaan-aset"
    observed: list[dict] = []

    def observe(label: str) -> None:
        result = client.request("GET", path)
        empty = isinstance(result.body, dict) and result.body.get("data") == []
        observed.append({"probe": label, "status": result.status, "empty": empty})

    def run(statement: str) -> str:
        return sql(statement, database)

    def literal(value) -> str:
        return "null" if value is None else "'" + str(value).replace("'", "''") + "'"

    def mutated(label: str, change: str, backup: str, restore) -> None:
        rows = json.loads(run(f"SELECT coalesce(json_agg(row_to_json(b)),'[]') FROM ({backup}) b;"))
        try:
            run(change)
            observe(label)
        finally:
            for row in rows:
                run(restore(row))

    owned = f"SELECT id FROM role_assignments WHERE membership_id='{membership}'"
    observe("active-session")
    mutated("assignment-revoked-live-session", f"UPDATE role_assignments SET status='inactive' WHERE membership_id='{membership}';",
            f"SELECT id,status FROM role_assignments WHERE membership_id='{membership}'",
            lambda row: f"UPDATE role_assignments SET status={literal(row['status'])} WHERE id={literal(row['id'])};")
    observe("assignment-restored")
    mutated("assignment-expired-live-session", f"UPDATE role_assignments SET valid_until=now()-interval '1 minute' WHERE membership_id='{membership}';",
            f"SELECT id,valid_until FROM role_assignments WHERE membership_id='{membership}'",
            lambda row: f"UPDATE role_assignments SET valid_until={literal(row['valid_until'])} WHERE id={literal(row['id'])};")
    mutated("role-disabled-live-session", f"UPDATE roles SET is_active=false WHERE id IN (SELECT role_id FROM role_assignments WHERE membership_id='{membership}');",
            f"SELECT DISTINCT r.id,r.is_active FROM roles r JOIN role_assignments a ON a.role_id=r.id WHERE a.membership_id='{membership}'",
            lambda row: f"UPDATE roles SET is_active={'true' if row['is_active'] else 'false'} WHERE id={literal(row['id'])};")
    mutated("data-scope-expired-live-session",
            f"UPDATE role_assignment_data_policy_scopes SET valid_until=now()-interval '1 minute' WHERE role_assignment_id IN ({owned}) AND policy_code='management-aset.asset-responsibility';",
            f"SELECT id,valid_until FROM role_assignment_data_policy_scopes WHERE role_assignment_id IN ({owned}) AND policy_code='management-aset.asset-responsibility'",
            lambda row: f"UPDATE role_assignment_data_policy_scopes SET valid_until={literal(row['valid_until'])} WHERE id={literal(row['id'])};")
    mutated("membership-revoked-live-session", f"UPDATE tenant_memberships SET status='inactive' WHERE id='{membership}';",
            f"SELECT id,status FROM tenant_memberships WHERE id='{membership}'",
            lambda row: f"UPDATE tenant_memberships SET status={literal(row['status'])} WHERE id={literal(row['id'])};")
    observe("membership-restored")
    # Margin 60 detik di kedua sisi lifetime 120 menit; ::integer membulatkan, jadi -7201 jatuh tepat di batas detik.
    # Varian teroptimasi menyimpan sesi di Valkey: sisa TTL 60 detik dan DEL adalah padanan kedua UPDATE dan DELETE.
    valkey = os.environ.get("COMPARE_VARIANT") == "optimized"
    if valkey:
        if not age_sessions(user_id, 60):
            raise RuntimeError("Sesi Valkey milik probe tidak ditemukan.")
    else:
        run(f"UPDATE sessions SET last_activity=floor(extract(epoch FROM now()))::integer-7140 WHERE user_id={user_id};")
    observe("database-session-near-lifetime")
    if valkey:
        age_sessions(user_id, None)
    else:
        run(f"UPDATE sessions SET last_activity=floor(extract(epoch FROM now()))::integer-7260 WHERE user_id={user_id};")
    observe("database-session-expired")
    client.login(account, password)
    if valkey:
        age_sessions(user_id, None)
    else:
        run(f"DELETE FROM sessions WHERE user_id={user_id};")
    observe("database-session-deleted")
    client.login(account, password)
    observe("new-session-after-revocation")
    return observed


def rollback(base: str, fixture: dict, password: str, database: str) -> list[dict]:
    """INSERT posting yang gagal di tengah completion wajib membatalkan claim, aset, nomor aset, dan posting."""
    account = fixture["accounts"][1]
    tenant = next(item for item in fixture["tenants"] if item["tenant_id"] == account["tenant_id"])
    client = Client(base)
    client.login(account, password)
    receipts = "/api/modules/" + fixture["module_id"] + "/v1/penerimaan-aset"
    created = client.request("POST", receipts, tenant["receipt_body"], {"Idempotency-Key": "conformance-rollback-" + uuid.uuid4().hex})
    observed = [{"probe": "rollback-create", "status": created.status}]
    receipt = created.body["data"]
    if not ID.fullmatch(receipt["id"]):
        raise ValueError("ID receipt tidak sah.")
    state = f"""SELECT json_build_object('status',r.status,'version',r.version,
      'assets',(SELECT count(*) FROM aset_tr_aset a WHERE a.penerimaan_aset_id=r.id),
      'postings',(SELECT count(*) FROM finance_postings p WHERE p.source_id=r.id),
      'asset_numbers',(SELECT count(*) FROM number_sequence_issues i WHERE i.idempotency_key LIKE 'aset:penerimaan:'||r.id||':%'))
      FROM aset_tr_penerimaan_aset r WHERE r.id='{receipt['id']}';"""
    before = json.loads(sql(state, database))
    sql(f"ALTER TABLE finance_postings ADD CONSTRAINT conformance_reject_posting CHECK (posting_id <> 'AST-ACQ-{receipt['id']}') NOT VALID;", database)
    try:
        failed = client.request("POST", receipts + "/" + receipt["id"] + "/selesaikan", {"version": receipt["version"]})
        observed.append({"probe": "rollback-posting-insert-fails", "status": failed.status, "body": failed.body})
        observed.append({"probe": "rollback-no-partial-effect", "before": before, "after": json.loads(sql(state, database))})
    finally:
        sql("ALTER TABLE finance_postings DROP CONSTRAINT conformance_reject_posting;", database)
    retried = client.request("POST", receipts + "/" + receipt["id"] + "/selesaikan", {"version": receipt["version"]})
    assets = client.request("GET", receipts + "/" + receipt["id"] + "/aset")
    observed.append({"probe": "rollback-retry-completes", "status": retried.status, "version": retried.body.get("data", {}).get("version")})
    observed.append({"probe": "rollback-asset-codes", "status": assets.status, "codes": sorted(asset["kode"] for asset in assets.body.get("data", []))})
    return observed


def throttle(base: str) -> list[dict]:
    """Limiter login Fortify: percobaan gagal untuk satu email dan IP dihitung, lalu ditolak dengan petunjuk tunggu."""
    client = Client(base)
    client.request("GET", "/login")
    observed = []
    email = f"throttle-{uuid.uuid4().hex}@stack-comparison.example.invalid"
    for attempt in range(1, 7):
        result = client.request("POST", "/login", {"email": email, "password": "wrong-password"})
        headers = {key.lower(): value for key, value in result.headers.items()}
        body = result.body if isinstance(result.body, dict) else {}
        # Nilai limit/remaining pasti; retry-after dan reset bergantung jam, jadi hanya keberadaannya.
        observed.append({"probe": f"login-throttle-{attempt}", "status": result.status,
                         "limit": headers.get("x-ratelimit-limit"), "remaining": headers.get("x-ratelimit-remaining"),
                         "timed_headers": sorted(header for header in headers if header in {"retry-after", "x-ratelimit-reset"}),
                         "message": re.sub(r"\d+", "#", str(body.get("message"))),
                         "errors": {field: [re.sub(r"\d+", "#", message) for message in messages] for field, messages in body.get("errors", {}).items()}})
    return observed


SECTIONS = ["http", "revocation", "rollback", "throttle"]


def compare(expected: dict, actual: dict) -> list[dict]:
    differences = []
    for section in [name for name in SECTIONS if name in expected and name in actual]:
        left, right = expected[section], actual[section]
        if section == "http":
            left, right = left["events"], right["events"]
        if len(left) != len(right):
            differences.append({"path": section + ".length", "expected": len(left), "actual": len(right)})
        for index, (a, b) in enumerate(zip(left, right)):
            # Pemenang race sudah diwajibkan tepat satu 200; status pihak kalah (409/422) bergantung waktu.
            if a.get("probe") == b.get("probe") == "completion-race":
                continue
            if a != b:
                differences.append({"path": f"{section}[{index}]", "expected": a, "actual": b})
    return differences


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True)
    parser.add_argument("--database", required=True)
    parser.add_argument("--fixture", type=Path, default=LAB / "private" / "fixture.json")
    parser.add_argument("--env", type=Path, default=LAB / "private" / "runtime.env")
    parser.add_argument("--expected", type=Path, help="Hasil conformance PHP sebagai acuan probe yang direkam.")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--sections", nargs="+", choices=SECTIONS, default=SECTIONS)
    args = parser.parse_args()
    validate_database(args.database)
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    password = fixture_password(args.env)
    probes = {"http": lambda: http_probes()(args.base, fixture, password),
              "revocation": lambda: revocation(args.base, fixture, password, args.database),
              "rollback": lambda: rollback(args.base, fixture, password, args.database),
              "throttle": lambda: throttle(args.base)}
    report = {"database": args.database, "base": args.base, **{section: probes[section]() for section in args.sections}}
    if args.expected:
        report["differences"] = compare(json.loads(args.expected.read_text(encoding="utf-8")), report)
        report["verdict"] = "NOT VERIFIED" if report["differences"] else "VERIFIED"
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    counts = {section: len(report[section]["events"] if section == "http" else report[section]) for section in args.sections}
    print(json.dumps({"verdict": report.get("verdict", "RECORDED"), "differences": len(report.get("differences", [])), **counts}))
    if report.get("differences"):
        raise SystemExit(1)
