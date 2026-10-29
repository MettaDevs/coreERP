"""Inventaris kandidat audit; kecocokan pola bukan bukti cacat perilaku."""

import argparse
import json
from pathlib import Path
import re
import subprocess


RULES = {
    "datatable_without_incremental": (
        {".tsx"},
        re.compile(r"<DataTable\b(?:(?!/>).)*?/>", re.DOTALL),
        lambda match, source: not re.search(r"\bincremental\s*=", match.group()),
    ),
    "page_based_query": (
        {".php"},
        re.compile(r"->(?:simplePaginate|paginate)\s*\("),
        lambda match, source: True,
    ),
    "sample_default": (
        {".php"},
        re.compile(r"\bDEFAULT_ITEMS\b"),
        lambda match, source: True,
    ),
    "toggle_boolean": (
        {".php"},
        re.compile(r"->\w+\s*=\s*!\s*\$"),
        lambda match, source: True,
    ),
    "toast_form_without_summary": (
        {".tsx"},
        re.compile(r"\b(?:toast\.error|toastSaveError)\s*\("),
        lambda match, source: "<form" in source and "FormErrorSummary" not in source,
    ),
}


def inspect(path, source):
    findings = []
    for rule, (extensions, pattern, qualifies) in RULES.items():
        if Path(path).suffix not in extensions:
            continue
        for match in pattern.finditer(source):
            if qualifies(match, source):
                findings.append({"rule": rule, "path": path, "line": source.count("\n", 0, match.start()) + 1})
    return findings


def excluded(path, prefixes=()):
    parts = set(Path(path).parts)
    return any(path.startswith(prefix) for prefix in prefixes) or bool(parts & {"vendor", "node_modules", "tests", "Fixtures", "database", "Analytics", "analytics", "analitik"})


def self_test():
    assert inspect("ui/List.tsx", "<DataTable data={rows} />")[0]["rule"] == "datatable_without_incremental"
    assert not inspect("ui/List.tsx", "<DataTable incremental={list} data={rows} />")
    assert inspect("Controller.php", "$query->paginate(15);")[0]["rule"] == "page_based_query"
    assert inspect("ui/Edit.tsx", "<form>toast.error('x')</form>")[0]["rule"] == "toast_form_without_summary"
    assert not inspect("ui/Edit.tsx", "<form><FormErrorSummary />toast.error('x')</form>")
    assert excluded("apps/core/app/Platform/Analytics/Report.php")
    assert excluded("modules/apperp/skipped/Example.php", ["modules/apperp/skipped/"])
    print("PASS scanner positive and negative controls; candidates still require caller/behavior review")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--exclude-prefix", action="append", default=[], help="Prefix repo yang dikeluarkan dari scope audit ini; boleh diulang")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    root = Path(__file__).resolve().parents[1]
    paths = subprocess.check_output(["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z", "apps/core/app", "apps/core/resources/js", "apps/control-plane/app", "apps/control-plane/resources/js", "modules/apperp"], cwd=root).decode().split("\0")
    sources = [path for path in paths if path and not excluded(path, args.exclude_prefix) and Path(path).suffix in {".php", ".ts", ".tsx"}]
    candidates = []
    for path in sources:
        candidates.extend(inspect(path, (root / path).read_text(encoding="utf-8")))
    result = {
        "head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip(),
        "source_files": len(sources),
        "excluded": "analytics, tests, fixtures, migrations, dependencies; schema and ownership require separate audit",
        "excluded_prefixes": args.exclude_prefix,
        "classification": "Candidates, not confirmed defects; static or child-editor DataTable can be valid",
        "candidates": candidates,
    }
    rendered = json.dumps(result, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")


if __name__ == "__main__":
    main()
