"""Periksa CPDB-02 pada fixture Docker PostgreSQL terpisah yang sudah berjalan.

Perintah ini tidak membuat database, role, container, atau server. Berikan path
profile privat secara eksplisit. Output mentah proses dan credential tidak dicetak.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import uuid
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROJECT = "coreerp-cpdb02"
APP = PROJECT + "-app-1"
DATABASE = PROJECT + "-database-1"
DATABASES = {
    "core_control_plane": "cpdb02_control_migrator",
    "core_erp": "cpdb02_business_migrator",
    "core_erp_cpdb02_test_environment_a": "cpdb02_provisioner",
    "core_erp_cpdb02_test_environment_b": "cpdb02_provisioner",
}
ROLES = {
    "cpdb02_control_runtime", "cpdb02_control_migrator", "cpdb02_business_runtime",
    "cpdb02_business_migrator", "cpdb02_provisioner", "cpdb02_outsider",
}
ENV_KEYS = {
    "APP_ENV", "APP_DEBUG", "APP_KEY", "APP_URL", "LOG_CHANNEL", "CACHE_STORE", "CACHE_PREFIX",
    "SESSION_DRIVER", "SESSION_CONNECTION", "QUEUE_CONNECTION", "MAIL_MAILER", "BROADCAST_CONNECTION",
    "OTEL_PHP_AUTOLOAD_ENABLED", "COREERP_BASE_DOMAIN", "COREERP_DATABASE_MODE", "COREERP_CONTROL_CONNECTION",
    "COREERP_BUSINESS_CONNECTION", "COREERP_CONTROL_MIGRATION_CONNECTION", "COREERP_BUSINESS_MIGRATION_CONNECTION",
    "COREERP_MAINTENANCE_CONNECTION", "SCHEDULER_CACHE_STORE", "DB_CONNECTION", "DB_HOST", "DB_PORT",
    "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "DB_URL", "DB_TEST_HOST", "DB_TEST_PORT", "DB_TEST_DATABASE",
    "DB_TEST_SCHEMA", "DB_TEST_USERNAME", "DB_TEST_PASSWORD", "DB_PASSWORD_RESET_CONNECTION", "DB_QUEUE_CONNECTION",
    "DB_BATCH_CONNECTION", "DB_FAILED_JOB_CONNECTION", "DB_CACHE_CONNECTION", "DB_CACHE_LOCK_CONNECTION",
    *(prefix + suffix for prefix in ("DB_CONTROL", "DB_CONTROL_MIGRATION", "DB_BUSINESS", "DB_BUSINESS_MIGRATION", "DB_PROVISIONING") for suffix in ("_HOST", "_PORT", "_DATABASE", "_USERNAME", "_PASSWORD", "_SCHEMA", "_SSLMODE", "_URL")),
}
SOURCES = (
    "app/Platform/Environment/Support/EnvironmentConnection.php",
    "app/Platform/Environment/Support/ConnectionFrame.php",
    "app/Platform/Environment/Listeners/ResetDatabaseConnections.php",
    "app/Platform/Database/Support/DatabasePrivileges.php",
    "app/Platform/Modules/Support/CoreMigrateCommand.php",
    "app/Platform/Modules/Support/ModuleMigrator.php",
    "app/Platform/Modules/Support/ModuleManifest.php",
    "app/Platform/Modules/Support/ModuleMigrationRepository.php",
    "app/Platform/Modules/Support/ModuleSeeder.php",
    "app/Platform/ControlPlane/Console/ControlMigrate.php",
    "app/Platform/Reporting/Jobs/RunReportExport.php",
    "config/database.php", "config/coreerp.php", "config/session.php", "config/auth.php",
    "config/cache.php", "config/queue.php", "routes/console.php",
)
FRAMEWORK = ("session", "password_reset", "cache", "lock", "scheduler", "queue_success", "queue_failure", "batch")
VERBS = {
    "preflight": ("control", "business", "control_migration", "business_migration", "maintenance", "framework_pins"),
    "routing": ("control", "pooled", "environment_a", "environment_b", "nested_transaction", "exception_cleanup", "migration_guard"),
    "control_upgrade": ("runtime_restoration",),
    "migrate_environments": ("environment_a", "environment_b"),
    "modules": ("success", "failure", "history"),
    "privileges": ("control", "business", "environment_a", "environment_b", "cross_connect", "role_authority", "control_routine"),
    **{f"{phase}_{mode}": FRAMEWORK for mode in ("warm", "cold") for phase in ("produce", "consume")},
}
ROLE_CHECKS = {
    "database_role_match", "migrator_table_schema_ddl", "runtime_table_dml", "future_schema_table_defaults",
    "future_function_defaults", "public_schema_access_absent", "public_function_access_absent", "runtime_nextval",
    "runtime_setval_denied", "runtime_create_table_denied", "runtime_create_schema_denied", "runtime_alter_table_denied",
    "runtime_set_role_denied", "history_readable", "history_insert_grant_absent", "history_update_grant_absent",
    "history_delete_grant_absent", "history_update_denied", "history_delete_denied", "history_insert_denied",
    "history_nextval_denied", "history_setval_denied",
    "public_table_sequence_access_absent", "runtime_delete",
}


def row_checks(table: str) -> set[str]:
    return {table + "_control_count", *(table + "_business_absent_" + str(index) for index in range(3))}


def expected_checks(verb: str, lane: str) -> set[str]:
    if verb == "preflight":
        return {*("framework_connection_" + str(index) for index in range(7)), "scheduler_database_store"} if lane == "framework_pins" else {"database_role_match"}
    if verb == "routing":
        return {
            "control": {"database_role_match"},
            "pooled": {"database_role_match", "default_restored"},
            "environment_a": {"database_role_match", "default_restored"},
            "environment_b": {"database_role_match", "default_restored"},
            "nested_transaction": {"database_role_match", "ancestor_pdo_transaction_preserved", "middle_context_restored", "ancestor_survives_inner_cleanup", "outer_context_restored"},
            "exception_cleanup": {"database_role_match", "exception_observed", "exception_context_restored", "owned_connection_purged"},
            "migration_guard": {"database_role_match", "physical_control_alias_rejected", "business_migrator_selected"},
        }[lane]
    if verb == "migrate_environments":
        return {"normal_migration_succeeded", "database_role_match", "business_history_complete", "control_history_absent", "pooled_default_restored"}
    if verb == "control_upgrade":
        return {"normal_control_upgrade_succeeded", "runtime_default_restored", "database_role_match", "control_history_unchanged", "business_history_unchanged", "control_business_history_absent"}
    if verb == "modules":
        return {
            "success": {"database_role_match", "migration_recorded", "runtime_restored", "migration_used_owner", "seed_ran", "seed_used_runtime", "seed_idempotent", "migration_idempotent", "core_history_unchanged", "control_history_unchanged", "change_log_restored"},
            "failure": {"database_role_match", "expected_failure_observed", "runtime_restored", "ddl_rolled_back", "failure_not_recorded", "change_log_restored"},
            "history": {"database_role_match", "history_readable", "history_insert_grant_absent", "history_update_grant_absent", "history_delete_grant_absent", "history_update_denied", "history_delete_denied", "history_insert_denied", "history_nextval_denied", "history_setval_denied"},
        }[lane]
    if verb == "privileges":
        if lane in ("control", "business", "environment_a", "environment_b"):
            return ROLE_CHECKS
        if lane == "cross_connect":
            return {
                *(role + suffix for role in ("cpdb02_control_runtime", "cpdb02_control_migrator") for suffix in ("_business_connect_denied", "_environment_a_connect_denied", "_environment_b_connect_denied")),
                *(role + "_control_connect_denied" for role in ("cpdb02_business_runtime", "cpdb02_business_migrator")),
                *("outsider_connect_denied_" + str(index) for index in range(4)),
            }
        if lane == "role_authority":
            return {"database_role_match", "all_synthetic_roles_present", "only_provisioner_createdb", "no_inherited_role_memberships", "provisioner_control_connect_denied", "provisioner_pooled_connect_denied"}
        return {"database_role_match", "runtime_actor_version_trigger", "runtime_change_log_trigger", "erp_security_tables_absent"}
    checks = {"fresh_process"}
    if lane in ("session", "password_reset", "cache"):
        table, assertion = {"session": ("sessions", "session_value"), "password_reset": ("password_reset_tokens", "reset_token_valid"), "cache": ("cache", "cache_value")}[lane]
        return checks | {assertion} | row_checks(table)
    if lane == "lock":
        assertions = {"lock_acquired"} if verb.startswith("produce_") else {"lock_contended_after_restart", "original_lock_owner_released", "lock_reacquired"}
        return checks | assertions | row_checks("cache_locks")
    if lane == "scheduler":
        assertions = {"event_mutex_created", "scheduler_mutex_created"} if verb.startswith("produce_") else {"event_mutex_survived_restart", "scheduler_mutex_survived_restart"}
        return checks | assertions | row_checks("cache_locks")
    checks |= row_checks("jobs")
    if verb.startswith("consume_"):
        checks |= {"real_worker_completed", "worker_business_context", "worker_context_cleaned", "worker_actor_cleaned"} | row_checks("failed_jobs")
    if lane == "batch":
        checks |= {"batch_pending" if verb.startswith("produce_") else "batch_finished"} | row_checks("job_batches")
    return checks


def expected_identities(verb: str, lane: str) -> list[dict[str, str]]:
    targets = {
        "control": ("core_control_plane", "cpdb02_control_runtime"),
        "business": ("core_erp", "cpdb02_business_runtime"),
        "control_migration": ("core_control_plane", "cpdb02_control_migrator"),
        "business_migration": ("core_erp", "cpdb02_business_migrator"),
        "maintenance": ("postgres", "cpdb02_provisioner"),
        "environment_a": ("core_erp_cpdb02_test_environment_a", "cpdb02_business_runtime"),
        "environment_b": ("core_erp_cpdb02_test_environment_b", "cpdb02_business_runtime"),
    }
    names = []
    if verb == "preflight" and lane != "framework_pins":
        names = [lane]
    elif verb == "routing":
        names = {"pooled": ["business"], "nested_transaction": ["environment_b", "environment_a"], "exception_cleanup": ["business"], "migration_guard": ["control_migration", "maintenance"]}.get(lane, [lane])
    elif verb == "control_upgrade":
        names = ["business"]
    elif verb == "migrate_environments":
        names = [lane, "business"]
    elif verb == "modules":
        names = ["environment_a"]
    elif verb == "privileges":
        if lane in ("control", "business", "environment_a", "environment_b"):
            database, runtime = targets[lane]
            return [{"database": database, "role": "cpdb02_control_migrator" if lane == "control" else "cpdb02_business_migrator"}, {"database": database, "role": runtime}]
        names = {"cross_connect": [], "role_authority": ["maintenance"], "control_routine": ["control"]}[lane]
    elif verb.startswith("consume_") and lane in ("queue_success", "queue_failure", "batch"):
        names = ["environment_b" if lane == "queue_failure" else "environment_a"]
    return [{"database": targets[name][0], "role": targets[name][1]} for name in names]


class VerificationFailure(Exception):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationFailure(message)


def read_profile(path: pathlib.Path) -> tuple[dict[str, Any], str]:
    require(path.is_file() and not path.is_symlink(), "The explicit private profile must be a regular file.")
    content = path.read_bytes()
    require(len(content) <= 65536, "The private profile is too large.")
    try:
        profile = json.loads(content)
    except (ValueError, UnicodeError):
        raise VerificationFailure("The private profile is not valid JSON.") from None
    validate_profile(profile)
    return profile, hashlib.sha256(content).hexdigest()


def validate_profile(profile: Any) -> None:
    require(isinstance(profile, dict) and set(profile) == {"env", "roles", "databases"}, "The private profile shape is invalid.")
    env, roles, databases = profile["env"], profile["roles"], profile["databases"]
    require(isinstance(env, dict) and all(isinstance(k, str) and isinstance(v, (str, int, bool)) for k, v in env.items()), "The profile env map is invalid.")
    require(set(env) <= ENV_KEYS, "The profile contains an unapproved environment override.")
    require(isinstance(roles, dict) and set(roles) == ROLES and all(isinstance(v, str) and len(v) >= 24 for v in roles.values()), "The synthetic fixture roles are incomplete.")
    require(isinstance(databases, dict) and all(databases.get(k) == v for k, v in DATABASES.items()), "The profile does not identify the fixed fixture databases.")
    require(set(databases) <= set(DATABASES) | {"core_erp_cpdb02_test_suite"}, "The profile contains an unapproved database.")
    expected = {
        "APP_ENV": "testing", "APP_DEBUG": "false", "DB_CONNECTION": "business",
        "COREERP_DATABASE_MODE": "split", "COREERP_CONTROL_CONNECTION": "control",
        "COREERP_BUSINESS_CONNECTION": "business", "COREERP_CONTROL_MIGRATION_CONNECTION": "control_migration",
        "COREERP_BUSINESS_MIGRATION_CONNECTION": "business_migration", "COREERP_MAINTENANCE_CONNECTION": "provisioning",
        "CACHE_STORE": "database", "SESSION_DRIVER": "database", "QUEUE_CONNECTION": "database",
        "SCHEDULER_CACHE_STORE": "database", "MAIL_MAILER": "array", "OTEL_PHP_AUTOLOAD_ENABLED": "false",
    }
    require(all(str(env.get(k, "")) == v for k, v in expected.items()), "The profile must select the isolated split SQL fixture.")
    require(all(not v for k, v in env.items() if k.endswith("_URL") and k != "APP_URL"), "Database and external service URLs must be empty.")
    require(all(v == "database" for k, v in env.items() if k.endswith("_HOST")), "Every database host must be the fixture database service.")
    require(not env.get("LARAVEL_PARALLEL_TESTING") and not env.get("TEST_TOKEN"), "Parallel test rewriting is forbidden in this fixture.")
    for prefix, database, role in (
        ("DB_CONTROL", "core_control_plane", "cpdb02_control_runtime"),
        ("DB_CONTROL_MIGRATION", "core_control_plane", "cpdb02_control_migrator"),
        ("DB_BUSINESS", "core_erp", "cpdb02_business_runtime"),
        ("DB_BUSINESS_MIGRATION", "core_erp", "cpdb02_business_migrator"),
        ("DB_PROVISIONING", "postgres", "cpdb02_provisioner"),
    ):
        require(all(str(env.get(prefix + suffix, "")) == value for suffix, value in (
            ("_HOST", "database"), ("_PORT", "5432"), ("_DATABASE", database),
            ("_USERNAME", role), ("_SCHEMA", "public"), ("_SSLMODE", "disable"),
        )), "A named fixture connection has an unsafe target.")
        require(env.get(prefix + "_PASSWORD") == roles[role], "A named fixture credential differs from the private role profile.")
    for key in ("SESSION_CONNECTION", "DB_CACHE_CONNECTION", "DB_CACHE_LOCK_CONNECTION", "DB_PASSWORD_RESET_CONNECTION", "DB_QUEUE_CONNECTION", "DB_BATCH_CONNECTION", "DB_FAILED_JOB_CONNECTION"):
        require(env.get(key, "") in ("", "control"), "Framework SQL connections must use control or its configured default.")


def validate_output(path: pathlib.Path, profile_path: pathlib.Path) -> pathlib.Path:
    require(not path.is_symlink(), "A report cannot be a symbolic link.")
    require(not path.is_dir(), "A report cannot be a directory.")
    path = path.resolve()
    directory = (ROOT / ".audit/cpdb-02").resolve()
    require(directory.is_relative_to(ROOT.resolve()) and path.is_relative_to(directory), "Reports must remain under this worktree's private .audit/cpdb-02 directory.")
    require(path.suffix == ".json" and path != profile_path.resolve(), "The report must be a JSON file distinct from the profile.")
    require(not (path.exists() and profile_path.exists() and path.samefile(profile_path)), "A report cannot overwrite the private profile through an alias.")
    return path


def run(command: list[str], *, data: str | None = None, timeout: int = 600) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(command, input=data, text=True, capture_output=True, encoding="utf-8", timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        raise VerificationFailure("A fixture subprocess could not complete. External output was withheld.") from None


def verify_containers(objects: Any) -> None:
    require(isinstance(objects, list) and len(objects) == 2, "Docker did not identify both fixture containers.")
    networks = []
    for item, name, service in zip(objects, (APP, DATABASE), ("app", "database"), strict=True):
        require(isinstance(item, dict) and item.get("Name") == "/" + name, "A Docker container identity differs from the fixed fixture.")
        labels = item.get("Config", {}).get("Labels", {})
        require(labels.get("com.docker.compose.project") == PROJECT and labels.get("coreerp.test-purpose") == "cpdb-02" and labels.get("com.docker.compose.service") == service, "Docker labels do not identify the dedicated CPDB-02 fixture.")
        require(item.get("State", {}).get("Running") is True and not item["State"].get("Restarting"), "Both fixture containers must be running without a restart.")
        networks.append({v.get("NetworkID") for v in item.get("NetworkSettings", {}).get("Networks", {}).values()} - {None, ""})
    require(bool(networks[0] & networks[1]), "The fixture containers do not share a Docker network.")


def validate_result(result: Any, verb: str, profile_hash: str, source_hashes: dict[str, str]) -> dict[str, Any]:
    require(isinstance(result, dict) and set(result) == {"metadata", "lanes"}, "The helper returned an unexpected result shape.")
    metadata = result["metadata"]
    require(isinstance(metadata, dict) and set(metadata) == {"profile_sha256", "source_hashes", "php_version", "postgres_version", "process_id"}, "The helper metadata is incomplete.")
    require(metadata["profile_sha256"] == profile_hash and metadata["source_hashes"] == source_hashes, "The running source or private profile differs from the reviewed input.")
    require(isinstance(metadata["php_version"], str) and re.fullmatch(r"[0-9]+(?:\.[0-9]+){2}(?:[-+][A-Za-z0-9.]+)?", metadata["php_version"]) is not None, "The PHP version is invalid.")
    require(isinstance(metadata["postgres_version"], str) and re.fullmatch(r"[0-9]+(?:\.[0-9]+){1,2}", metadata["postgres_version"]) is not None, "The PostgreSQL version is invalid.")
    require(type(metadata["process_id"]) is int and metadata["process_id"] > 0, "The helper process identity is missing.")
    lanes = result["lanes"]
    require(isinstance(lanes, dict) and set(lanes) == set(VERBS[verb]), "The helper omitted or invented a required lane.")
    for name, lane in lanes.items():
        require(isinstance(lane, dict) and set(lane) == {"status", "checks", "identities", "error"}, "A lane returned unexpected fields.")
        require(lane["status"] in ("passed", "failed", "unknown"), "A lane has an invalid status.")
        checks = lane["checks"]
        require(isinstance(checks, dict) and set(checks) <= expected_checks(verb, name) and all(type(v) is bool for v in checks.values()), "Lane checks must contain only whitelisted boolean observations.")
        require(lane["status"] != "passed" or set(checks) == expected_checks(verb, name) and all(checks.values()) and lane["error"] is None, "An incomplete or failing lane cannot pass.")
        require(lane["error"] is None or lane["error"] in ("assertion_failed", "schema_not_ready", "probe_exception", "producer_incomplete"), "A lane error is not a public failure category.")
        require(isinstance(lane["identities"], list) and len(lane["identities"]) <= 12, "Lane identities are invalid.")
        for identity in lane["identities"]:
            require(isinstance(identity, dict) and set(identity) == {"database", "role"} and identity["database"] in {*DATABASES, "postgres"} and identity["role"] in ROLES, "A lane disclosed an unapproved identity.")
        require(lane["status"] != "passed" or lane["identities"] == expected_identities(verb, name), "A passing lane omitted or changed its observed database and role identities.")
    return result


class Fixture:
    def __init__(self, args: argparse.Namespace, profile_hash: str):
        self.args = args
        self.profile_hash = profile_hash
        self.run_id = uuid.uuid4().hex
        self.helper_path = "/tmp/cpdb02-" + self.run_id + ".php"
        self.source_hashes = {}
        for source in SOURCES:
            path = args.source_root / "apps/core" / source
            require(path.is_file(), "A required reviewed source file is missing.")
            self.source_hashes[source] = hashlib.sha256(path.read_bytes()).hexdigest()

    def guard(self) -> None:
        process = run(["docker", "inspect", APP, DATABASE], timeout=20)
        require(process.returncode == 0, "The fixed Docker fixture cannot be inspected.")
        try:
            objects = json.loads(process.stdout)
        except ValueError:
            raise VerificationFailure("Docker inspect did not return valid JSON.") from None
        verify_containers(objects)

    def install(self) -> None:
        self.guard()
        process = run(["docker", "cp", str(ROOT / "scripts/database-connection-fixture.php"), APP + ":" + self.helper_path], timeout=30)
        require(process.returncode == 0, "The helper could not be copied into the guarded fixture.")

    def helper(self, verb: str) -> dict[str, Any]:
        self.guard()
        process = run(["docker", "exec", "-i", APP, "php", self.helper_path, "/fixture/profiles.json", verb], data=json.dumps({"run_id": self.run_id, "profile_sha256": self.profile_hash, "source_hashes": self.source_hashes}))
        require(process.returncode == 0, "The fixture helper failed. External output was withheld.")
        try:
            result = json.loads(process.stdout)
        except ValueError:
            raise VerificationFailure("The helper did not return valid JSON. External output was withheld.") from None
        return validate_result(result, verb, self.profile_hash, self.source_hashes)


def collect(fixture: Fixture) -> dict[str, Any]:
    results = {"preflight": fixture.helper("preflight")}
    require(all(lane["status"] == "passed" for lane in results["preflight"]["lanes"].values()), "The read-only fixture preflight did not pass.")
    for verb in VERBS:
        if verb == "preflight":
            continue
        try:
            results[verb] = fixture.helper(verb)
        except VerificationFailure:
            results[verb] = {"metadata": None, "lanes": {name: {"status": "failed", "checks": {}, "identities": [], "error": "probe_exception"} for name in VERBS[verb]}}
    return results


def passed(results: dict[str, Any]) -> bool:
    return set(results) == set(VERBS) and all(
        results[verb]["metadata"] is not None and set(results[verb]["lanes"]) == set(names) and all(
            lane["status"] == "passed" and set(lane["checks"]) == expected_checks(verb, name) and all(lane["checks"].values()) and lane["error"] is None
            and lane["identities"] == expected_identities(verb, name)
            for name, lane in results[verb]["lanes"].items()
        )
        for verb, names in VERBS.items()
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", type=pathlib.Path, required=True)
    parser.add_argument("--source-root", type=pathlib.Path, default=ROOT)
    parser.add_argument("--output", type=pathlib.Path, default=ROOT / ".audit/cpdb-02/verification.json")
    args = parser.parse_args()
    try:
        output = validate_output(args.output, args.profile)
        _, profile_hash = read_profile(args.profile)
        fixture = Fixture(args, profile_hash)
        fixture.install()
        results = collect(fixture)
        status = "passed" if passed(results) else "failed"
        report = {"kind": "cpdb02-database-connections", "status": status, "project": PROJECT, "run_id": fixture.run_id, "results": results}
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        counts = {state: sum(lane["status"] == state for result in results.values() for lane in result["lanes"].values()) for state in ("passed", "failed", "unknown")}
        print(json.dumps({"status": status, "lanes": counts, "report": str(output)}))
        return 0 if status == "passed" else 1
    except VerificationFailure as failure:
        print(json.dumps({"status": "refused", "reason": str(failure)}))
        return 2


if __name__ == "__main__":
    sys.exit(main())
