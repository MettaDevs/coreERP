#!/usr/bin/env python3
"""Ubah sample metric oracle k6 menjadi CSV untuk verify.sql."""

import argparse
import csv
import json
import sys
from pathlib import Path


FIELDS = (
    "fixture_id",
    "run_id",
    "scenario",
    "query_code",
    "metric",
    "tenant_id",
    "user_email",
    "access_kind",
    "group_key",
    "currency_code",
    "observed_value",
)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inputs", nargs="+", type=Path, help="berkas JSON output k6")
    parser.add_argument("--output", required=True, type=Path, help="CSV yang dibaca oracle SQL")
    args = parser.parse_args()

    rows = []

    for path in args.inputs:
        with path.open(encoding="utf-8") as source:
            for line_number, line in enumerate(source, start=1):
                try:
                    point = json.loads(line)
                except json.JSONDecodeError as error:
                    raise SystemExit(f"{path}:{line_number}: JSON rusak: {error}") from error

                if point.get("type") != "Point" or point.get("metric") != "analytics_oracle_value":
                    continue

                tags = point.get("data", {}).get("tags") or {}
                missing = [field for field in FIELDS[:-1] if field not in tags]

                if missing:
                    raise SystemExit(f"{path}:{line_number}: tag oracle hilang: {', '.join(missing)}")

                rows.append({
                    **{field: tags[field] for field in FIELDS[:-1]},
                    "observed_value": point["data"]["value"],
                })

    if not rows:
        raise SystemExit("Tidak ada sample analytics_oracle_value di berkas k6.")

    args.output.parent.mkdir(parents=True, exist_ok=True)

    with args.output.open("w", encoding="utf-8", newline="") as target:
        writer = csv.DictWriter(target, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(rows)

    print(f"{len(rows)} sample oracle ditulis ke {args.output}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
