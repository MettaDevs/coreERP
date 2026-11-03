"""Regresi guard CPDB-02 tanpa Docker, PostgreSQL, atau bootstrap Laravel."""

from __future__ import annotations

import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("cpdb02_verifier", ROOT / "scripts/verify-database-connections.py")
assert SPEC is not None and SPEC.loader is not None
DRIVER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = DRIVER
SPEC.loader.exec_module(DRIVER)
SECRET = "synthetic-private-sentinel-" + "x" * 24
HASHES = {source: "b" * 64 for source in DRIVER.SOURCES}


def profile() -> dict:
    env = {
        "APP_ENV": "testing", "APP_DEBUG": "false", "DB_CONNECTION": "business",
        "COREERP_DATABASE_MODE": "split", "COREERP_CONTROL_CONNECTION": "control",
        "COREERP_BUSINESS_CONNECTION": "business", "COREERP_CONTROL_MIGRATION_CONNECTION": "control_migration",
        "COREERP_BUSINESS_MIGRATION_CONNECTION": "business_migration", "COREERP_MAINTENANCE_CONNECTION": "provisioning",
        "CACHE_STORE": "database", "SESSION_DRIVER": "database", "QUEUE_CONNECTION": "database",
        "SCHEDULER_CACHE_STORE": "database", "MAIL_MAILER": "array", "OTEL_PHP_AUTOLOAD_ENABLED": "false",
    }
    for prefix, database, role in (
        ("DB_CONTROL", "core_control_plane", "cpdb02_control_runtime"),
        ("DB_CONTROL_MIGRATION", "core_control_plane", "cpdb02_control_migrator"),
        ("DB_BUSINESS", "core_erp", "cpdb02_business_runtime"),
        ("DB_BUSINESS_MIGRATION", "core_erp", "cpdb02_business_migrator"),
        ("DB_PROVISIONING", "postgres", "cpdb02_provisioner"),
    ):
        env.update({prefix + suffix: value for suffix, value in (
            ("_HOST", "database"), ("_PORT", "5432"), ("_DATABASE", database), ("_USERNAME", role),
            ("_PASSWORD", SECRET), ("_SCHEMA", "public"), ("_SSLMODE", "disable"),
        )})
    return {"env": env, "roles": {role: SECRET for role in DRIVER.ROLES}, "databases": dict(DRIVER.DATABASES)}


def containers() -> list[dict]:
    return [{
        "Name": "/" + name,
        "Config": {"Labels": {"com.docker.compose.project": DRIVER.PROJECT, "coreerp.test-purpose": "cpdb-02", "com.docker.compose.service": service}},
        "State": {"Running": True, "Restarting": False},
        "NetworkSettings": {"Networks": {"fixture": {"NetworkID": "synthetic-network"}}},
    } for name, service in ((DRIVER.APP, "app"), (DRIVER.DATABASE, "database"))]


def result(verb: str) -> dict:
    return {
        "metadata": {"profile_sha256": "a" * 64, "source_hashes": dict(HASHES), "php_version": "8.4.27", "postgres_version": "16.14", "process_id": 42},
        "lanes": {name: {"status": "passed", "checks": {check: True for check in DRIVER.expected_checks(verb, name)}, "identities": DRIVER.expected_identities(verb, name), "error": None} for name in DRIVER.VERBS[verb]},
    }


