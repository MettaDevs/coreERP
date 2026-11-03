"""Fingerprint SQL pada cluster eksperimen, termasuk statements di fungsi/trigger."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

CONTAINER = "coreerp-stack-comparison-database-1"
DATABASE = "erp_compare"


def validate_database(database: str) -> None:
    if re.fullmatch(r"erp_compare(?:_[a-z0-9_]+)?", database) is None or len(database) > 63:
        raise ValueError("Nama database bukan namespace eksperimen ini.")


def sql(statement: str, database: str = "postgres") -> str:
    result = subprocess.run(
        ["docker", "exec", "-i", CONTAINER, "psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-U", "compare_admin", "-d", database],
        input=statement,
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout.strip()


def initialize() -> None:
    metadata = json.loads(sql("SELECT json_build_object('database',current_database(),'version',current_setting('server_version'),'track',current_setting('pg_stat_statements.track'));", DATABASE))
    if metadata["database"] != DATABASE or metadata["track"] != "all":
        raise RuntimeError("Observer hanya boleh memakai fixture erp_compare dengan nested tracking.")
    sql("CREATE EXTENSION IF NOT EXISTS pg_stat_statements;")
    print(json.dumps(metadata))


def reset() -> None:
    sql("SELECT pg_stat_statements_reset();")


def snapshot(database: str = DATABASE) -> list[dict]:
    validate_database(database)
    statement = """
        SELECT coalesce(json_agg(row_to_json(s)), '[]'::json) FROM (
          SELECT queryid::text, query, calls, rows, total_exec_time, total_plan_time,
                 shared_blks_hit, shared_blks_read, wal_records, wal_bytes::text,
                 toplevel
          FROM pg_stat_statements
          WHERE dbid = (SELECT oid FROM pg_database WHERE datname='__DATABASE__')
          ORDER BY toplevel DESC, calls DESC, queryid
        ) s;
    """
    return json.loads(sql(statement.replace("__DATABASE__", database)))


def difference(before: list[dict], after: list[dict]) -> list[dict]:
    previous = {(row["queryid"], row["toplevel"]): row for row in before}
    current_keys = {(row["queryid"], row["toplevel"]) for row in after}
    if any(key not in current_keys and row["calls"] > 0 for key, row in previous.items()):
        raise RuntimeError("Fingerprint SQL hilang. Statistik ter-reset atau ter-evict; capture tidak valid.")
    rows = []
    for row in after:
        old = previous.get((row["queryid"], row["toplevel"]), {})
        delta = dict(row)
        for key in ["calls", "rows", "total_exec_time", "total_plan_time", "shared_blks_hit", "shared_blks_read", "wal_records"]:
            delta[key] = row[key] - old.get(key, 0)
        delta["wal_bytes"] = str(int(row["wal_bytes"]) - int(old.get("wal_bytes", 0)))
        if delta["calls"] < 0:
            raise RuntimeError("Statistik direset saat pengambilan. Capture tidak valid.")
        if delta["calls"] > 0:
            rows.append(delta)
    return rows


def capture(output: Path, rows: list[dict] | None = None, database: str = DATABASE) -> None:
    if rows is None:
        rows = snapshot(database)
    for row in rows:
        template = re.sub(r"\s+", " ", row["query"]).strip()
        row["fingerprint"] = hashlib.sha256(template.encode()).hexdigest()
        row["query"] = template
    output.parent.mkdir(parents=True, exist_ok=True)
    evidence = {
        "database": database,
        "scope": "dedicated cluster; pg_stat_statements.track=all",
        "top_level_calls": sum(row["calls"] for row in rows if row["toplevel"]),
        "nested_calls": sum(row["calls"] for row in rows if not row["toplevel"]),
        "timing_disclosure": "Parent execution time contains nested work. Do not add parent and nested durations.",
        "limitations": "Aggregated successful statements. Failed query and execution order need separate evidence. Other fixture activity contaminates a capture.",
        "queries": rows,
    }
    output.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: value for key, value in evidence.items() if key != "queries"}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["init", "reset", "capture"])
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.action == "init":
        initialize()
    elif args.action == "reset":
        reset()
    elif args.output is None:
        parser.error("capture membutuhkan --output di folder private.")
    else:
        capture(args.output)
