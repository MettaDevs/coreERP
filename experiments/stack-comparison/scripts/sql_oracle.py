"""Oracle SQL independen. Tidak mengimpor kode aplikasi atau menjalankan PHP."""

from __future__ import annotations

import argparse
import base64
import json
from pathlib import Path

from sql_observer import sql, validate_database


def query(fixture: dict) -> str:
    encoded = base64.b64encode(json.dumps(fixture, separators=(",", ":")).encode()).decode()
    return """
WITH input AS (
 SELECT convert_from(decode('""" + encoded + """','base64'),'UTF8')::jsonb AS data
), f AS (
 SELECT item.*, data->>'module_id' AS module_id
 FROM input, jsonb_to_recordset(data->'tenants') AS item(
   tenant_id text, legal_entity_id text, vendor_id text, book_id text, profile_id text,
   attribute_id text, attribute_value text, business_unit_code text
 )
), r AS (
 SELECT r.*, f.book_id, f.profile_id, f.attribute_id, f.attribute_value, f.business_unit_code, f.module_id
 FROM aset_tr_penerimaan_aset r JOIN f USING(tenant_id)
 WHERE r.deleted_at IS NULL
), d AS (
 SELECT penerimaan_aset_id AS receipt_id, sum(jumlah) AS quantity,
   sum(round(nilai_per_unit*jumlah,2)) AS value,
   sum(round(ppn_per_unit*jumlah,2)) AS tax
 FROM aset_tr_penerimaan_aset_details GROUP BY penerimaan_aset_id
), completed AS (
 SELECT r.*, d.quantity, d.value, d.tax
 FROM r JOIN d ON d.receipt_id=r.id WHERE r.status='selesai'
), asset AS (
 SELECT a.*, c.profile_id AS expected_profile_id, c.book_id AS expected_book_id,
   c.attribute_id AS expected_attribute_id, c.attribute_value AS expected_attribute_value,
   c.updated_by_user_id AS expected_actor_id, c.module_id,
   line.jumlah AS line_quantity, round(line.nilai_per_unit*line.jumlah,2) AS line_value,
   substring(a.creation_key FROM ':([0-9]+)$')::integer AS unit_index
 FROM aset_tr_aset a JOIN completed c ON c.id=a.penerimaan_aset_id
 JOIN aset_tr_penerimaan_aset_details line ON line.id=a.penerimaan_aset_detail_id
 WHERE a.deleted_at IS NULL
), failures AS (
 SELECT 'foreign_tenant' AS label, count(*) AS violations FROM tenants
 WHERE id NOT IN (SELECT tenant_id FROM f)
 UNION ALL SELECT 'nonempty_completed_work', CASE WHEN count(*)>0 THEN 0 ELSE 1 END FROM completed
 UNION ALL SELECT 'completed_header_version', count(*) FROM completed WHERE version<3
 UNION ALL SELECT 'completed_asset_count', count(*) FROM completed c
 WHERE (SELECT count(*) FROM asset a WHERE a.penerimaan_aset_id=c.id)<>c.quantity
 UNION ALL SELECT 'asset_value_sum', count(*) FROM completed c
 WHERE (SELECT coalesce(sum(acquisition_value),0) FROM asset a WHERE a.penerimaan_aset_id=c.id)<>c.value
 UNION ALL SELECT 'asset_allocation', count(*) FROM asset
 WHERE unit_index IS NULL OR acquisition_value <>
   (floor(line_value*100/line_quantity) + CASE WHEN unit_index<=mod(line_value*100,line_quantity) THEN 1 ELSE 0 END)/100
 UNION ALL SELECT 'asset_scope', count(*) FROM asset a JOIN completed c ON c.id=a.penerimaan_aset_id
 WHERE a.tenant_id<>c.tenant_id OR a.legal_entity_id<>c.legal_entity_id
 UNION ALL SELECT 'asset_actor', count(*) FROM asset
 WHERE created_by_user_id IS DISTINCT FROM expected_actor_id
 UNION ALL SELECT 'asset_actual_state', count(*) FROM asset
 WHERE is_budgeted OR lifecycle_state<>'received'
 UNION ALL SELECT 'draft_no_completion', count(*) FROM r WHERE status='draft' AND (
   EXISTS(SELECT 1 FROM aset_tr_aset a WHERE a.penerimaan_aset_id=r.id) OR
   EXISTS(SELECT 1 FROM finance_postings p WHERE p.posting_id='AST-ACQ-'||r.id AND p.tenant_id=r.tenant_id))
 UNION ALL SELECT 'one_pending_posting', count(*) FROM completed c WHERE
   (SELECT count(*) FROM finance_postings p WHERE p.tenant_id=c.tenant_id AND p.posting_id='AST-ACQ-'||c.id AND p.status='pending')<>1
 UNION ALL SELECT 'posting_scope', count(*) FROM completed c JOIN finance_postings p ON p.posting_id='AST-ACQ-'||c.id AND p.tenant_id=c.tenant_id
 WHERE p.legal_entity_id<>c.legal_entity_id OR p.vendor_id IS DISTINCT FROM c.vendor_id OR p.settlement_mode<>'direct_payable'
 UNION ALL SELECT 'balanced_journal', count(*) FROM completed c JOIN finance_postings p ON p.posting_id='AST-ACQ-'||c.id AND p.tenant_id=c.tenant_id
 WHERE (SELECT coalesce(sum(debit),0) FROM finance_posting_lines l WHERE l.finance_posting_id=p.id)<>c.value+c.tax
    OR (SELECT coalesce(sum(credit),0) FROM finance_posting_lines l WHERE l.finance_posting_id=p.id)<>c.value+c.tax
 UNION ALL SELECT 'journal_line_sides_dimensions', count(*) FROM finance_posting_lines l
 JOIN finance_postings p ON p.id=l.finance_posting_id
 JOIN completed c ON p.posting_id='AST-ACQ-'||c.id AND p.tenant_id=c.tenant_id
 WHERE (l.debit>0)=(l.credit>0) OR l.business_unit_code IS DISTINCT FROM c.business_unit_code
 UNION ALL SELECT 'one_posted_book', count(*) FROM asset a WHERE
   (SELECT count(*) FROM aset_tr_buku_aset b WHERE b.tenant_id=a.tenant_id AND b.aset_id=a.id AND b.buku_id=a.expected_book_id AND b.depreciate AND b.useful_life_periods=48 AND b.depreciation_profile_id=a.expected_profile_id)<>1
 UNION ALL SELECT 'ledger_matches_asset', count(*) FROM asset a WHERE
   (SELECT count(*) FROM aset_tr_ledger_aset l JOIN aset_tr_buku_aset b ON b.id=l.buku_aset_id
    WHERE l.tenant_id=a.tenant_id AND l.aset_id=a.id AND b.buku_id=a.expected_book_id
      AND l.posting_type='acquisition' AND l.source_id=a.penerimaan_aset_id AND l.acquisition_delta=a.acquisition_value)<>1
 UNION ALL SELECT 'typed_attribute', count(*) FROM asset a WHERE
   (SELECT count(*) FROM aset_tr_aset_atribut t WHERE t.tenant_id=a.tenant_id AND t.aset_id=a.id AND t.tipe_atribut_id=a.expected_attribute_id AND t.nilai_number=a.expected_attribute_value::numeric)<>1
 UNION ALL SELECT 'attribute_locked', count(*) FROM f JOIN aset_m_tipe_atribut t ON t.id=f.attribute_id AND t.tenant_id=f.tenant_id WHERE NOT t.data_type_locked AND EXISTS(SELECT 1 FROM completed c WHERE c.tenant_id=f.tenant_id)
 UNION ALL SELECT 'one_placement', count(*) FROM asset a WHERE
   (SELECT count(*) FROM aset_tr_penempatan_aset p WHERE p.tenant_id=a.tenant_id AND p.aset_id=a.id)<>1
 UNION ALL SELECT 'posting_published_once', count(*) FROM completed c JOIN finance_postings p ON p.posting_id='AST-ACQ-'||c.id AND p.tenant_id=c.tenant_id WHERE
   (SELECT count(*) FROM finance_posting_events e WHERE e.tenant_id=c.tenant_id AND e.finance_posting_id=p.id AND e.event='published')<>1
 UNION ALL SELECT 'asset_number_issued', count(*) FROM asset a WHERE NOT EXISTS(
   SELECT 1 FROM number_sequence_issues i JOIN tenant_number_sequences s ON s.id=i.sequence_id
   JOIN app_number_sequence_references ref ON ref.id=s.reference_id
   WHERE s.tenant_id=a.tenant_id AND ref.code=a.module_id||'.asset-code'
     AND i.formatted_value=a.kode AND i.idempotency_key='aset:'||a.creation_key)
 UNION ALL SELECT 'receipt_number_issued', count(*) FROM r WHERE NOT EXISTS(
   SELECT 1 FROM number_sequence_issues i JOIN tenant_number_sequences s ON s.id=i.sequence_id
   JOIN app_number_sequence_references ref ON ref.id=s.reference_id
   WHERE s.tenant_id=r.tenant_id AND ref.code=r.module_id||'.penerimaan-aset'
     AND i.formatted_value=r.kode AND i.idempotency_key='penerimaan-aset:'||r.creation_key)
 UNION ALL SELECT 'duplicate_asset_code', count(*) FROM (
   SELECT tenant_id,legal_entity_id,kode FROM aset_tr_aset WHERE deleted_at IS NULL GROUP BY 1,2,3 HAVING count(*)>1
 ) duplicates
 UNION ALL SELECT 'duplicate_receipt_code', count(*) FROM (
   SELECT tenant_id,legal_entity_id,kode FROM aset_tr_penerimaan_aset WHERE deleted_at IS NULL GROUP BY 1,2,3 HAVING count(*)>1
 ) duplicates
)
SELECT json_build_object('checks',(SELECT count(*) FROM failures),
 'completed_receipts',(SELECT count(*) FROM completed),
 'failures',coalesce((SELECT json_agg(row_to_json(failures)) FROM failures WHERE violations>0),'[]'::json));
"""


