"""Periksa validator PF01 dalam memori tanpa HTTP, Docker, atau boot Laravel.

Exit 1 berarti masih ada assertion yang menerima hasil salah. Exit 0 berarti
kedua celah validator ditolak dan pemeriksaan sintaks serta guard lulus.
"""

from __future__ import annotations

import ast
import base64
import json
import os
import pathlib
import subprocess
import sys
import types
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASE_REVISION = "cdab80be96ab309f88dbcc939e16f9c81f03b1d0"
SOURCE = ROOT / "scripts/verify-tenant-creation.py"
PHP_SOURCE = ROOT / "scripts/tenant-creation-fixture.php"


def load_driver() -> types.ModuleType:
    source = SOURCE.read_text(encoding="utf-8")
    ast.parse(source)
    module = types.ModuleType("pf01_review_proof")
    module.__file__ = str(SOURCE)
    sys.modules[module.__name__] = module
    exec(compile(source, str(SOURCE), "exec"), module.__dict__)
    return module


def reject_false_baseline(module: types.ModuleType) -> None:
    observed = dict(
        tenant_status="active", membership_status="active", membership_count=1,
        owner_role_count=1, owner_assignment_count=1, units_count=1,
        entitlement_app_ids=["fixture-app"], environment_count=0,
        module_installation_count=0, operator_membership_count=0,
        must_change_password=True, temporary_password_matches=True,
        membership_principal_id=1, principal_id=1, operator_principal_id=2,
    )
    head = dict(
        kind="pf01-erp-registration", status="passed", source_revision="a" * 40,
        backend_source_hashes={"invented.php": "0" * 64}, http_status=201,
        first_environment="none", app_id="fixture-app", observed=observed,
    )

    class MemoryBaseline:
        def read_text(self, **kwargs: object) -> str:
            return json.dumps(head)

    fixture = types.SimpleNamespace(args=types.SimpleNamespace(
        baseline_evidence=MemoryBaseline(), baseline_revision=BASE_REVISION,
        expected_baseline_revision=BASE_REVISION,
    ))
    module.erp_smoke = lambda fixture, report: head
    try:
        result = module.comparative_erp_lane(fixture, {})
    except module.VerificationFailure:
        return
    if result.get("status") == "failed":
        return
    raise AssertionError("Head evidence with invented hashes was accepted as trunk baseline.")


def reject_wrong_performance_owner(module: types.ModuleType) -> None:
    class MemoryFixture:
        def __init__(self) -> None:
            self.args = types.SimpleNamespace(p95_budget_ms=1000)
            self.context = {"people": {f"perf_{i}": 100 + i for i in range(5)}}
            self.rows: list[dict[str, object]] = []

        def payload(self, label: str) -> dict[str, str]:
            return {"name": label}

        def post(self, label: str, payload: object, actor: str) -> object:
            index = len(self.rows) + 1
            result = dict(
                tenant_id=str(index).zfill(26), membership_id=str(index + 500).zfill(26),
                principal_id=999, replayed=False,
            )
            self.rows.append(result)
            return module.Reply(label, 201, 1.0, result, {})

        def helper(self, verb: str) -> dict[str, object]:
            totals = {table: 0 for table in module.ERP_TABLES}
            totals.update({table: len(self.rows) for table in (
                "clients", "tenants", "tenant_memberships", "tenant_creation_requests",
            )})
            totals.update(users=0, external_identities=0)
            return dict(
                totals=totals, tenant_effects={table: 0 for table in module.ERP_TABLES},
                tenants=[dict(id=row["tenant_id"], status="active") for row in self.rows],
                memberships=[dict(
                    id=row["membership_id"], tenant_id=row["tenant_id"],
                    user_id=999, status="active",
                ) for row in self.rows],
                receipts=[dict(
                    tenant_id=row["tenant_id"], membership_id=row["membership_id"],
                    principal_id=999, actor_user_id=999,
                ) for row in self.rows],
                audits=[dict(subject_id=row["tenant_id"], user_id=999) for row in self.rows],
            )

    try:
        result = module.performance(MemoryFixture())
    except module.VerificationFailure:
        return
    if result.get("status") == "failed":
        return
    raise AssertionError("150 SQL-consistent creations by the wrong principal were accepted.")


