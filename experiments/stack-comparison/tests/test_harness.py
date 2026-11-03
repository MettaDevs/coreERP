from __future__ import annotations

import copy
import json
from pathlib import Path
import sys
import unittest

SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))

from compare_http import Comparison, compare_runs
from sql_observer import difference


def valid_run(offset: int = 0) -> dict:
    profile = json.loads((SCRIPTS.parent / "profile.json").read_text())
    record_id = f"{100 + offset:026d}"
    details = [{"id": f"{200 + offset:026d}"}]
    assets = [{"id": f"{300 + offset + i:026d}", "kode": f"A{i}", "penerimaan_aset_detail_id": f"{201 + offset:026d}"} for i in range(3)]
    events = []
    for operation in profile["main_flow"][:-1] + ["asset-show"] * 3:
        if operation == "receipt-list":
            body = {"data": [], "next_cursor": None}
        elif operation == "receipt-preview":
            body = {"data": {"status": "pending"}}
        elif operation in {"receipt-assets", "asset-list"}:
            body = {"data": copy.deepcopy(assets)}
        elif operation == "asset-show":
            body = {"data": copy.deepcopy(assets[sum(e["operation"] == "asset-show" for e in events)])}
        else:
            if operation == "receipt-replace-lines":
                details = [{"id": f"{201 + offset:026d}"}]
            body = {"data": {"id": record_id, "creation_key": "compare-unit", "details": copy.deepcopy(details), "created_at": "2026-11-03 00:00:00", "updated_at": "2026-11-03 00:00:00", "amount": "1000000.00"}}
        events.append({"operation": operation, "status": 201 if operation == "receipt-create" else 200, "headers": {}, "body": body, "started_at": "2026-11-03T00:00:00+00:00", "finished_at": "2026-11-03T00:00:20+00:00"})
    account = {"index": 0, "user_id": 1, "tenant_id": "unit-tenant", "legal_entity_id": "unit-company", "membership_id": "unit-membership"}
    return {"source_sha": profile["source_sha"], "profile_id": profile["id"], "fixture_id": "unit", "fixture_hash": "unit-fixture-hash", "run_id": "unit", "account": account, "authentication": [{"status": 200}] * 3, "events": events}


class HarnessTests(unittest.TestCase):
    def test_complete_equivalent_profile_accepts_bijective_generated_ids(self):
        self.assertEqual(compare_runs(valid_run(), valid_run(10))["verdict"], "VERIFIED")

    def test_empty_and_partial_profiles_fail(self):
        for events in [[], valid_run()["events"][:1]]:
            run = valid_run()
            run["events"] = events
            self.assertEqual(compare_runs(run, copy.deepcopy(run))["verdict"], "NOT VERIFIED")

    def test_source_profile_fixture_run_and_auth_must_match(self):
        for key in ["source_sha", "profile_id", "fixture_id", "fixture_hash", "run_id"]:
            other = valid_run(10)
            other[key] = "different"
            self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED", key)
        other = valid_run(10)
        other["authentication"][0] = {"status": 401}
        self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED")
        for account in [None, {**valid_run()["account"], "index": 1, "user_id": 2}]:
            other = valid_run(10)
            other["account"] = account
            self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED", account)

    def test_money_types_and_missing_fields_are_not_normalized(self):
        for replacement in [999999.99, "999999.99", None]:
            other = valid_run(10)
            other["events"][1]["body"]["data"]["amount"] = replacement
            self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED")
        other = valid_run(10)
        del other["events"][1]["body"]["data"]["amount"]
        self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED")

    def test_readback_cannot_change_stored_timestamps(self):
        for field in ["created_at", "updated_at"]:
            other = valid_run(10)
            other["events"][2]["body"]["data"][field] = "2026-11-03 00:00:09"
            self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED", field)

    def test_timestamp_freshness_is_required(self):
        other = valid_run(10)
        for event in other["events"]:
            if isinstance(event["body"].get("data"), dict) and "created_at" in event["body"]["data"]:
                event["body"]["data"]["created_at"] = "1970-01-01 00:00:00"
        self.assertEqual(compare_runs(valid_run(), other)["verdict"], "NOT VERIFIED")

    def test_generated_record_ids_cannot_be_reused(self):
        comparison = Comparison()
        comparison.register(f"{1:026d}", f"{2:026d}", "$receipt")
        with self.assertRaises(ValueError):
            comparison.register(f"{3:026d}", f"{2:026d}", "$detail")

    def test_lost_query_stats_are_inconclusive_not_zero_work(self):
        with self.assertRaises(RuntimeError):
            difference([{"queryid": "1", "toplevel": True, "calls": 1}], [])


if __name__ == "__main__":
    unittest.main()
