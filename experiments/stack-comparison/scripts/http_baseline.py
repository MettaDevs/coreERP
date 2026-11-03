"""Login HTTP nyata dan bukti transaksi pada fixture eksperimen terisolasi."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime, timezone
import http.cookiejar
import hashlib
import json
from pathlib import Path
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


@dataclass
class Result:
    status: int
    headers: dict[str, str]
    body: object
    duration_ms: float
    started_at: str
    finished_at: str


class Client:
    def __init__(self, base: str, trace_directory: Path | None = None, trace_database: str = "erp_compare") -> None:
        self.base = base.rstrip("/")
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.trace_directory = trace_directory
        self.trace_sequence = 0
        self.trace_database = trace_database

    def csrf(self) -> str:
        return next((urllib.parse.unquote(cookie.value) for cookie in self.cookies if cookie.name == "XSRF-TOKEN"), "")

    def request(self, method: str, path: str, payload: object = None, headers: dict[str, str] | None = None) -> Result:
        selected = {"Accept": "application/json", "X-Requested-With": "XMLHttpRequest"}
        if payload is not None:
            selected["Content-Type"] = "application/json"
        if method not in {"GET", "HEAD", "OPTIONS"}:
            selected["X-XSRF-TOKEN"] = self.csrf()
        selected.update(headers or {})
        data = None if payload is None else json.dumps(payload, separators=(",", ":")).encode()
        request = urllib.request.Request(self.base + path, data=data, headers=selected, method=method)
        if self.trace_directory is not None:
            from sql_observer import snapshot
            before = snapshot(self.trace_database)
        started_at = datetime.now(timezone.utc).isoformat()
        start = time.perf_counter()
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read()
        elapsed = (time.perf_counter() - start) * 1000
        finished_at = datetime.now(timezone.utc).isoformat()
        if self.trace_directory is not None:
            from sql_observer import capture, difference
            time.sleep(0.1)
            after = snapshot(self.trace_database)
            self.trace_sequence += 1
            label = re.sub(r"[^a-zA-Z0-9_-]+", "-", urllib.parse.urlsplit(path).path).strip("-")
            capture(self.trace_directory / f"{self.trace_sequence:02d}-{method}-{label}.json", difference(before, after), self.trace_database)
        try:
            body = json.loads(raw)
        except (json.JSONDecodeError, UnicodeDecodeError):
            body = {"non_json_bytes": len(raw)}
        return Result(response.status, dict(response.headers.items()), body, elapsed, started_at, finished_at)

    def login(self, account: dict, password: str) -> list[Result]:
        bootstrap = self.request("GET", "/login")
        if bootstrap.status != 200 or not self.csrf():
            raise RuntimeError(f"Login bootstrap gagal, HTTP {bootstrap.status}, csrf_present={bool(self.csrf())}.")
        login = self.request("POST", "/login", {"email": account["email"], "password": password})
        if login.status != 200:
            raise RuntimeError(f"Login gagal, HTTP {login.status}.")
        workspace = self.request("PUT", "/api/v1/workspace-context", {
            "membership_id": account["membership_id"],
            "legal_entity_id": account["legal_entity_id"],
            "org_unit_id": account["department_id"],
        })
        if workspace.status != 200:
            raise RuntimeError(f"Workspace gagal, HTTP {workspace.status}.")
        return [bootstrap, login, workspace]


def fixture_password(path: Path) -> str:
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith("COMPARE_PASSWORD="):
            return line.split("=", 1)[1]
    raise ValueError("COMPARE_PASSWORD tidak ada di env fixture.")


def run_flow(client: Client, fixture: dict, account: dict, run_id: str) -> list[dict]:
    tenant = next(item for item in fixture["tenants"] if item["tenant_id"] == account["tenant_id"])
    prefix = "/api/modules/" + fixture["module_id"] + "/v1"
    document = tenant["receipt_body"]
    events: list[dict] = []

    def execute(operation: str, method: str, path: str, payload: object = None, headers: dict[str, str] | None = None, expected: int = 200) -> Result:
        result = client.request(method, prefix + path, payload, headers)
        events.append({"operation": operation, "status": result.status, "duration_ms": result.duration_ms, "started_at": result.started_at, "finished_at": result.finished_at, "headers": {key: value for key, value in result.headers.items() if key.lower() in {"etag", "idempotent-replayed", "location"}}, "body": result.body})
        if result.status != expected:
            raise RuntimeError(f"{operation} gagal, HTTP {result.status}, expected {expected}.")
        return result

    execute("receipt-list", "GET", "/penerimaan-aset?per_page=40")
    created = execute("receipt-create", "POST", "/penerimaan-aset", document, {"Idempotency-Key": "compare-" + run_id}, 201)
    receipt = created.body["data"]
    receipt_id = receipt["id"]
    shown = execute("receipt-show-draft", "GET", "/penerimaan-aset/" + receipt_id)
    changed = execute("receipt-replace-lines", "PATCH", "/penerimaan-aset/" + receipt_id, {**document, "version": shown.body["data"]["version"]})
    execute("receipt-preview", "GET", "/penerimaan-aset/" + receipt_id + "/pratinjau-posting")
    completed = execute("receipt-complete", "POST", "/penerimaan-aset/" + receipt_id + "/selesaikan", {"version": changed.body["data"]["version"]})
    if completed.body["data"].get("posting", {}).get("status") != "pending":
        raise RuntimeError("Posting bukan pending. Profil bisnis belum memenuhi syarat.")
    execute("receipt-show-completed", "GET", "/penerimaan-aset/" + receipt_id)
    assets = execute("receipt-assets", "GET", "/penerimaan-aset/" + receipt_id + "/aset")
    if len(assets.body["data"]) != 3:
        raise RuntimeError("Jumlah aset tidak sesuai profile tiga unit.")
    execute("asset-list", "GET", "/aset?per_page=40")
    for asset in assets.body["data"]:
        execute("asset-show", "GET", "/aset/" + asset["id"])
    return events


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://127.0.0.1:18641")
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--env", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--account", type=int, default=0)
    parser.add_argument("--trace-directory", type=Path)
    parser.add_argument("--trace-database", default="erp_compare")
    parser.add_argument("--run-id")
    arguments = parser.parse_args()
    fixture = json.loads(arguments.fixture.read_text(encoding="utf-8"))
    profile = json.loads((Path(__file__).resolve().parents[1] / "profile.json").read_text(encoding="utf-8"))
    client = Client(arguments.base, arguments.trace_directory, arguments.trace_database)
    account = fixture["accounts"][arguments.account]
    login = client.login(account, fixture_password(arguments.env))
    run_id = arguments.run_id or uuid.uuid4().hex
    events = run_flow(client, fixture, account, run_id)
    arguments.output.parent.mkdir(parents=True, exist_ok=True)
    metadata = {
        "source_sha": fixture["source_sha"], "profile_id": profile["id"], "fixture_id": fixture["fixture_id"],
        "fixture_hash": hashlib.sha256(json.dumps(fixture, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
        "run_id": run_id,
        "authentication": [{"status": result.status, "duration_ms": result.duration_ms} for result in login],
        "events": events,
    }
    arguments.output.write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"operations": len(events), "statuses": [event["status"] for event in events], "capacity_claim": False}))
