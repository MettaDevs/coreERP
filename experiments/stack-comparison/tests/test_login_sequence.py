"""Aturan pembanding urutan login; urutan statement dibuat di memori, tanpa database."""

from __future__ import annotations

import copy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from login_sequence import compare, shape, transactions

FAILED = [
    'select * from "sessions" where "id" = $1',
    'select * from "cache" where "key" in ($1)',
    'insert into "cache" ("key", "value", "expiration") values ($1, $2, $3) on conflict do nothing',
    "BEGIN",
    'select * from "cache" where "key" = $1 limit 1 for update',
    'update "cache" set "value" = $1 where "key" = $2',
    "COMMIT",
    'update "sessions" set "payload" = $1 where "id" = $2',
    'delete from "sessions" where "last_activity" <= $1',
]


def report(statements: list[str], cache: list | None = None, scenario: str = "login-failed") -> dict:
    shaped = [(7, shape(text)) for text in statements]
    rows = cache if cache is not None else [["laravel-cache-EMAIL|IP", "i:1;"]]
    return {"scenarios": {scenario: {"status": 422, **transactions(shaped)}}, "cache": {"rows": rows, "route_keys_verified": True}}


class LoginSequenceTests(unittest.TestCase):
    def test_same_obligations_with_other_sql_text_match(self):
        native = ["select * from sessions where id=$1", "select * from cache where key in ($1)",
                  "insert into cache(key,value,expiration) values($1,$2,$3) on conflict do nothing", "begin",
                  "select * from cache where key=$1 limit 1 for update", "update cache set value=$1 where key=$2", "commit",
                  "insert into sessions(id,payload) values($1,$2) on conflict(id) do update set payload=excluded.payload"]
        self.assertEqual([], compare(report(FAILED), report(native)))

    def test_increment_outside_a_transaction_fails(self):
        flattened = [text for text in FAILED if text not in {"BEGIN", "COMMIT"}]
        fields = {difference["field"] for difference in compare(report(FAILED), report(flattened))}
        self.assertIn("transactions", fields)

    def test_missing_row_lock_fails(self):
        unlocked = [text.replace(" for update", "") for text in FAILED]
        self.assertTrue(compare(report(FAILED), report(unlocked)))

    def test_cache_rows_must_match(self):
        differences = compare(report(FAILED), report(FAILED, [["laravel-cache-EMAIL|IP", "i:2;"]]))
        self.assertEqual(["cache"], [difference["field"] for difference in differences])

    def test_session_gc_is_ignored_but_id_delete_is_not(self):
        without_gc = [text for text in FAILED if "last_activity" not in text]
        self.assertEqual([], compare(report(FAILED), report(without_gc)))
        regenerated = FAILED + ['delete from "sessions" where "id" = $1']
        self.assertTrue(compare(report(FAILED), report(regenerated)))

    def test_new_session_read_is_optional_only_without_a_cookie(self):
        for scenario, optional in [("login-page", True), ("login-failed", False)]:
            skipped = [text for text in FAILED if '"sessions" where "id"' not in text]
            with self.subTest(scenario=scenario):
                self.assertEqual(optional, not compare(report(FAILED, scenario=scenario), report(skipped, scenario=scenario)))


if __name__ == "__main__":
    unittest.main()
