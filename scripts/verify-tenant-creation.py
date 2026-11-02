"""Periksa PF01 melalui HTTP nyata dan SELECT PostgreSQL pada fixture Docker khusus.

Runtime harus sudah berjalan. Script tidak membuat server, migration, atau container.
Credential dari env dan stdout setup hanya berada dalam memori proses ini.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import datetime as dt
import hashlib
import json
import math
import pathlib
import re
import statistics
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass, field
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[1]
OPERATOR_PATH = "/api/internal/v1/tenant-registrations"
CUSTOMER_PATH = "/api/internal/v1/product-registration/organizations"
APP_CATALOG_PATH = "/api/v1/control/apps"
ERP_PATH = "/api/internal/v1/tenants"
ERP_TABLES = (
    "organizations", "legal_entities", "operating_units", "tenant_app_entitlements",
    "environments", "environment_members", "roles", "role_assignments",
    "units_of_measure", "uom_classes", "uom_systems", "uom_translations", "uom_conversions", "uom_external_codes",
    "tenant_number_sequences", "number_sequence_profiles", "number_sequence_counters",
    "number_sequence_allocations", "number_sequence_reservations", "number_sequence_reusable_numbers",
    "number_sequence_issues", "number_sequence_audit_events", "number_sequence_continuous_pool",
    "core_module_installations", "managed_product_tenants",
)
RESULT_FIELDS = ("tenant_id", "membership_id", "principal_id", "replayed")


class VerificationFailure(Exception):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationFailure(message)


def read_environment(path: pathlib.Path) -> dict[str, str]:
    values = {}
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        key, separator, value = line.removeprefix("export ").partition("=")
        require(bool(separator), "The fixture env file contains an invalid assignment.")
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        values[key.strip()] = value
    require(values.get("APP_ENV") == "testing", "APP_ENV must be testing.")
    require(values.get("DB_CONNECTION") == "pgsql_test", "DB_CONNECTION must be pgsql_test.")
    require(
        re.fullmatch(r"core_erp_pf01_test(?:_[a-z0-9_]+)?", values.get("DB_TEST_DATABASE", "")) is not None,
        "DB_TEST_DATABASE must use the dedicated PF01 test prefix.",
    )
    require(values.get("DB_TEST_HOST") == "database", "DB_TEST_HOST must be the compose database service.")
    for name in ("COREERP_CONTROL_PLANE_TOKEN", "MANAGED_REGISTRATION_TOKEN"):
        require(len(values.get(name, "")) >= 32, "A required fixture bearer token is missing or too short.")
    return values


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req: Any, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> None:
        return None


@dataclass(frozen=True)
class Reply:
    label: str
    status: int
    elapsed_ms: float
    result: dict[str, Any]
    private_body: dict[str, Any] = field(repr=False)

    def evidence(self) -> dict[str, Any]:
        return {"label": self.label, "status": self.status, "elapsed_ms": round(self.elapsed_ms, 3), "result": self.result}


class Fixture:
    def __init__(self, args: argparse.Namespace, environment: dict[str, str]):
        self.args = args
        self.environment = environment
        self.run_id = uuid.uuid4().hex
        self.prefix = "pf01-" + self.run_id[:12]
        self.context: dict[str, Any] = {}
        self.replies: list[Reply] = []
        self.reply_lock = threading.Lock()

    def compose_command(self) -> list[str]:
        return [
            "docker", "compose", "--env-file", str(self.args.env_file),
            "--file", str(self.args.compose_file), "--project-name", self.args.project,
        ]

    def verify_target(self) -> None:
        process = subprocess.run(
            self.compose_command() + ["port", self.args.service, "8080"],
            text=True, capture_output=True, timeout=20,
        )
        require(process.returncode == 0, "The fixture service port could not be verified.")
        actual = urllib.parse.urlsplit("http://" + process.stdout.strip())
        expected = urllib.parse.urlsplit(self.args.base_url)
        same_host = expected.hostname == actual.hostname or expected.hostname == "localhost" and actual.hostname in ("127.0.0.1", "::1")
        require(actual.hostname in ("127.0.0.1", "::1") and same_host and actual.port == expected.port,
                "The HTTP URL does not match the selected guarded Compose service.")
        self.args.base_url = "http://" + actual.netloc

    def helper(self, verb: str, extra: dict[str, Any] | None = None) -> dict[str, Any]:
        command = self.compose_command() + [
            "exec", "-T", self.args.service, "php", "/repo/scripts/tenant-creation-fixture.php", verb,
        ]
        process = subprocess.run(
            command, input=json.dumps({"run_id": self.run_id, **(extra or {})}), text=True,
            capture_output=True, encoding="utf-8", timeout=120,
        )
        require(process.returncode == 0, f"Fixture {verb} failed with exit {process.returncode}. External output was withheld.")
        try:
            result = json.loads(process.stdout)
        except (ValueError, TypeError):
            raise VerificationFailure(f"Fixture {verb} did not return valid JSON. External output was withheld.") from None
        require(isinstance(result, dict), f"Fixture {verb} did not return an object.")
        return result

    def post(self, label: str, payload: dict[str, Any], actor: str = "existing", realm: str = "customer", path: str | None = None, method: str = "POST") -> Reply:
        path = path or (OPERATOR_PATH if realm in ("operator", "customer_at_operator") else CUSTOMER_PATH)
        token_name = "COREERP_CONTROL_PLANE_TOKEN" if realm == "operator" else "MANAGED_REGISTRATION_TOKEN"
        headers = {"Authorization": "Bearer " + self.environment[token_name], "Accept": "application/json", "Content-Type": "application/json"}
        if realm != "operator":
            headers["X-Registration-Session"] = self.context["sessions"][actor]
        request = urllib.request.Request(self.args.base_url + path, data=json.dumps(payload).encode() if method == "POST" else None, headers=headers, method=method)
        started = time.perf_counter()
        status = 0
        body = b""
        try:
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
            with opener.open(request, timeout=self.args.timeout) as response:
                status, body = response.status, response.read(1_048_576)
        except urllib.error.HTTPError as failure:
            status, body = failure.code, failure.read(1_048_576)
        except (urllib.error.URLError, TimeoutError, OSError):
            pass
        elapsed = (time.perf_counter() - started) * 1000
        try:
            data = json.loads(body)
        except (ValueError, UnicodeDecodeError):
            data = {}
        result = {key: data[key] for key in RESULT_FIELDS if key in data} if isinstance(data, dict) else {}
        if path == APP_CATALOG_PATH and isinstance(data, dict) and isinstance(data.get("data"), list):
            result = {"app_ids": [row["id"] for row in data["data"] if isinstance(row, dict) and isinstance(row.get("id"), str)]}
        reply = Reply(label, status, elapsed, result, data if isinstance(data, dict) else {})
        with self.reply_lock:
            self.replies.append(reply)
        return reply

    def payload(self, label: str) -> dict[str, Any]:
        return {"name": self.prefix + "-" + label, "slug": self.prefix + "-" + label.replace("_", "-"), "idempotency_key": str(uuid.uuid4())}

    def concurrent(self, requests: list[tuple[str, dict[str, Any], str]], realm: str = "customer") -> list[Reply]:
        barrier = threading.Barrier(len(requests))

        def send(item: tuple[str, dict[str, Any], str]) -> Reply:
            barrier.wait(timeout=10)
            return self.post(item[0], item[1], item[2], realm=realm)

        with concurrent.futures.ThreadPoolExecutor(max_workers=len(requests)) as pool:
            return list(pool.map(send, requests))


def created(reply: Reply, expected_status: int = 201) -> dict[str, Any]:
    require(reply.status == expected_status, f"{reply.label} returned HTTP {reply.status}; expected {expected_status}.")
    require(set(reply.result) == set(RESULT_FIELDS), f"{reply.label} returned an incomplete creation result.")
    require(type(reply.result["principal_id"]) is int, f"{reply.label} returned an invalid principal ID.")
    for key in ("tenant_id", "membership_id"):
        require(isinstance(reply.result[key], str) and re.fullmatch(r"[0-9A-HJKMNP-TV-Z]{26}", reply.result[key], re.IGNORECASE | re.ASCII) is not None, f"{reply.label} returned an invalid {key}.")
    require(reply.result["replayed"] is (expected_status == 200), f"{reply.label} returned an incorrect replay flag.")
    return reply.result


def delta(before: dict[str, Any], after: dict[str, Any]) -> dict[str, int]:
    return {key: after["totals"][key] - count for key, count in before["totals"].items()}


def no_erp_effects(before: dict[str, Any], after: dict[str, Any]) -> None:
    changes = delta(before, after)
    require(all(changes[table] == 0 for table in ERP_TABLES), "Tenant-only requests changed ERP or managed-product rows.")
    require(all(count == 0 for count in after["tenant_effects"].values()), "A tenant-only tenant has ERP rows.")


def bound_customer(snapshot: dict[str, Any], label: str) -> dict[str, Any]:
    rows = [row for row in snapshot["customers"] if row["label"] == label]
    require(len(rows) == 1, "SQL did not find the expected synthetic customer.")
    return rows[0]


def committed(snapshot: dict[str, Any], result: dict[str, Any], actor_id: int | None = None) -> None:
    tenant = [row for row in snapshot["tenants"] if row["id"] == result["tenant_id"]]
    member = [row for row in snapshot["memberships"] if row["id"] == result["membership_id"]]
    receipt = [row for row in snapshot["receipts"] if row["tenant_id"] == result["tenant_id"]]
    audits = [row for row in snapshot["audits"] if row["subject_id"] == result["tenant_id"]]
    require(len(tenant) == len(member) == len(receipt) == len(audits) == 1, "SQL did not find exactly one tenant, membership, receipt and audit for the result.")
    require(tenant[0]["status"] == member[0]["status"] == "active", "Committed tenant or membership is not active.")
    require(member[0]["tenant_id"] == result["tenant_id"] and member[0]["user_id"] == result["principal_id"], "SQL membership differs from the HTTP creation result.")
    require(receipt[0]["membership_id"] == result["membership_id"] and receipt[0]["principal_id"] == result["principal_id"], "SQL receipt differs from the HTTP creation result.")
    if actor_id is not None:
        require(receipt[0]["actor_user_id"] == actor_id == audits[0]["user_id"], "SQL actor audit differs from the request actor.")


def same_result(first: dict[str, Any], second: dict[str, Any]) -> bool:
    return all(first[key] == second[key] for key in ("tenant_id", "membership_id", "principal_id"))


def erp_smoke(fixture: Fixture, report: dict[str, Any]) -> dict[str, Any]:
    catalog = fixture.post("erp-app-catalog", {}, realm="operator", path=APP_CATALOG_PATH, method="GET")
    require(catalog.status == 200 and bool(catalog.result.get("app_ids")), "The real HTTP app catalog did not expose an available app.")
    app_id = sorted(catalog.result["app_ids"])[0]
    before = fixture.helper("erp-snapshot")
    payload = {
        "legal_name": fixture.prefix + "-erp", "admin_name": fixture.prefix + "-erp-owner",
        "admin_email": fixture.prefix + "-erp-owner@example.invalid",
        "requested_by": fixture.context["people"]["operator"], "first_environment": "none", "app_ids": [app_id],
    }
    reply = fixture.post("erp-operator-registration", payload, realm="operator", path=ERP_PATH)
    require(reply.status == 201, f"ERP operator registration returned HTTP {reply.status}; expected 201.")
    require(reply.private_body.get("environment_id") is None and reply.private_body.get("email") == payload["admin_email"], "ERP response did not preserve the no-environment owner contract.")
    password = reply.private_body.get("temporary_password")
    require(isinstance(password, str) and bool(password), "ERP registration did not return its one-time password.")
    checked_password = fixture.helper("verify-erp-password", {"temporary_password": password})
    after = fixture.helper("erp-snapshot")
    observation = after["erp"]
    require(isinstance(observation, dict), "SQL did not find the ERP regression tenant.")
    require(observation["tenant_id"] == reply.result.get("tenant_id"), "ERP HTTP tenant ID differs from SQL.")
    require(observation["tenant_status"] == observation["membership_status"] == "active", "ERP tenant or owner membership is not active.")
    require(observation["membership_principal_id"] == observation["principal_id"] != observation["operator_principal_id"], "ERP operator and owner are not separated in SQL.")
    require(observation["membership_count"] == observation["owner_role_count"] == observation["owner_assignment_count"] == 1, "ERP regression did not retain exactly one owner membership, Owner role and assignment.")
    require(observation["units_count"] > 0 and app_id in observation["entitlement_app_ids"], "ERP regression lost default units or the selected entitlement.")
    require(observation["environment_count"] == observation["module_installation_count"] == observation["operator_membership_count"] == 0, "ERP no-environment registration created unexpected runtime or operator access rows.")
    require(observation["must_change_password"] is True and checked_password["password_matches"] is True, "ERP one-time password flags or stored hash differ from the response.")
    changes = delta(before, after)
    require(all(changes[table] == 1 for table in ("clients", "tenants", "users", "tenant_memberships", "roles", "role_assignments")), "ERP regression did not commit one complete registration.")
    observation["temporary_password_matches"] = True
    return {
        "kind": "pf01-erp-registration", "status": "passed", "source_revision": report["source_revision"],
        "backend_source_hashes": after["metadata"]["source_hashes"],
        "http_status": reply.status, "first_environment": "none", "app_id": app_id,
        "observed": observation, "sql_delta": changes,
    }


def comparative_erp_lane(fixture: Fixture, report: dict[str, Any]) -> dict[str, Any]:
    head = erp_smoke(fixture, report)
    if fixture.args.baseline_evidence is None:
        return {"lane": 1, "status": "not_run", "reason": "Head ERP HTTP smoke passed. Comparative trunk evidence was not supplied.", "head": head}
    try:
        baseline = json.loads(fixture.args.baseline_evidence.read_text(encoding="utf-8-sig"))
    except (ValueError, OSError):
        raise VerificationFailure("ERP baseline evidence could not be read as JSON.") from None
    require(isinstance(baseline, dict) and baseline.get("kind") == "pf01-erp-registration" and baseline.get("status") == "passed" and baseline.get("http_status") == 201 and baseline.get("first_environment") == "none", "ERP baseline evidence does not describe a successful real no-environment registration.")
    require(isinstance(baseline.get("source_revision"), str) and re.fullmatch(r"[a-f0-9]{40}", baseline["source_revision"]) is not None and isinstance(baseline.get("backend_source_hashes"), dict) and bool(baseline["backend_source_hashes"]), "ERP baseline evidence lacks a revision or backend source hashes.")
    require(baseline["source_revision"] == fixture.args.baseline_revision,
            "ERP evidence does not match the explicitly pinned trunk revision.")
    require(baseline["source_revision"] != head["source_revision"],
            "Trunk and head evidence must come from different source revisions.")
    verified_backend_hashes(baseline["backend_source_hashes"], fixture.args.baseline_revision)
    require(baseline.get("app_id") == head["app_id"], "Trunk and head ERP probes selected different catalog apps.")
    observed = baseline.get("observed")
    require(isinstance(observed, dict), "ERP baseline evidence lacks independent SQL observations.")
    comparable = (
        "tenant_status", "membership_status", "membership_count", "owner_role_count",
        "owner_assignment_count", "units_count", "entitlement_app_ids", "environment_count",
        "module_installation_count", "operator_membership_count", "must_change_password", "temporary_password_matches",
    )
    require(all(key in observed and observed[key] == head["observed"][key] for key in comparable), "ERP trunk/head SQL observations differ.")
    require(observed.get("membership_principal_id") == observed.get("principal_id") != observed.get("operator_principal_id"), "ERP baseline did not prove operator/owner separation.")
    safe_baseline = {
        "kind": baseline["kind"], "status": baseline["status"], "source_revision": baseline["source_revision"],
        "http_status": baseline["http_status"], "first_environment": baseline["first_environment"],
        "app_id": baseline["app_id"], "observed": {key: observed[key] for key in comparable},
    }
    return {"lane": 1, "status": "passed", "head": head, "baseline": safe_baseline, "scope": "Actual ERP HTTP registration and SQL parity with first_environment=none. Interactive login and production environment provisioning are outside this probe."}


def verified_backend_hashes(hashes: dict[str, str], revision: str) -> dict[str, str]:
    required = {"app/Platform/Tenant/Actions/RegisterBusiness.php", "app/Foundation/UnitOfMeasure/Listeners/ProvisionUnitsForNewTenant.php"}
    require(required <= hashes.keys(), "Backend evidence lacks the registration and seed lifecycle source hashes.")
    for path, observed in hashes.items():
        require(path.startswith("app/") and path.endswith(".php") and ".." not in pathlib.PurePosixPath(path).parts,
                "Backend evidence contains an invalid source path.")
        result = subprocess.run(["git", "show", revision + ":apps/core/" + path], cwd=ROOT, capture_output=True, timeout=10)
        require(result.returncode == 0 and hashlib.sha256(result.stdout).hexdigest() == observed,
                "Backend source hashes do not match the pinned Git revision.")
    return hashes


def live_lanes(fixture: Fixture, report: dict[str, Any]) -> None:
    try:
        report["lanes"].append(comparative_erp_lane(fixture, report))
    except VerificationFailure as failure:
        report["lanes"].append({"lane": 1, "status": "failed", "reason": str(failure)})
    initial = fixture.helper("snapshot")
    owner = fixture.context["people"]["existing"]
    shared: dict[str, Any] = {}

    def lane(number: int, check: Any) -> None:
        try:
            evidence = check()
            report["lanes"].append({"lane": number, "status": "passed", **evidence})
        except VerificationFailure as failure:
            report["lanes"].append({"lane": number, "status": "failed", "reason": str(failure)})

    def existing() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        result = created(fixture.post("existing-principal", fixture.payload("existing")))
        after = fixture.helper("snapshot")
        committed(after, result, owner)
        require(result["principal_id"] == owner, "An existing identity resolved to another principal.")
        changes = delta(before, after)
        require(changes["users"] == changes["external_identities"] == 0 and changes["tenants"] == changes["tenant_memberships"] == 1, "Existing-principal creation duplicated identity rows or skipped membership.")
        no_erp_effects(before, after)
        shared["first"] = result
        return {"creation": result, "sql_delta": changes}

    def new_principal() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        before_customer = bound_customer(before, "new")
        require(before_customer["user_id"] is None and before_customer["identity_count"] == 0, "Synthetic verified-SSO fixture was already bound before deliberate creation.")
        result = created(fixture.post("new-principal", fixture.payload("new"), "new"))
        after = fixture.helper("snapshot")
        committed(after, result, result["principal_id"])
        customer = bound_customer(after, "new")
        changes = delta(before, after)
        require(changes["users"] == changes["external_identities"] == changes["tenants"] == changes["tenant_memberships"] == 1, "Deliberate creation did not create one principal, identity, tenant and membership.")
        require(customer["user_id"] == customer["identity_user_id"] == result["principal_id"] and customer["identity_count"] == 1, "New SSO principal mapping differs from the committed result.")
        no_erp_effects(before, after)
        return {"creation": result, "sql_delta": changes, "scope": "Synthetic verified SSO session; external SSO exchange is covered separately."}

    def subjects() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        require(all(value is True for value in before["identity_pair"].values()), "Same-provider-email fixture lacks distinct subjects or verified contacts.")
        results = [created(fixture.post(label, fixture.payload(label), label)) for label in ("same_email_a", "same_email_b")]
        after = fixture.helper("snapshot")
        require(results[0]["principal_id"] != results[1]["principal_id"], "Different SSO subjects were merged.")
        for label, result in zip(("same_email_a", "same_email_b"), results):
            committed(after, result, result["principal_id"])
            customer = bound_customer(after, label)
            require(customer["user_id"] == customer["identity_user_id"] == result["principal_id"] and customer["identity_count"] == 1, "Subject binding did not match SQL.")
        require(delta(before, after)["users"] == 2, "Distinct SSO subjects did not create distinct principals.")
        rejected = fixture.post("email-collision", fixture.payload("collision"), "collision")
        require(rejected.status == 409, "An email collision without exact external identity was not rejected with HTTP 409.")
        final = fixture.helper("snapshot")
        require(all(value == 0 for value in delta(after, final).values()), "Rejected email collision committed database changes.")
        collision = bound_customer(final, "collision")
        require(collision["user_id"] is None and collision["identity_count"] == 0, "Rejected subject acquired another account's principal.")
        no_erp_effects(before, final)
        return {"creations": results, "collision_http_status": rejected.status, "sql_identity_pair": final["identity_pair"]}

    def without_apps() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        result = created(fixture.post("no-apps", fixture.payload("no-apps")))
        after = fixture.helper("snapshot")
        committed(after, result, owner)
        no_erp_effects(before, after)
        forbidden = fixture.post("forbidden-apps", {**fixture.payload("forbidden-apps"), "app_ids": [str(uuid.uuid4())]})
        require(forbidden.status == 422, "The tenant-only endpoint accepted ERP app_ids.")
        final = fixture.helper("snapshot")
        require(all(value == 0 for value in delta(after, final).values()), "Rejected ERP app_ids committed changes.")
        return {"creation": result, "tenant_effects": final["tenant_effects"], "app_ids_http_status": forbidden.status}

    def authorization() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        payload = {**fixture.payload("forbidden-operator"), "requested_by": owner, "owner_user_id": owner}
        responses = [
            fixture.post("customer-bearer-at-operator", payload, realm="customer_at_operator"),
            fixture.post("nonoperator-actor", payload, realm="operator"),
            fixture.post("forged-customer-owner", payload),
        ]
        require([reply.status for reply in responses] == [401, 404, 422], "Operator/customer authorization or protected actor fields were not enforced.")
        after = fixture.helper("snapshot")
        require(all(value == 0 for value in delta(before, after).values()), "Rejected authorization requests committed changes.")
        return {"http_statuses": [reply.status for reply in responses], "sql_delta": delta(before, after)}

    def operator_owner() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        operator = fixture.context["people"]["operator"]
        result = created(fixture.post("operator-target-owner", {**fixture.payload("operator-owner"), "requested_by": operator, "owner_user_id": owner}, realm="operator"))
        after = fixture.helper("snapshot")
        committed(after, result, operator)
        require(result["principal_id"] == owner and operator != owner, "Operator became the target owner.")
        require(not any(row["user_id"] == operator and row["tenant_id"] == result["tenant_id"] for row in after["memberships"]), "Operator acquired membership in the customer tenant.")
        no_erp_effects(before, after)
        return {"creation": result, "operator_principal_id": operator, "sql_delta": delta(before, after)}

    def idempotency() -> dict[str, Any]:
        payload = fixture.payload("replay")
        first = created(fixture.post("idempotency-first", payload))
        before = fixture.helper("snapshot")
        replay = created(fixture.post("idempotency-replay", payload), 200)
        require(same_result(first, replay), "Replay returned different creation IDs.")
        changed = fixture.post("idempotency-changed", {**payload, "name": payload["name"] + "-changed"})
        require(changed.status == 409, "Changed payload on the same key was not rejected with HTTP 409.")
        after = fixture.helper("snapshot")
        require(all(value == 0 for value in delta(before, after).values()), "Replay or changed payload duplicated committed rows.")
        payload = fixture.payload("concurrent-key")
        responses = fixture.concurrent([("same-key-a", payload, "existing"), ("same-key-b", payload, "existing")])
        require(sorted(reply.status for reply in responses) == [200, 201], "Concurrent same-key requests did not return one creation and one replay.")
        results = [created(reply, reply.status) for reply in responses]
        require(same_result(*results), "Concurrent same-key requests returned different creation IDs.")
        final = fixture.helper("snapshot")
        changes = delta(after, final)
        require(all(changes[table] == 1 for table in ("clients", "tenants", "tenant_memberships", "tenant_creation_requests")), "Concurrent same-key requests did not commit exactly one creation.")
        committed(final, first, owner)
        committed(final, results[0], owner)
        other = created(fixture.post("same-key-other-actor", {**fixture.payload("actor-key"), "idempotency_key": payload["idempotency_key"]}, "race"))
        final = fixture.helper("snapshot")
        require(other["principal_id"] == fixture.context["people"]["race"] and not same_result(other, results[0]), "Another actor replayed somebody else's creation.")
        committed(final, other, other["principal_id"])
        same_key_receipts = [row for row in final["receipts"] if row["idempotency_key"] == payload["idempotency_key"]]
        require(len(same_key_receipts) == 2 and len({row["actor_scope"] for row in same_key_receipts}) == 2, "Idempotency key is not scoped to the actor.")
        operator_payload = {
            **fixture.payload("uuid-spelling"), "requested_by": fixture.context["people"]["operator"],
            "owner_user_id": owner, "idempotency_key": "abcdefab-cdef-4abc-8def-abcdefabcdef",
        }
        variant = {**operator_payload, "idempotency_key": operator_payload["idempotency_key"].upper()}
        variants = fixture.concurrent([("uuid-lower", operator_payload, "existing"), ("uuid-upper", variant, "existing")], realm="operator")
        require(sorted(reply.status for reply in variants) == [200, 201], "Equivalent UUID spellings did not produce one creation and one replay.")
        variant_results = [created(reply, reply.status) for reply in variants]
        require(same_result(*variant_results) and variant_results[0]["principal_id"] == owner, "Equivalent UUID spellings changed the creation owner or result.")
        final = fixture.helper("snapshot")
        committed(final, variant_results[0], fixture.context["people"]["operator"])
        no_erp_effects(before, final)
        return {"creation": first, "replay": replay, "concurrent_statuses": [reply.status for reply in responses], "concurrent_creation": results[0], "concurrent_sql_delta": changes, "other_actor_creation": other, "uuid_spelling_statuses": [reply.status for reply in variants]}

    def slug_race() -> dict[str, Any]:
        before = fixture.helper("snapshot")
        first = fixture.payload("same-slug")
        second = {**fixture.payload("same-slug-other"), "slug": first["slug"]}
        responses = fixture.concurrent([("slug-race-a", first, "existing"), ("slug-race-b", second, "race")])
        require(sorted(reply.status for reply in responses) == [201, 409], "Concurrent explicit slug requests did not return one creation and one conflict.")
        winner = next(reply for reply in responses if reply.status == 201)
        result = created(winner)
        after = fixture.helper("snapshot")
        changes = delta(before, after)
        require(all(changes[table] == 1 for table in ("clients", "tenants", "tenant_memberships", "tenant_creation_requests")), "Slug race did not commit exactly one creation.")
        require(sum(row["slug"] == first["slug"] for row in after["tenants"]) == 1, "SQL contains duplicate explicit slugs.")
        expected_owner = owner if winner.label.endswith("-a") else fixture.context["people"]["race"]
        require(result["principal_id"] == expected_owner, "Slug-race membership belongs to the losing principal.")
        committed(after, result, expected_owner)
        no_erp_effects(before, after)
        return {"http_statuses": [reply.status for reply in responses], "creation": result, "sql_delta": changes}

    def two_tenants() -> dict[str, Any]:
        require("first" in shared, "The first existing-principal creation failed, so the two-tenant comparison is incomplete.")
        before = fixture.helper("snapshot")
        second = created(fixture.post("second-tenant", fixture.payload("second-tenant")))
        after = fixture.helper("snapshot")
        first = shared["first"]
        require(first["principal_id"] == second["principal_id"] == owner and first["tenant_id"] != second["tenant_id"] and first["membership_id"] != second["membership_id"], "Two tenants did not retain distinct memberships for the same principal.")
        committed(after, first, owner)
        committed(after, second, owner)
        require(delta(before, after)["users"] == 0, "Second tenant duplicated the principal.")
        no_erp_effects(initial, after)
        return {"creations": [first, second], "scope": "Creation IDs and memberships are checked in SQL. Browser tenant switching belongs to PF03."}

    for number, check in ((2, existing), (3, new_principal), (4, subjects), (5, without_apps), (6, authorization), (7, operator_owner), (8, idempotency), (9, slug_race), (10, two_tenants)):
        lane(number, check)


def percentile95(values: list[float]) -> float:
    return sorted(values)[math.ceil(len(values) * 0.95) - 1]


def performance(fixture: Fixture) -> dict[str, Any]:
    for batch in range(5):
        created(fixture.post(f"warmup-{batch}", fixture.payload(f"warmup-{batch}"), f"perf_{batch}"))
    before = fixture.helper("snapshot")
    batches = []
    replies = []
    expected_principals = []
    for batch in range(5):
        samples = [fixture.post(f"perf-{batch}-{sample}", fixture.payload(f"perf-{batch}-{sample}"), f"perf_{batch}") for sample in range(30)]
        replies.extend(samples)
        expected_principals.extend([fixture.context["people"][f"perf_{batch}"]] * len(samples))
        times = [reply.elapsed_ms for reply in samples]
        batches.append({"batch": batch + 1, "samples": 30, "p50_ms": round(statistics.median(times), 3), "p95_ms": round(percentile95(times), 3), "min_ms": round(min(times), 3), "max_ms": round(max(times), 3), "errors": sum(reply.status != 201 for reply in samples)})
    after = fixture.helper("snapshot")
    changes = delta(before, after)
    errors = sum(reply.status != 201 for reply in replies)
    result: dict[str, Any] = {
        "claim": "Absolute sequential HTTP latency for committed tenant-only creation by existing principals on this isolated fixture.",
        "baseline": "Trunk has no tenant-only endpoint. No speedup ratio is calculated.",
        "concurrency": 1, "warmup_requests": 5, "samples": 150, "batches": batches,
        "errors": errors, "sql_delta": changes, "p95_budget_ms": fixture.args.p95_budget_ms,
        "p95_ms": round(percentile95([reply.elapsed_ms for reply in replies]), 3),
        "batch_p95_median_ms": round(statistics.median(batch["p95_ms"] for batch in batches), 3),
        "batch_p95_range_ms": [min(batch["p95_ms"] for batch in batches), max(batch["p95_ms"] for batch in batches)],
        "limits": "PHP development server and Docker test fixture. Machine load, resource observations and limiter profiling must accompany any performance interpretation. This is not a production capacity measurement.",
    }
    try:
        require(errors == 0, "The latency probe includes non-201 responses.")
        for reply, expected_principal in zip(replies, expected_principals, strict=True):
            require(reply.result.get("principal_id") == expected_principal,
                    "The latency response belongs to a different fixture principal.")
            committed(after, created(reply), expected_principal)
        require(all(changes[table] == 150 for table in ("clients", "tenants", "tenant_memberships", "tenant_creation_requests")), "The latency probe did not commit 150 distinct creations.")
        require(changes["users"] == changes["external_identities"] == 0, "The existing-principal latency probe created identity rows.")
        no_erp_effects(before, after)
        require(result["p95_ms"] <= fixture.args.p95_budget_ms, "The absolute fixture p95 exceeds the requested budget.")
        result["status"] = "passed"
    except VerificationFailure as failure:
        result.update(status="failed", reason=str(failure))
    return result


def arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--compose-file", type=pathlib.Path, required=True)
    parser.add_argument("--env-file", type=pathlib.Path, required=True)
    parser.add_argument("--project", default="coreerp-pf01")
    parser.add_argument("--service", default="app")
    parser.add_argument("--output", type=pathlib.Path, required=True)
    parser.add_argument("--baseline-evidence", type=pathlib.Path)
    parser.add_argument("--baseline-revision")
    parser.add_argument("--erp-baseline-only", action="store_true")
    parser.add_argument("--source-revision")
    parser.add_argument("--timeout", type=float, default=15)
    parser.add_argument("--p95-budget-ms", type=float, default=1000)
    args = parser.parse_args()
    parsed = urllib.parse.urlsplit(args.base_url)
    require(parsed.scheme == "http" and parsed.hostname in ("127.0.0.1", "localhost", "::1") and parsed.port is not None and not parsed.username and not parsed.password and parsed.path in ("", "/") and not parsed.query and not parsed.fragment, "Use an explicit loopback HTTP fixture URL and port.")
    args.base_url = args.base_url.rstrip("/")
    args.compose_file = args.compose_file.resolve()
    args.env_file = args.env_file.resolve()
    args.output = args.output.resolve()
    require(args.output not in (args.env_file, args.compose_file), "Evidence output must not overwrite the fixture configuration.")
    require(re.fullmatch(r"coreerp-pf01(?:-[a-z0-9]+)*", args.project) is not None, "Use the dedicated PF01 compose project.")
    require(re.fullmatch(r"[a-z][a-z0-9_-]*", args.service) is not None, "Use a valid compose fixture service name.")
    require(args.timeout > 0 and args.p95_budget_ms > 0, "Timeout and latency budget must be positive.")
    if args.source_revision is not None:
        require(re.fullmatch(r"[a-f0-9]{40}", args.source_revision) is not None, "Source revision must be a full Git SHA.")
    require(not args.erp_baseline_only or args.source_revision is not None, "ERP baseline mode requires the actual backend --source-revision.")
    if args.baseline_evidence is not None:
        args.baseline_evidence = args.baseline_evidence.resolve()
        require(args.baseline_evidence != args.output, "Evidence output must not overwrite the trunk baseline.")
        require(isinstance(args.baseline_revision, str) and re.fullmatch(r"[a-f0-9]{40}", args.baseline_revision) is not None,
                "Comparative ERP verification requires the explicitly pinned --baseline-revision.")
    return args


def main() -> int:
    args = arguments()
    environment = read_environment(args.env_file)
    fixture = Fixture(args, environment)
    report: dict[str, Any] = {
        "schema_version": 1, "run_id": fixture.run_id,
        "started_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "scope": "PF01 synthetic verified-SSO fixtures, actual HTTP requests, independent committed PostgreSQL SELECTs.",
        "lanes": [], "status": "failed",
        "source_hashes": {str(path.relative_to(ROOT)).replace("\\", "/"): hashlib.sha256(path.read_bytes()).hexdigest() for path in (pathlib.Path(__file__), ROOT / "scripts/tenant-creation-fixture.php")},
    }
    try:
        fixture.verify_target()
        revision = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, capture_output=True, timeout=10)
        report["driver_source_revision"] = revision.stdout.strip() if revision.returncode == 0 else None
        report["source_revision"] = args.source_revision or report["driver_source_revision"]
        dirty = subprocess.run(["git", "status", "--porcelain", "--untracked-files=normal"], cwd=ROOT, text=True, capture_output=True, timeout=10)
        report["driver_worktree_dirty"] = bool(dirty.stdout.strip()) if dirty.returncode == 0 else None
        fixture.context = fixture.helper("erp-baseline" if args.erp_baseline_only else "setup")
        report["database"] = fixture.context["metadata"]
        if args.source_revision is not None:
            verified_backend_hashes(report["database"]["source_hashes"], args.source_revision)
        if args.erp_baseline_only:
            report.update(erp_smoke(fixture, report))
            report["scope"] = "ERP baseline only. Actual HTTP registration with first_environment=none and independent SQL observations. This does not verify the tenant-only endpoints."
        else:
            live_lanes(fixture, report)
            report["performance"] = performance(fixture)
            report["final_sql"] = fixture.helper("snapshot")
            failed = any(lane["status"] == "failed" for lane in report["lanes"]) or report["performance"]["status"] == "failed"
            incomplete = any(lane["status"] == "not_run" for lane in report["lanes"])
            report["status"] = "failed" if failed else "partial" if incomplete else "passed"
    except VerificationFailure as failure:
        report["failure"] = str(failure)
    except Exception as failure:
        report["failure"] = "Driver failed with " + type(failure).__name__ + ". External details were withheld."
    report["requests"] = [reply.evidence() for reply in fixture.replies]
    report["finished_at"] = dt.datetime.now(dt.timezone.utc).isoformat()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"status": report["status"], "kind": report.get("kind", "pf01-acceptance"), "lanes": {str(lane["lane"]): lane["status"] for lane in report["lanes"]}, "performance": report.get("performance", {}).get("status", "not_run"), "http_requests": len(fixture.replies), "output": str(args.output)}))
    return {"passed": 0, "failed": 1, "partial": 2}[report["status"]]


if __name__ == "__main__":
    try:
        sys.exit(main())
    except VerificationFailure as failure:
        print(json.dumps({"status": "refused", "reason": str(failure)}), file=sys.stderr)
        sys.exit(2)
    except Exception as failure:
        print(json.dumps({"status": "failed", "reason": type(failure).__name__ + "; external details withheld"}), file=sys.stderr)
        sys.exit(1)
