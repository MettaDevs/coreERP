"""Probe perilaku comparator; tidak membuka database atau memanggil aplikasi."""

from copy import deepcopy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from compare_posting import Comparison, input_hash, load_json


class PostingComparisonTest(unittest.TestCase):
    def setUp(self) -> None:
        self.comparison = Comparison()
        self.comparison.receipt_ids = ("0" * 26, "1" * 26)
        self.comparison.register("0" * 26, "1" * 26, "$receipt")

    def test_maps_generated_foreign_links_but_never_account_ids(self) -> None:
        left, right = "0" * 26, "1" * 26
        self.comparison.compare(left, right, "postings[0].input.source_document.id")
        self.assertEqual([], self.comparison.differences)
        self.comparison.compare(left, right, "postings[0].input.lines[0].account_id")
        self.assertEqual("postings[0].input.lines[0].account_id", self.comparison.differences[0]["path"])

    def test_preserves_null_absence_types_and_array_order(self) -> None:
        pairs = [({}, {"value": None}), ({}, {"value": "$ABSENT"}), (1, True),
                 ("0", "0.00"), ([1, 2], [2, 1]), (load_json("1.0000001"), load_json("1.0000002"))]
        for left, right in pairs:
            with self.subTest(left=left, right=right):
                comparison = Comparison()
                comparison.compare(left, right)
                self.assertTrue(comparison.differences)

    def test_standalone_surrogates_only_are_omitted(self) -> None:
        self.comparison.compare("0" * 26, "1" * 26, "lines[0].id")
        self.assertEqual([], self.comparison.differences)
        self.comparison.compare("first", "second", "lines[0].description")
        self.assertTrue(self.comparison.differences)

    def test_timestamp_format_stays_exact(self) -> None:
        path = "postings[0].payload.published_at"
        self.comparison.compare("2026-11-03T07:36:30+00:00", "2026-11-03T07:40:09+00:00", path)
        self.assertEqual([], self.comparison.differences)
        self.comparison.compare("2026-11-03T07:36:30+00:00", "2026-11-03T07:40:09Z", path)
        self.assertTrue(self.comparison.differences)

    def test_independent_hash_matches_frozen_php_publication(self) -> None:
        data = {
            "posting_type": "asset.acquisition",
            "legal_entity_id": "01m6g2sgvrndjjd3hjqw9kpgt7",
            "currency_code": "IDR",
            "posting_date": "2026-11-03",
            "document_date": "2026-11-03",
            "settlement_mode": "direct_payable",
            "vendor_id": "01m6g2sh1mr2zvftk0np494q08",
            "lines": [
                {"account_id": "01M6G2RVPJW0ADS6D17DQEW6R8", "debit": "1000000.00", "credit": "0"},
                {"account_id": "01M6G2RVPJW0ADS6D17DQEW6RA", "debit": "100000.00", "credit": "0"},
                {"account_id": "01M6G2RVPJW0ADS6D17DQEW6R9", "debit": "0", "credit": "1100000.00"},
            ],
        }
        for line in data["lines"]:
            line["org_unit_id"] = "01m6g2sgwccwkf2yfwg60tr2dn"
        posting = {"currency_decimals": 2, "input": data}
        expected = "cbd6316dc7e01fb6282d4bb83261e3c39857b6cad3d8913ed89b2d4f9105449e"
        self.assertEqual(expected, input_hash(posting))
        changed = deepcopy(posting)
        changed["input"]["posting_id"] = "AST-ACQ-" + "0" * 26
        changed["input"]["occurred_at"] = "2026-11-03T08:00:00+00:00"
        self.assertEqual(expected, input_hash(changed))
        changed["input"]["lines"][1]["account_id"] = data["lines"][0]["account_id"]
        self.assertNotEqual(expected, input_hash(changed))

    def test_nested_snapshot_types_stay_exact_in_the_oracle(self) -> None:
        self.comparison.check({"problems": 0}, {"problems": False}, "event.data")
        self.assertEqual("event.data", self.comparison.differences[0]["path"])


if __name__ == "__main__":
    unittest.main()
