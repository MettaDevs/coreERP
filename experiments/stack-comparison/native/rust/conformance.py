"""Probe HTTP kandidat Rust pada fixture yang sudah disiapkan. Tidak menjalankan PHP."""

from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from copy import copy, deepcopy
import importlib.util
import json
from pathlib import Path
import sys
import uuid


def load_harness(path: Path):
    spec = importlib.util.spec_from_file_location("comparison_http", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://127.0.0.1:18643")
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--env", type=Path, required=True)
    parser.add_argument("--harness", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    harness = load_harness(args.harness)
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    password = harness.fixture_password(args.env)
    checks: list[dict] = []
    prefix = "/api/modules/" + fixture["module_id"] + "/v1"

    def expect(label: str, response, status: int) -> None:
        checks.append({"case": label, "status": response.status, "expected": status})
        if response.status != status:
            raise AssertionError(f"{label}: expected {status}, received {response.status}: {response.body}")

    anonymous = harness.Client(args.base)
    expect("anonymous-read", anonymous.request("GET", prefix + "/penerimaan-aset"), 401)
    bootstrap = anonymous.request("GET", "/login")
    expect("ordinary-html-bootstrap", bootstrap, 200)
    assert "non_json_bytes" in bootstrap.body and anonymous.csrf()
    expect("inertia-version-required", anonymous.request("GET", "/login", headers={"X-Inertia": "true"}), 409)
    expect("csrf-required", anonymous.request("POST", "/login", {}, {"X-XSRF-TOKEN": ""}), 419)
    account = fixture["accounts"][0]
    expect("wrong-password", anonymous.request("POST", "/login", {"email": account["email"], "password": "incorrect"}), 422)
    application = next(item for item in fixture["probe_accounts"] if item["kind"] == "application")
    expect("application-account-denied", anonymous.request("POST", "/login", {"email": application["email"], "password": password}), 422)

    client = harness.Client(args.base)
    client.login(account, password)
    events = harness.run_flow(client, fixture, account, uuid.uuid4().hex)
    receipt = events[5]["body"]["data"]
    receipt_id = receipt["id"]
    assert receipt["version"] == 5
    expect("complete-repeat", client.request("POST", prefix + "/penerimaan-aset/" + receipt_id + "/selesaikan", {"version": receipt["version"]}), 422)
    tenant = next(item for item in fixture["tenants"] if item["tenant_id"] == account["tenant_id"])
    document = tenant["receipt_body"]
    key = "rust-conformance-" + uuid.uuid4().hex
    draft = client.request("POST", prefix + "/penerimaan-aset", document, {"Idempotency-Key": key})
    expect("draft-create", draft, 201)
    changed = client.request("POST", prefix + "/penerimaan-aset", {"details": []}, {"Idempotency-Key": key})
    expect("changed-body-creation-replay", changed, 200)
    assert changed.body["data"]["id"] == draft.body["data"]["id"] and "details" not in changed.body["data"]
    draft_id = draft.body["data"]["id"]
    path = prefix + "/penerimaan-aset/" + draft_id
    expect("version-required", client.request("PATCH", path, document), 428)
    expect("stale-version", client.request("PATCH", path, {**document, "version": 0}), 409)
    expect("if-match-precedence", client.request("PATCH", path, {**document, "version": 0}, {"If-Match": 'W/"1"'}), 200)
    missing_attribute = deepcopy(document)
    missing_attribute["details"][0]["atribut"] = []
    expect("required-attribute", client.request("POST", prefix + "/penerimaan-aset", missing_attribute, {"Idempotency-Key": uuid.uuid4().hex}), 422)
    invalid_precision = deepcopy(document)
    invalid_precision["details"][0]["nilai_per_unit"] = "333333.3331"
    expect("currency-unit-precision", client.request("POST", prefix + "/penerimaan-aset", invalid_precision, {"Idempotency-Key": uuid.uuid4().hex}), 422)
    expect("cursor-malformed", client.request("GET", prefix + "/aset?cursor=not-a-cursor"), 422)
    first = client.request("GET", prefix + "/aset?per_page=1&sort=kode")
    expect("first-page", first, 200)
    assert len(first.body["data"]) == 1 and first.body["next_cursor"]
    second = client.request("GET", prefix + "/aset?per_page=1&sort=kode&cursor=" + first.body["next_cursor"])
    expect("cursor-next-page", second, 200)
    assert first.body["data"][0]["id"] != second.body["data"][0]["id"]
    expect("punctuation-query", client.request("GET", prefix + "/aset?q=%21%21%21"), 200)
    assert client.request("GET", prefix + "/aset?q=%21%21%21").body["data"] == []
    for kind, expected in [("no_asset_create", 403), ("outside_scope", 404)]:
        probe = next(item for item in fixture["probe_accounts"] if item["kind"] == kind and item["tenant_id"] == account["tenant_id"])
        denied = harness.Client(args.base)
        denied.login(probe, password)
        expect(kind, denied.request("POST", path + "/selesaikan", {"version": 3}), expected)
    foreign = next(item for item in fixture["accounts"] if item["tenant_id"] != account["tenant_id"])
    other = harness.Client(args.base)
    other.login(foreign, password)
    expect("cross-tenant-show", other.request("GET", prefix + "/penerimaan-aset/" + receipt_id), 404)
    expect("foreign-workspace", client.request("PUT", "/api/v1/workspace-context", {"membership_id": foreign["membership_id"], "legal_entity_id": foreign["legal_entity_id"], "org_unit_id": foreign["department_id"]}), 422)
    other_tenant_body = next(item for item in fixture["tenants"] if item["tenant_id"] == foreign["tenant_id"])["receipt_body"]
    expect("cross-company-write", client.request("POST", prefix + "/penerimaan-aset", other_tenant_body, {"Idempotency-Key": uuid.uuid4().hex}), 422)

    race = client.request("POST", prefix + "/penerimaan-aset", document, {"Idempotency-Key": uuid.uuid4().hex})
    expect("race-draft", race, 201)
    race_id = race.body["data"]["id"]
    def complete_race(_):
        contender = harness.Client(args.base)
        for cookie in client.cookies:
            contender.cookies.set_cookie(copy(cookie))
        return contender.request("POST", prefix + "/penerimaan-aset/" + race_id + "/selesaikan", {"version": race.body["data"]["version"]})
    with ThreadPoolExecutor(max_workers=2) as pool:
        raced = list(pool.map(complete_race, range(2)))
    statuses = sorted(response.status for response in raced)
    assert statuses in [[200, 409], [200, 422]], statuses
    checks.append({"case": "completion-race", "statuses": statuses})
    assert len(client.request("GET", prefix + "/penerimaan-aset/" + race_id + "/aset").body["data"]) == 3

    tampered = harness.Client(args.base)
    for cookie in client.cookies:
        tampered.cookies.set_cookie(copy(cookie))
    for cookie in tampered.cookies:
        if cookie.name != "XSRF-TOKEN":
            cookie.value = "tampered"
    expect("tampered-cookie", tampered.request("GET", prefix + "/penerimaan-aset"), 401)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps({"runtime": "rust", "checks": checks, "events": events, "capacity_claim": False}, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"runtime": "rust", "checks": len(checks), "main_operations": len(events), "status": "verified", "capacity_claim": False}))


if __name__ == "__main__":
    main()