class VerifierGuards(unittest.TestCase):
    def assert_refused(self, callback) -> None:
        with self.assertRaises(DRIVER.VerificationFailure) as failure:
            callback()
        self.assertNotIn(SECRET, str(failure.exception))

    def test_valid_profile_and_complete_results(self) -> None:
        DRIVER.validate_profile(profile())
        results = {verb: DRIVER.validate_result(result(verb), verb, "a" * 64, HASHES) for verb in DRIVER.VERBS}
        self.assertTrue(DRIVER.passed(results))

    def test_unsafe_profile_is_refused(self) -> None:
        mutations = (
            lambda p: p["env"].update(DB_CONTROL_HOST="production.invalid"),
            lambda p: p["env"].update(DB_BUSINESS_URL="postgres://" + SECRET + "@production.invalid/business"),
            lambda p: p["env"].update(DB_PROVISIONING_USERNAME="postgres"),
            lambda p: p["env"].update(DB_BUSINESS_MIGRATION_DATABASE="core_control_plane"),
            lambda p: p["env"].update(COREERP_DATABASE_MODE="combined"),
            lambda p: p["env"].update(DB_QUEUE_CONNECTION="business"),
            lambda p: p["env"].update(APP_CONFIG_CACHE="/fixture/config.php"),
            lambda p: p["env"].update(TEST_TOKEN="1"),
            lambda p: p["databases"].update(production="postgres"),
            lambda p: p["roles"].pop("cpdb02_outsider"),
            lambda p: p["roles"].update(cpdb02_control_runtime="short"),
            lambda p: p.update(private=SECRET),
        )
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                value = profile()
                mutation(value)
                self.assert_refused(lambda: DRIVER.validate_profile(value))

    def test_docker_identity_guards(self) -> None:
        DRIVER.verify_containers(containers())
        for field, value in (("com.docker.compose.project", "erp-dev"), ("coreerp.test-purpose", "pf-01"), ("com.docker.compose.service", "core-app")):
            data = containers()
            data[0]["Config"]["Labels"][field] = value
            self.assert_refused(lambda: DRIVER.verify_containers(data))
        for mutation in (
            lambda data: data[1].update(Name="/production-database"),
            lambda data: data[1]["State"].update(Running=False),
            lambda data: data[1]["State"].update(Restarting=True),
            lambda data: data[1]["NetworkSettings"]["Networks"]["fixture"].update(NetworkID="unrelated-network"),
            lambda data: data.pop(),
        ):
            data = containers()
            mutation(data)
            self.assert_refused(lambda: DRIVER.verify_containers(data))

    def test_no_container_write_before_label_guard(self) -> None:
        fixture = DRIVER.Fixture.__new__(DRIVER.Fixture)
        fixture.helper_path = "/tmp/synthetic.php"
        data = containers()
        data[0]["Config"]["Labels"]["com.docker.compose.project"] = "erp-dev"
        response = subprocess.CompletedProcess([], 0, json.dumps(data), "")
        with mock.patch.object(DRIVER, "run", return_value=response) as runner:
            self.assert_refused(fixture.install)
        self.assertEqual(runner.call_count, 1)
        self.assertEqual(runner.call_args.args[0][:2], ["docker", "inspect"])

    def test_source_profile_and_output_shape_guards(self) -> None:
        mutations = (
            lambda data: data["metadata"].update(profile_sha256="f" * 64),
            lambda data: data["metadata"]["source_hashes"].update({DRIVER.SOURCES[0]: "f" * 64}),
            lambda data: data["metadata"].update(php_version=SECRET),
            lambda data: data["metadata"].update(postgres_version=SECRET),
            lambda data: data["metadata"].update(process_id=0),
            lambda data: data.update(stderr=SECRET),
            lambda data: data["metadata"].update(password=SECRET),
            lambda data: data["lanes"]["control"].update(private=SECRET),
            lambda data: data["lanes"]["control"]["checks"].update({SECRET: True}),
            lambda data: data["lanes"]["control"].update(identities=[{"database": SECRET, "role": "cpdb02_control_runtime"}]),
            lambda data: data["lanes"]["control"].update(identities=[]),
            lambda data: data["lanes"]["control"].update(error=SECRET),
        )
        for mutation in mutations:
            data = result("preflight")
            mutation(data)
            self.assert_refused(lambda: DRIVER.validate_result(data, "preflight", "a" * 64, HASHES))

    def test_missing_empty_false_or_invented_lane_cannot_pass(self) -> None:
        for mutation in (
            lambda data: data["lanes"].pop("control"),
            lambda data: data["lanes"].update(invented=data["lanes"]["control"]),
            lambda data: data["lanes"]["control"].update(checks={}),
            lambda data: data["lanes"]["control"]["checks"].update(database_role_match=False),
            lambda data: data["lanes"]["control"]["checks"].update(database_role_match=1),
            lambda data: data["lanes"]["control"].update(status="PASS"),
        ):
            data = result("preflight")
            mutation(data)
            self.assert_refused(lambda: DRIVER.validate_result(data, "preflight", "a" * 64, HASHES))
        all_results = {verb: result(verb) for verb in DRIVER.VERBS}
        del all_results["consume_cold"]
        self.assertFalse(DRIVER.passed(all_results))
        all_results["consume_cold"] = result("consume_cold")
        all_results["consume_cold"]["lanes"]["lock"]["status"] = "unknown"
        self.assertFalse(DRIVER.passed(all_results))

    def test_exit_zero_with_laravel_error_or_private_output_cannot_pass(self) -> None:
        fixture = DRIVER.Fixture.__new__(DRIVER.Fixture)
        fixture.guard = lambda: None
        fixture.profile_hash = "a" * 64
        fixture.source_hashes = dict(HASHES)
        fixture.run_id = "b" * 32
        fixture.helper_path = "/tmp/synthetic.php"
        for stdout in ("Laravel error " + SECRET, json.dumps({"error": SECRET}), json.dumps({"metadata": {}, "lanes": {}})):
            with mock.patch.object(DRIVER, "run", return_value=subprocess.CompletedProcess([], 0, stdout, SECRET)) as runner:
                self.assert_refused(lambda: fixture.helper("preflight"))
            command = runner.call_args.args[0]
            self.assertFalse(any(SECRET in argument for argument in command))
            self.assertNotIn(SECRET, runner.call_args.kwargs["data"])

    def test_preflight_failure_stops_all_mutating_lanes(self) -> None:
        data = result("preflight")
        data["lanes"]["control"]["status"] = "failed"
        helper = mock.Mock(return_value=data)
        self.assert_refused(lambda: DRIVER.collect(types.SimpleNamespace(helper=helper)))
        self.assertEqual(helper.call_args_list, [mock.call("preflight")])

    def test_later_helper_failure_remains_failed(self) -> None:
        def helper(verb):
            if verb == "consume_cold":
                raise DRIVER.VerificationFailure("Private output withheld.")
            return result(verb)

        data = DRIVER.collect(types.SimpleNamespace(helper=helper))
        self.assertFalse(DRIVER.passed(data))
        self.assertTrue(all(lane["status"] == "failed" for lane in data["consume_cold"]["lanes"].values()))

    def test_report_destination_and_profile_alias_guards(self) -> None:
        directory = ROOT / ".audit/cpdb-02"
        directory.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="guard-", dir=directory) as temp:
            base = pathlib.Path(temp)
            self.assertTrue(base.resolve().is_relative_to(directory.resolve()))
            private = base / "profile.json"
            private.write_text(json.dumps(profile()), encoding="utf-8")
            self.assertEqual(DRIVER.validate_output(base / "report.json", private), (base / "report.json").resolve())
            for output in (ROOT / "public-report.json", private, base / "report.txt", base):
                self.assert_refused(lambda: DRIVER.validate_output(output, private))
            hardlink = base / "hardlink.json"
            hardlink.hardlink_to(private)
            self.assert_refused(lambda: DRIVER.validate_output(hardlink, private))
            link = base / "symlink.json"
            try:
                link.symlink_to(private)
            except OSError:
                return
            self.assert_refused(lambda: DRIVER.validate_output(link, private))
            self.assert_refused(lambda: DRIVER.read_profile(link))


if __name__ == "__main__":
    unittest.main()
