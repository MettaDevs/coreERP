"""Probe HTTP dan pencabutan akses pada database pengembangan kandidat saja."""

from __future__ import annotations

import argparse
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import re
import sys
import uuid


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Modul harness tidak tersedia.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


def identifier(value: str) -> str:
    if not re.fullmatch(r"[0-9A-HJKMNP-TV-Z]{26}", value, re.IGNORECASE):
        raise ValueError("ID fixture bukan ULID.")
    return "'" + value + "'"


def run(args) -> dict:
    if args.database != "erp_compare_ts":
        raise ValueError("Probe mutasi akses hanya boleh memakai erp_compare_ts.")
    http = load("native_http_baseline", args.harness / "http_baseline.py")
    observer = load("native_sql_observer", args.harness / "sql_observer.py")
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    password = http.fixture_password(args.env)
    account = fixture["accounts"][0]
    tenant = next(item for item in fixture["tenants"] if item["tenant_id"] == account["tenant_id"])
    prefix = "/api/modules/" + fixture["module_id"] + "/v1"
    checks: list[dict] = []

    def check(name: str, actual, expected) -> None:
        checks.append({"case": name, "passed": actual == expected})
        if actual != expected:
            raise AssertionError(f"{name}: expected {expected!r}, actual {actual!r}")

    def query(statement: str):
        return observer.sql(statement, args.database)

    anonymous = http.Client(args.base)
    check("anonymous", anonymous.request("GET", prefix + "/penerimaan-aset").status, 401)
    check("malformed-cookie-uri", anonymous.request("GET", prefix + "/penerimaan-aset", headers={"Cookie": "laravel-session=%broken"}).status, 401)
    client = http.Client(args.base)
    client.request("GET", "/login")
    missing = client.request("PUT", "/api/v1/workspace-context", {"membership_id": account["membership_id"]}, {"X-XSRF-TOKEN": ""})
    check("csrf-missing", missing.status, 419)
    check("csrf-invalid", client.request("POST", "/login", {"email": account["email"], "password": password}, {"X-XSRF-TOKEN": "broken"}).status, 419)
    unknown = {"email": "native-probe-" + uuid.uuid4().hex + "@comparison.invalid", "password": password}
    for attempt in range(6):
        check("login-limiter-" + str(attempt + 1), client.request("POST", "/login", unknown).status, 422 if attempt < 5 else 429)
    application = next(item for item in fixture["probe_accounts"] if item["kind"] == "application")
    check("application-principal-denied", client.request("POST", "/login", {"email": application["email"], "password": password}).status, 422)
    client.login(account, password)
    check("invalid-cursor", client.request("GET", prefix + "/penerimaan-aset?cursor=broken").status, 422)
    punctuation = client.request("GET", prefix + "/penerimaan-aset?q=...---")
    check("punctuation-search-status", punctuation.status, 200)
    check("punctuation-search-empty", punctuation.body.get("data"), [])
    first = client.request("GET", prefix + "/aset?per_page=1&sort=nilai&direction=asc")
    check("numeric-cursor-first-status", first.status, 200)
    cursor = first.body.get("next_cursor")
    if not isinstance(cursor, str):
        raise AssertionError("Fixture belum memiliki dua aset untuk probe cursor.")
    second = client.request("GET", prefix + "/aset?per_page=1&sort=nilai&direction=asc&cursor=" + cursor)
    check("numeric-cursor-second-status", second.status, 200)
    check("numeric-cursor-no-duplicate", first.body["data"][0]["id"] != second.body["data"][0]["id"], True)

    body = deepcopy(tenant["receipt_body"])
    body["details"][0]["atribut"] = []
    count_before = query("select count(*) from number_sequence_issues;").strip()
    check("required-attribute", client.request("POST", prefix + "/penerimaan-aset", body, {"Idempotency-Key": "native-invalid-" + uuid.uuid4().hex}).status, 422)
    check("invalid-create-no-number", query("select count(*) from number_sequence_issues;").strip(), count_before)
    receipts = client.request("GET", prefix + "/penerimaan-aset?status=selesai&per_page=1")
    receipt = receipts.body["data"][0]
    replay = client.request("POST", prefix + "/penerimaan-aset", {"changed": True}, {"Idempotency-Key": receipt["creation_key"]})
    check("changed-body-create-replay", replay.status, 200)
    check("replay-raw-header", "details" in replay.body["data"], False)
    check("complete-repeat", client.request("POST", prefix + "/penerimaan-aset/" + receipt["id"] + "/selesaikan", {"version": receipt["version"]}).status, 422)

    membership_id = identifier(account["membership_id"])
    query("update tenant_memberships set status='inactive' where id=" + membership_id + ";")
    try:
        check("membership-revocation-same-cookie", client.request("GET", prefix + "/penerimaan-aset").status, 403)
    finally:
        query("update tenant_memberships set status='active' where id=" + membership_id + ";")
    check("membership-restored", client.request("GET", prefix + "/penerimaan-aset").status, 200)
    roles = json.loads(query("select coalesce(json_agg(role_id),'[]'::json) from role_assignments where membership_id=" + membership_id + " and status='active';"))
    if not roles:
        raise AssertionError("Fixture tidak memiliki role aktif.")
    role_ids = ",".join(identifier(role) for role in roles)
    query("update roles set is_active=false where id in (" + role_ids + ");")
    try:
        check("role-revocation-same-cookie", client.request("GET", prefix + "/penerimaan-aset").status, 403)
    finally:
        query("update roles set is_active=true where id in (" + role_ids + ");")
    check("role-restored", client.request("GET", prefix + "/penerimaan-aset").status, 200)
    foreign_account = next(item for item in fixture["accounts"] if item["tenant_id"] != account["tenant_id"])
    foreign = http.Client(args.base)
    foreign.login(foreign_account, password)
    check("foreign-tenant-receipt", foreign.request("GET", prefix + "/penerimaan-aset/" + receipt["id"]).status, 404)
    query("delete from sessions where user_id=" + str(int(account["user_id"])) + ";")
    check("deleted-session", client.request("GET", prefix + "/penerimaan-aset").status, 401)
    return {"database": args.database, "checks": checks, "passed": all(item["passed"] for item in checks), "capacity_claim": False}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://127.0.0.1:18644")
    parser.add_argument("--harness", required=True, type=Path)
    parser.add_argument("--fixture", required=True, type=Path)
    parser.add_argument("--env", required=True, type=Path)
    parser.add_argument("--database", default="erp_compare_ts")
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    result = run(args)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"checks": len(result["checks"]), "passed": result["passed"], "capacity_claim": False}))