def reject_wrong_http_target(module: types.ModuleType) -> None:
    args = types.SimpleNamespace(
        env_file=pathlib.Path("fixture.env"), compose_file=pathlib.Path("fixture.yml"),
        project="coreerp-pf01", service="live", base_url="http://127.0.0.1:41001",
    )
    fixture = module.Fixture(args, {})
    result = subprocess.CompletedProcess([], 0, "127.0.0.1:41000\n", "")
    with mock.patch.object(module.subprocess, "run", return_value=result):
        for address in ("http://127.0.0.1:41001", "http://[::1]:41000"):
            args.base_url = address
            try:
                fixture.verify_target()
            except module.VerificationFailure:
                continue
            raise AssertionError("A different listener accepted the fixture credentials.")
        args.base_url = "http://localhost:41000"
        fixture.verify_target()
        if args.base_url != "http://127.0.0.1:41000":
            raise AssertionError("The verified listener was not pinned before HTTP use.")


def reject_unsafe_configuration() -> None:
    prefix = PHP_SOURCE.read_text(encoding="utf-8").split(
        "    require $corePath.'/vendor/autoload.php';", 1,
    )
    if len(prefix) != 2 or "require " in prefix[0]:
        raise AssertionError("The PHP pre-bootstrap boundary changed. Inspect it before adapting this proof.")
    source = prefix[0].removeprefix("<?php")
    source = source.replace(
        "$corePath = dirname(__DIR__).'/apps/core';",
        "$corePath = base64_decode('" + base64.b64encode(str(ROOT / "apps/core").encode()).decode() + "');",
    )
    source += "    refuse('Review proof blocked bootstrap.');\n} catch (Throwable $failure) { exit(99); }\n"
    script = "eval(base64_decode('" + base64.b64encode(source.encode()).decode() + "'));"
    safe = {
        **os.environ, "APP_ENV": "testing", "DB_CONNECTION": "pgsql_test",
        "DB_TEST_DATABASE": "core_erp_pf01_test_guard", "DB_TEST_HOST": "database",
        "DB_TEST_SCHEMA": "coreerp_test", "COREERP_CONTROL_CONNECTION": "pgsql_test",
        "DB_URL": "", "LARAVEL_PARALLEL_TESTING": "", "TEST_TOKEN": "",
    }
    cases = {
        "APP_ENV": "local", "DB_CONNECTION": "pgsql", "DB_TEST_DATABASE": "core_erp",
        "DB_TEST_HOST": "localhost", "DB_TEST_SCHEMA": "public",
        "COREERP_CONTROL_CONNECTION": "pgsql", "DB_URL": "pgsql://invalid.invalid/forbidden",
        "LARAVEL_PARALLEL_TESTING": "1", "TEST_TOKEN": "1",
    }
    for key, wrong in cases.items():
        process = subprocess.run(
            ["php", "-r", script, "setup"], env={**safe, key: wrong},
            input=json.dumps({"run_id": "1" * 32}), text=True,
            capture_output=True, timeout=10, cwd=ROOT,
        )
        if process.returncode != 2 or "dedicated testing database" not in process.stderr:
            raise AssertionError("The pre-bootstrap guard did not reject " + key + ".")
    process = subprocess.run(
        ["php", "-r", script, "setup"], env=safe, input=json.dumps({"run_id": "invalid"}),
        text=True, capture_output=True, timeout=10, cwd=ROOT,
    )
    if process.returncode != 2 or "hexadecimal run_id" not in process.stderr:
        raise AssertionError("The pre-bootstrap guard did not reject malformed input.")


def main() -> int:
    module = load_driver()
    failures = 0
    checks = (
        ("reject_false_baseline", lambda: reject_false_baseline(module)),
        ("reject_wrong_performance_owner", lambda: reject_wrong_performance_owner(module)),
        ("reject_wrong_http_target", lambda: reject_wrong_http_target(module)),
        ("reject_unsafe_configuration", reject_unsafe_configuration),
    )
    for label, check in checks:
        try:
            check()
            print(json.dumps({"check": label, "status": "passed"}))
        except Exception as failure:
            failures += 1
            reason = str(failure) if isinstance(failure, AssertionError) else type(failure).__name__
            print(json.dumps({"check": label, "status": "failed", "reason": reason}))
    lint = subprocess.run(["php", "-l", str(PHP_SOURCE)], capture_output=True, text=True, timeout=10)
    failures += lint.returncode != 0
    print(json.dumps({"check": "php_syntax", "status": "passed" if lint.returncode == 0 else "failed"}))
    return int(failures != 0)


if __name__ == "__main__":
    sys.exit(main())
