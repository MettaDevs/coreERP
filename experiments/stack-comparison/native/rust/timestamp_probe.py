"""Periksa konsistensi timestamp snapshot ketika publication menunggu lock database."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import uuid

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
    parser.add_argument("--expect-mismatch", action="store_true")
    args = parser.parse_args()
    if re.fullmatch(r"erp_compare_rust(?:_[a-z0-9_]+)?", args.database) is None:
        raise ValueError("Probe hanya boleh menulis clone pengembangan Rust.")
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    harness = load_harness(args.harness)
    account = fixture["accounts"][0]
    tenant = next(item for item in fixture["tenants"] if item["tenant_id"] == account["tenant_id"])
    client = harness.Client(args.base)
    client.login(account, harness.fixture_password(args.env))
    path = "/api/modules/" + fixture["module_id"] + "/v1/penerimaan-aset"
    draft = client.request("POST", path, tenant["receipt_body"], {"Idempotency-Key": "timestamp-" + uuid.uuid4().hex})
    assert draft.status == 201, draft.body
    receipt = draft.body["data"]
    receipt_id = receipt["id"]
    assert re.fullmatch(r"[A-Za-z0-9]{26}", receipt_id)
    command = ["docker", "exec", "-i", args.container, "psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-U", "compare_admin", "-d", args.database]
    lock = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    lock.stdin.write("BEGIN; LOCK TABLE finance_reference_accounts IN ACCESS EXCLUSIVE MODE; SELECT 'lock_ready'; SELECT pg_sleep(2.2); COMMIT;\n")
    lock.stdin.close()
    lock.stdin = None
    assert lock.stdout.readline().strip() == "lock_ready"
    completed = client.request("POST", path + "/" + receipt_id + "/selesaikan", {"version": receipt["version"]})
    _, error = lock.communicate(timeout=10)
    assert lock.returncode == 0, error
    assert completed.status == 200, completed.body
    query = f"""
SELECT json_build_object(
 'occurred_matches', occurred_at=(payload->>'occurred_at')::timestamptz AT TIME ZONE 'UTC',
 'published_matches', published_at=(payload->>'published_at')::timestamptz AT TIME ZONE 'UTC',
 'occurred_at', occurred_at, 'input_occurred_at', input->>'occurred_at',
 'published_at', published_at, 'payload_published_at', payload->>'published_at')
FROM finance_postings WHERE posting_id='AST-ACQ-{receipt_id}';
"""
    result = subprocess.run(command, input=query, capture_output=True, text=True, check=True)
    evidence = json.loads(result.stdout)
    matches = evidence["occurred_matches"] and evidence["published_matches"]
    assert matches != args.expect_mismatch, evidence
    evidence["expected_mismatch"] = args.expect_mismatch
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"timestamp_snapshot_matches": matches, "expected_mismatch": args.expect_mismatch}))


if __name__ == "__main__":
    main()
