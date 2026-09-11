#!/usr/bin/env python3
"""Fail when the internal API surface and its contract disagree.

Every route under `internal/v1` is called by another application. Nothing in the
test suite fails when one is missing from the contract -- that is precisely how
twelve endpoints ended up undocumented while every test stayed green. This check
is the only thing that notices.

Run from `apps/core`:

    python contracts/check-contract-coverage.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    sys.exit("PyYAML is required: pip install pyyaml")

ROUTES = Path("routes/api.php")
CONTRACT = Path("contracts/openapi-internal.yaml")
HTTP_METHODS = ("get", "post", "put", "patch", "delete")

ROUTE_PATTERN = re.compile(
    r"Route::(" + "|".join(HTTP_METHODS) + r")\(\s*'([^']+)'",
    re.IGNORECASE,
)


def routes_in_code() -> set[tuple[str, str]]:
    source = ROUTES.read_text(encoding="utf-8")
    return {
        (match.group(1).upper(), "/" + match.group(2).lstrip("/"))
        for match in ROUTE_PATTERN.finditer(source)
    }


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
    for path in (ROUTES, CONTRACT):
        if not path.exists():
            sys.exit(f"Not found: {path}. Run this from apps/core.")

    code = routes_in_code()
    contract = routes_in_contract()
    undocumented = code - contract
    orphaned = contract - code

    if not undocumented and not orphaned:
        print(f"OK: {len(code)} internal routes, all present in {CONTRACT}.")
        return 0

    print(f"Contract drift between {ROUTES} and {CONTRACT}.")
    if undocumented:
        render(
            "Routes that other apps can call but nothing documents:",
            undocumented,
            "Add them to the contract. A caller cannot integrate against a promise "
            "that was never written down.",
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
