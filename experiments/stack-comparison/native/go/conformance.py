"""Probe HTTP native pada fixture sintetis, tanpa helper domain kandidat atau PHP."""

from __future__ import annotations

import argparse
import concurrent.futures
import copy
import http.cookiejar
import json
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request
import uuid


class Client:
    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, method: str, path: str, body=None, headers=None):
        selected = {"Accept": "application/json", "Content-Type": "application/json"}
        if method not in {"GET", "HEAD", "OPTIONS"}:
            selected["X-XSRF-TOKEN"] = next((urllib.parse.unquote(c.value) for c in self.cookies if c.name == "XSRF-TOKEN"), "")
        selected.update(headers or {})
        request = urllib.request.Request(self.base + path, data=None if body is None else json.dumps(body).encode(), headers=selected, method=method)
        try:
            result = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            result = error
        raw = result.read()
        try:
            content = json.loads(raw)
        except json.JSONDecodeError:
            content = {"non_json_bytes": len(raw)}
        return result.status, content, dict(result.headers)

    def login(self, account: dict, password: str, workspace=True):
        assert self.request("GET", "/login")[0] == 200
        assert self.request("POST", "/login", {"email": account["email"], "password": password})[0] == 200
        if workspace:
            assert self.request("PUT", "/api/v1/workspace-context", {"membership_id": account["membership_id"], "legal_entity_id": account["legal_entity_id"], "org_unit_id": account["department_id"]})[0] == 200


