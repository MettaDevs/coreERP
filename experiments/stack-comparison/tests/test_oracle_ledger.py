"""Pengikatan ledger acknowledgement ke fixture; tidak membuka database."""

from __future__ import annotations

import copy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from sql_oracle import bind, flow_acknowledgement

RECEIPT, ASSETS = "0" * 26, ["1" * 26, "2" * 26, "3" * 26]
ACCOUNT = {"index": 0, "user_id": 7, "tenant_id": "T", "legal_entity_id": "L", "membership_id": "M"}
FIXTURE = {
    "accounts": [{"email": "unit@example.invalid", "user_id": 7, "tenant_id": "T", "legal_entity_id": "L", "membership_id": "M"}],
    "tenants": [{"tenant_id": "T", "receipt_body": {"details": [{"jumlah": 3}]}}],
}


def flow() -> dict:
    receipt = {"id": RECEIPT, "creation_key": "compare-unit", "kode": "R1", "status": "selesai", "version": 5,
               "posting": {"posting_id": "AST-ACQ-" + RECEIPT}}
    return {"account": dict(ACCOUNT), "events": [
        {"operation": "receipt-create", "status": 201, "body": {"data": {**receipt, "status": "draft", "version": 1}}},
        {"operation": "receipt-complete", "status": 200, "body": {"data": receipt}},
        {"operation": "receipt-assets", "status": 200, "body": {"data": [{"id": value, "kode": "A" + str(index)} for index, value in enumerate(ASSETS)]}},
    ]}


class LedgerTests(unittest.TestCase):
    def test_flow_binds_fixture_input_not_generator_payload(self):
        entry = bind([flow_acknowledgement(flow())], FIXTURE)[0]
        self.assertEqual((7, "T", RECEIPT, 5, ASSETS), (entry["user_id"], entry["tenant_id"], entry["receipt_id"], entry["version"], entry["asset_ids"]))
        self.assertIs(entry["body"], FIXTURE["tenants"][0]["receipt_body"])

    def test_missing_or_repeated_acknowledgement_fails(self):
        for operation in ["receipt-create", "receipt-complete", "receipt-assets"]:
            for repeat in [0, 2]:
                run = flow()
                event = next(item for item in run["events"] if item["operation"] == operation)
                run["events"] = [item for item in run["events"] if item is not event] + [event] * repeat
                with self.subTest(operation=operation, repeat=repeat), self.assertRaises(ValueError):
                    flow_acknowledgement(run)

    def test_account_must_match_fixture(self):
        for key, value in [("user_id", 8), ("tenant_id", "U"), ("membership_id", "N")]:
            entry = flow_acknowledgement(flow())
            entry["account"][key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                bind([entry], FIXTURE)

    def test_one_job_cannot_be_acknowledged_twice(self):
        entry = flow_acknowledgement(flow())
        with self.assertRaises(ValueError):
            bind([entry, copy.deepcopy(entry)], FIXTURE)

    def test_identifiers_must_be_ulids_and_keys_safe(self):
        for key, value in [("receipt_id", "not-a-ulid"), ("asset_ids", ["x"]), ("creation_key", "x'; DROP")]:
            entry = flow_acknowledgement(flow())
            entry[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                bind([entry], FIXTURE)


if __name__ == "__main__":
    unittest.main()
