#!/usr/bin/env python3
"""Fail when the internal API surface and its contract disagree.

Every route under `internal/v1` is called by another application. Nothing in the
test suite fails when one is missing from the contract -- that is precisely how
twelve endpoints ended up undocumented while every test stayed green. This check
is the only thing that notices.

Routes are read from the framework (`php artisan route:list --json`), not from the
text of `routes/api.php`. A route registered in a loop, or through a helper, never
appears there as a literal path, and a text match would report a clean run while
missing it.

The contract side is the combined bundle `contracts/openapi-internal.yaml`, assembled
by `contracts/bundle.py` from the split sources in `contracts/internal/`. Run
`python contracts/bundle.py --check` as well: it proves the bundle matches its sources.

Run from `apps/core`:

    python contracts/check-contract-coverage.py
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    sys.exit("PyYAML is required: pip install pyyaml")

CONTRACT = Path("contracts/openapi-internal.yaml")
PREFIX = "api/internal/v1/"
HTTP_METHODS = ("get", "post", "put", "patch", "delete")


def routes_in_code() -> set[tuple[str, str]]:
    env = dict(os.environ)
    # Only needed on machines whose PHP loads ext-opentelemetry; harmless elsewhere.
    env.setdefault("OTEL_PHP_DISABLED_INSTRUMENTATIONS", "all")
    result = subprocess.run(
        ["php", "artisan", "route:list", "--json", "--path=" + PREFIX.rstrip("/")],
        capture_output=True,
        text=True,
        env=env,
        check=False,
    )
    if result.returncode != 0:
        sys.exit("php artisan route:list failed:\n" + result.stderr + result.stdout)
    try:
        routes = json.loads(result.stdout)
    except json.JSONDecodeError:
        sys.exit("php artisan route:list did not return JSON:\n" + result.stdout[:2000])

    found: set[tuple[str, str]] = set()
    for route in routes:
        uri = route.get("uri", "")
        if not uri.startswith(PREFIX):
            continue
        for method in str(route.get("method", "")).split("|"):
            if method.lower() in HTTP_METHODS:
                found.add((method.upper(), "/" + uri[len(PREFIX):]))
    if not found:
        sys.exit("No routes found under /" + PREFIX + " -- the check would pass without checking anything.")
    return found


def routes_in_contract() -> set[tuple[str, str]]:
    spec = yaml.safe_load(CONTRACT.read_text(encoding="utf-8"))
    return {
        (method.upper(), path)
        for path, operations in (spec.get("paths") or {}).items()
        for method in operations
        if method in HTTP_METHODS
    }


def render(title: str, items: set[tuple[str, str]], hint: str) -> None:
    print(f"\n{title}")
    for method, path in sorted(items):
        print(f"  {method:6} /internal/v1{path}")
    print(f"  -> {hint}")


def main() -> int:
    if not CONTRACT.exists() or not Path("artisan").exists():
        sys.exit(f"Not found: {CONTRACT} or artisan. Run this from apps/core.")

    code = routes_in_code()
    contract = routes_in_contract()
    undocumented = code - contract
    orphaned = contract - code

    if not undocumented and not orphaned:
        print(f"OK: {len(code)} internal routes, all present in {CONTRACT}.")
        return 0

    print(f"Contract drift between the router and {CONTRACT}.")
    if undocumented:
        render(
            "Routes that other apps can call but nothing documents:",
            undocumented,
            "Add them under contracts/internal/ (a path file, and the root of every reader "
            "that may call it), then run `python contracts/bundle.py`. A caller cannot "
            "integrate against a promise that was never written down.",
        )
    if orphaned:
        render(
            "Documented routes that no longer exist:",
            orphaned,
            "Remove them, or restore the route. A contract that describes a missing "
            "endpoint is worse than no contract -- callers build against it.",
        )
    print(
        "\nSee the Contract decision gate in "
        ".agents/skills/coreerp-architecture/SKILL.md"
    )
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