def run(base: str, fixture: dict, password: str) -> dict:
    prefix = "/api/modules/" + fixture["module_id"] + "/v1"
    receipts = prefix + "/penerimaan-aset"
    owner = fixture["accounts"][0]
    tenant = next(t for t in fixture["tenants"] if t["tenant_id"] == owner["tenant_id"])
    document = tenant["receipt_body"]
    events = []

    def check(name, actual, expected):
        assert actual[0] == expected, (name, actual[0], actual[1])
        events.append({"probe": name, "status": actual[0]})
        return actual

    anonymous = Client(base)
    check("anonymous", anonymous.request("GET", receipts), 401)
    check("login-bootstrap", anonymous.request("GET", "/login"), 200)
    check("csrf-missing", anonymous.request("POST", "/login", {"email": owner["email"], "password": password}, {"X-XSRF-TOKEN": ""}), 419)
    check("csrf-wrong", anonymous.request("POST", "/login", {"email": owner["email"], "password": password}, {"X-XSRF-TOKEN": "wrong"}), 419)
    client = Client(base)
    client.login(owner, password)
    key = "go-conformance-" + uuid.uuid4().hex
    created = check("create", client.request("POST", receipts, document, {"Idempotency-Key": key}), 201)[1]["data"]
    path = receipts + "/" + created["id"]
    replay = check("changed-body-replay", client.request("POST", receipts, {"unexpected": True}, {"Idempotency-Key": key}), 200)
    assert replay[1]["data"]["id"] == created["id"] and "details" not in replay[1]["data"]
    assert {key.lower(): value for key, value in replay[2].items()}.get("idempotent-replayed") == "true"
    check("missing-version", client.request("POST", path + "/selesaikan", {}), 428)
    check("bad-version-header", client.request("POST", path + "/selesaikan", {"version": created["version"]}, {"If-Match": "wrong"}), 428)
    check("stale-zero-version", client.request("POST", path + "/selesaikan", {"version": 0}), 409)
    denied = next(a for a in fixture["probe_accounts"] if a["tenant_id"] == owner["tenant_id"] and a["kind"] == "no_asset_create")
    no_create = Client(base)
    no_create.login(denied, password)
    check("asset-create-required", no_create.request("POST", path + "/selesaikan", {"version": created["version"]}), 403)
    outside = next(a for a in fixture["probe_accounts"] if a["tenant_id"] == owner["tenant_id"] and a["kind"] == "outside_scope")
    outside_client = Client(base)
    outside_client.login(outside, password, workspace=False)
    check("unit-scope-show", outside_client.request("GET", path), 404)
    check("unit-scope-create", outside_client.request("POST", receipts, document, {"Idempotency-Key": key + "-outside"}), 403)
    foreign = next(a for a in fixture["accounts"] if a["tenant_id"] != owner["tenant_id"])
    foreign_client = Client(base)
    foreign_client.login(foreign, password)
    check("tenant-show", foreign_client.request("GET", path), 404)
    check("tenant-body-cannot-grant-access", foreign_client.request("POST", receipts, {**document, "tenant_id": owner["tenant_id"]}, {"Idempotency-Key": key + "-foreign"}), 422)
    invalid = copy.deepcopy(document)
    invalid["details"][0]["atribut"] = []
    check("required-attribute", client.request("POST", receipts, invalid, {"Idempotency-Key": key + "-attribute"}), 422)
    invalid = copy.deepcopy(document)
    invalid["details"][0]["nilai_per_unit"] = "333333.3331"
    check("unit-precision", client.request("POST", receipts, invalid, {"Idempotency-Key": key + "-precision"}), 422)
    updated = check("patch", client.request("PATCH", path, {**document, "version": created["version"]}), 200)[1]["data"]
    assert updated["version"] == created["version"] + 2
    check("patch-stale", client.request("PATCH", path, {**document, "version": created["version"]}), 409)
    workers = [Client(base) for _ in range(3)]
    worker_accounts = [a for a in fixture["accounts"] if a["tenant_id"] == owner["tenant_id"] and a["user_index"] != 1]
    for worker, account in zip(workers, worker_accounts):
        worker.login(account, password)
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as executor:
        results = list(executor.map(lambda c: c.request("POST", path + "/selesaikan", {"version": updated["version"]}), workers))
    statuses = sorted(r[0] for r in results)
    assert statuses.count(200) == 1 and all(status in {200, 409, 422} for status in statuses), statuses
    events.append({"probe": "completion-race", "statuses": statuses})
    check("repeat-completion", client.request("POST", path + "/selesaikan", {"version": updated["version"]}), 422)
    generated = check("generated-assets", client.request("GET", path + "/aset"), 200)[1]["data"]
    assert len(generated) == sum(d["jumlah"] for d in document["details"])
    codes = [a["kode"] for a in generated]
    assert len(codes) == len(set(codes))
    for asset in generated:
        check("asset-show", client.request("GET", prefix + "/aset/" + asset["id"]), 200)
    first = check("cursor-page-one", client.request("GET", receipts + "?per_page=1&sort=kode"), 200)[1]
    assert first["next_cursor"]
    second = check("cursor-page-two", client.request("GET", receipts + "?per_page=1&sort=kode&cursor=" + urllib.parse.quote(first["next_cursor"])), 200)[1]
    assert first["data"][0]["id"] != second["data"][0]["id"]
    check("bad-cursor", client.request("GET", receipts + "?cursor=invalid"), 422)
    punctuation = check("punctuation-search", client.request("GET", receipts + "?q=..."), 200)[1]
    assert punctuation["data"] == []
    phrase = urllib.parse.quote('"' + document["details"][0]["nama"] + '"')
    assets = check("phrase-search", client.request("GET", prefix + "/aset?q=" + phrase), 200)[1]
    assert all(a["nama"] == document["details"][0]["nama"] for a in assets["data"]) and assets["data"]
    saved_cookies = [copy.copy(cookie) for cookie in client.cookies]
    check("logout", client.request("POST", "/logout", {}), 204)
    old_client = Client(base)
    for cookie in saved_cookies:
        old_client.cookies.set_cookie(cookie)
    check("deleted-session", old_client.request("GET", receipts), 401)
    return {"verdict": "PASS", "profile": fixture["profile"], "receipt_id": created["id"], "events": events, "boundary": "HTTP only. The independent SQL oracle must verify persisted effects and rollback."}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://127.0.0.1:18642")
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--env", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    password = next(line.split("=", 1)[1] for line in args.env.read_text(encoding="utf-8").splitlines() if line.startswith("COMPARE_PASSWORD="))
    report = run(args.base, json.loads(args.fixture.read_text(encoding="utf-8")), password)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"verdict": report["verdict"], "probes": len(report["events"]), "capacity_claim": False}))