def evaluate(fixture: dict, database: str, selftest: bool = False) -> dict:
    validate_database(database)
    statement = query(fixture)
    if selftest:
        statement = """
BEGIN;
UPDATE aset_tr_aset SET acquisition_value=acquisition_value+0.01
WHERE id=(SELECT a.id FROM aset_tr_aset a JOIN aset_tr_penerimaan_aset r ON r.id=a.penerimaan_aset_id
          WHERE r.status='selesai' AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1);
""" + statement + "\nROLLBACK;"
    result = json.loads(sql(statement, database))
    result["database"] = database
    result["selftest"] = selftest
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixture", required=True, type=Path)
    parser.add_argument("--database", required=True)
    parser.add_argument("--selftest", action="store_true")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    fixture = json.loads(args.fixture.read_text(encoding="utf-8"))
    result = evaluate(fixture, args.database, args.selftest)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result))
    if args.selftest:
        detected = {failure["label"] for failure in result["failures"]}
        if not {"asset_value_sum", "asset_allocation", "ledger_matches_asset"}.issubset(detected):
            raise SystemExit("Oracle tidak mendeteksi semua efek perubahan satu sen.")
        restored = evaluate(fixture, args.database)
        if restored["failures"]:
            raise SystemExit("Rollback selftest tidak mengembalikan fixture bersih.")
        print(json.dumps({"selftest_detected_corruption": True, "rollback_restored": True}))
    elif result["failures"]:
        raise SystemExit(1)
