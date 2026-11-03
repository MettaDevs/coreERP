"""Buktikan pencabutan akses pada database Rust terisolasi, lalu pulihkan kolom akses."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess

from conformance import load_harness


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://127.0.0.1:18643")
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--env", type=Path, required=True)
    parser.add_argument("--harness", type=Path, required=True)
    parser.add_argument("--database", default="erp_compare_rust")
    parser.add_argument("--container", default="coreerp-stack-comparison-database-1")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if re.fullmatch(r"erp_compare_rust(?:_[a-z0-9_]+)?", args.database) is None:
        raise ValueError("Probe hanya boleh mengubah clone database Rust eksperimen.")
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    account = fixture["accounts"][3]
    membership = account["membership_id"]
    if re.fullmatch(r"[A-Za-z0-9]{26}", membership) is None:
        raise ValueError("ID fixture tidak sah.")
    user_id = int(account["user_id"])
    harness = load_harness(args.harness)
    password = harness.fixture_password(args.env)
    client = harness.Client(args.base)
    client.login(account, password)
    path = "/api/modules/" + fixture["module_id"] + "/v1/penerimaan-aset"
    checks: list[dict] = []

    def sql(statement: str) -> str:
        result = subprocess.run(["docker", "exec", "-i", args.container, "psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-U", "compare_admin", "-d", args.database], input=statement, text=True, capture_output=True, check=True)
        return result.stdout.strip()

    def expect(label: str, status: int, empty: bool = False) -> None:
        result = client.request("GET", path)
        if result.status != status or (empty and result.body.get("data") != []):
            raise AssertionError(f"{label}: expected HTTP {status}, got {result.status}")
        checks.append({"case": label, "status": result.status})

    def literal(value) -> str:
        return "null" if value is None else "'" + str(value).replace("'", "''") + "'"

    expect("active-session", 200)
    assignment_backup = json.loads(sql(f"select coalesce(json_agg(row_to_json(a)),'[]') from (select id,status,valid_until from role_assignments where membership_id='{membership}') a;"))
    try:
        sql(f"update role_assignments set status='inactive' where membership_id='{membership}';")
        expect("assignment-revoked-live-session", 403)
    finally:
        for row in assignment_backup:
            sql(f"update role_assignments set status={literal(row['status'])},valid_until={literal(row['valid_until'])} where id={literal(row['id'])};")
    expect("assignment-restored", 200)
    try:
        sql(f"update role_assignments set valid_until=now()-interval '1 minute' where membership_id='{membership}';")
        expect("assignment-expired-live-session", 403)
    finally:
        for row in assignment_backup:
            sql(f"update role_assignments set valid_until={literal(row['valid_until'])} where id={literal(row['id'])};")
    role_backup = json.loads(sql(f"select coalesce(json_agg(row_to_json(r)),'[]') from (select distinct r.id,r.is_active from roles r join role_assignments a on a.role_id=r.id where a.membership_id='{membership}') r;"))
    try:
        sql(f"update roles set is_active=false where id in (select role_id from role_assignments where membership_id='{membership}');")
        expect("role-disabled-live-session", 403)
    finally:
        for row in role_backup:
            sql(f"update roles set is_active={'true' if row['is_active'] else 'false'} where id={literal(row['id'])};")
    scope_backup = json.loads(sql(f"select coalesce(json_agg(row_to_json(s)),'[]') from (select s.id,s.valid_until from role_assignment_data_policy_scopes s join role_assignments a on a.id=s.role_assignment_id where a.membership_id='{membership}' and s.policy_code='management-aset.asset-responsibility') s;"))
    try:
        sql(f"update role_assignment_data_policy_scopes set valid_until=now()-interval '1 minute' where role_assignment_id in (select id from role_assignments where membership_id='{membership}') and policy_code='management-aset.asset-responsibility';")
        expect("data-scope-expired-live-session", 200, empty=True)
    finally:
        for row in scope_backup:
            sql(f"update role_assignment_data_policy_scopes set valid_until={literal(row['valid_until'])} where id={literal(row['id'])};")
    membership_status = json.loads(sql(f"select to_json(status) from tenant_memberships where id='{membership}';"))
    try:
        sql(f"update tenant_memberships set status='inactive' where id='{membership}';")
        expect("membership-revoked-live-session", 403)
    finally:
        sql(f"update tenant_memberships set status={literal(membership_status)} where id='{membership}';")
    expect("membership-restored", 200)
    sql(f"update sessions set last_activity=extract(epoch from now())::integer-7201 where user_id={user_id};")
    expect("database-session-expired", 401)
    client.login(account, password)
    sql(f"delete from sessions where user_id={user_id};")
    expect("database-session-deleted", 401)
    client.login(account, password)
    expect("new-session-after-revocation", 200)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps({"database": args.database, "checks": checks, "status": "verified"}, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"database": args.database, "checks": len(checks), "status": "verified"}))


if __name__ == "__main__":
    main()
