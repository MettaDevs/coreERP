#!/usr/bin/env python3
"""Fail when the HTTP surface and its contract disagree.

Nothing in the test suite fails when a route is missing from the contract. That
is exactly how `PUT /api/v1/jenis-aset/{id}/models` shipped undocumented while
every test stayed green. This check is the only thing that notices.

Routes are read from Laravel itself rather than by matching text in
`routes/api.php`. Most master routes here are registered in a loop over a
`$masters` array, so their paths never appear as literals in the source; a
regular expression would report a clean run while missing eighty routes.

Run from the repository root:

    python contracts/check-contract-coverage.py
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    sys.exit("PyYAML is required: pip install pyyaml")

API_DIR = Path("api")
CONTRACT = Path("contracts/openapi.yaml")
ASYNC_CONTRACT = Path("contracts/asyncapi.yaml")
HTTP_METHODS = ("GET", "POST", "PUT", "PATCH", "DELETE")

# Framework surface, not a promise to another module.
IGNORED_PREFIXES = ("storage/", "up", "_ignition")

# Gaps that are known, owned, and deliberately still open. Entries here are
# printed on every run: the point is to name a gap, not to hide it. A silent
# allowlist would turn this check into a rubber stamp within a month.
#
# Permintaan pembelian aset only exists as a route shell; the behaviour it will
# promise has not been decided yet, and a contract written ahead of that
# decision would describe a shape callers cannot rely on. Documenting it is
# scheduled as its own piece of work.
DEFERRED: dict[tuple[str, str], str] = {
    ("GET", "/api/v1/permintaan-pembelian-aset/{id}"): "permintaan pembelian belum dirancang",
    ("PATCH", "/api/v1/permintaan-pembelian-aset/{id}"): "permintaan pembelian belum dirancang",
    ("POST", "/api/v1/permintaan-pembelian-aset/{id}/batal"): "permintaan pembelian belum dirancang",
}


def routes_from_laravel() -> list[tuple[str, str]]:
    result = subprocess.run(
        ["php", "artisan", "route:list", "--json"],
        cwd=API_DIR,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        sys.exit(
            "`php artisan route:list` failed. The check needs a bootable app:\n"
            + (result.stderr or result.stdout)
        )

    routes: list[tuple[str, str]] = []
    for route in json.loads(result.stdout):
        uri = route["uri"]
        if uri.startswith(IGNORED_PREFIXES):
            continue
        for method in route["method"].split("|"):
            if method in HTTP_METHODS:
                routes.append((method, "/" + uri.lstrip("/")))

    return routes


def enum_values(parameters: list, name: str) -> list[str] | None:
    """Allowed values for a path placeholder, when the contract pins them.

    `/api/v1/{lifecycleDocument}` documents four concrete resources through one
    templated path whose parameter carries an enum. Comparing that string
    literally against the router would report four real endpoints as
    undocumented, and a check that cries wolf is a check people learn to skip.
    """
    for parameter in parameters or []:
        if not isinstance(parameter, dict):
            continue
        if parameter.get("name") != name or parameter.get("in") != "path":
            continue
        values = (parameter.get("schema") or {}).get("enum")
        if isinstance(values, list) and values:
            return [str(value) for value in values]

    return None


def expand(path: str, parameters: list) -> set[str]:
    expanded = {path}
    for placeholder in re.findall(r"\{([^}]+)\}", path):
        values = enum_values(parameters, placeholder)
        if not values:
            continue
        expanded = {
            candidate.replace("{" + placeholder + "}", value)
            for candidate in expanded
            for value in values
        }

    return expanded


def paths_in_contract() -> set[tuple[str, str]]:
    spec = yaml.safe_load(CONTRACT.read_text(encoding="utf-8"))
    documented: set[tuple[str, str]] = set()
    for path, operations in (spec.get("paths") or {}).items():
        shared = operations.get("parameters") or []
        for method, operation in operations.items():
            if method.upper() not in HTTP_METHODS:
                continue
            own = operation.get("parameters") if isinstance(operation, dict) else []
            for concrete in expand(path, [*shared, *(own or [])]):
                documented.add((method.upper(), concrete))

    return documented


def render(title: str, items: set[tuple[str, str]], hint: str) -> None:
    print(f"\n{title}")
    for method, path in sorted(items):
        print(f"  {method:6} {path}")
    print(f"  -> {hint}")


def main() -> int:
    if not API_DIR.exists() or not CONTRACT.exists():
        sys.exit(f"Not found: {API_DIR} or {CONTRACT}. Run this from the repo root.")

    routes = routes_from_laravel()
    events = {route for route in routes if route[1].startswith("/api/internal/v1/")}
    public = {route for route in routes if route[1].startswith("/api/v1/")}
    unclassified = set(routes) - events - public

    contract = paths_in_contract()
    undocumented = public - contract
    orphaned = contract - public

    deferred = {route for route in undocumented if route in DEFERRED}
    undocumented -= deferred
    # An entry that no longer matches a live route is stale bookkeeping; it would
    # quietly excuse a future route that happens to reuse the same path.
    stale = {route for route in DEFERRED if route not in public}

    # Event receivers are contracted as channels in asyncapi.yaml, not as REST
    # paths. They are reported rather than skipped in silence: a surface that
    # nothing prints is a surface nobody checks.
    print(f"{len(public)} public routes checked against {CONTRACT}.")
    print(f"{len(events)} event receivers contracted in {ASYNC_CONTRACT}:")
    for method, path in sorted(events):
        print(f"  {method:6} {path}")

    if deferred:
        print(f"\n{len(deferred)} undocumented routes are deferred on purpose:")
        for route in sorted(deferred):
            print(f"  {route[0]:6} {route[1]}  -- {DEFERRED[route]}")
        print("  -> Remove the entry from DEFERRED once the contract is written.")

    if unclassified:
        render(
            "Routes under neither /api/v1 nor /api/internal/v1:",
            unclassified,
            "Decide which surface each one belongs to, then contract it there.",
        )

    if stale:
        render(
            "DEFERRED entries that match no live route:",
            stale,
            "Delete them. A stale exemption silently excuses whatever route "
            "later claims that path.",
        )

    if not undocumented and not orphaned and not unclassified and not stale:
        print("\nOK: no contract drift.")
        return 0

    print(f"\nContract drift between the running routes and {CONTRACT}.")
    if undocumented:
        render(
            "Routes that callers can reach but nothing documents:",
            undocumented,
            "Add them to contracts/src/paths/, then rebuild with "
            "`python contracts/bundle.py`. A caller cannot integrate against a "
            "promise that was never written down.",
        )
    if orphaned:
        render(
            "Documented routes that no longer exist:",
            orphaned,
            "Remove them, or restore the route. A contract that describes a "
            "missing endpoint is worse than no contract -- callers build "
            "against it.",
        )
    print(
        "\nSee the Contract decision gate in "
        ".agents/skills/coreerp-architecture/SKILL.md of the CoreERP repository."
    )
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
