<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_tr_ledger_aset', function (Blueprint $table): void {
            // Identitas baris internal, bukan nomor dokumen bisnis.
            $table->uuid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('aset_id');
            $table->ulid('buku_aset_id');
            $table->ulid('buku_id')->nullable();
            $table->ulid('legal_entity_id');
            $table->ulid('responsible_org_unit_id')->nullable();
            $table->ulid('group_aset_id')->nullable();
            $table->string('asset_code', 120);
            $table->string('asset_name', 255);
            $table->string('book_code', 120);
            $table->string('currency_code', 3);
            $table->date('posting_date')->nullable();
            $table->string('posting_type', 40);
            $table->string('source_type', 80);
            $table->string('source_id', 120);
            $table->string('document_number', 120);
            $table->text('description')->nullable();
            foreach (['acquisition_delta', 'accumulated_delta', 'write_down_delta', 'appreciation_delta', 'amount'] as $column) {
                $table->decimal($column, 24, 2)->default(0);
            }
            $table->boolean('is_reconstructed')->default(false);
            $table->softDeletes();
            $table->timestamps();
            AuditColumns::add($table);
            $table->index(['tenant_id', 'posting_date'], 'aset_ledger_date_idx');
            $table->index(['tenant_id', 'aset_id', 'buku_aset_id'], 'aset_ledger_book_idx');
            $table->foreign(['tenant_id', 'aset_id'], 'aset_ledger_asset_fk')->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'buku_aset_id'], 'aset_ledger_book_fk')->references(['tenant_id', 'id'])->on('aset_tr_buku_aset')->restrictOnDelete();
        });
        AuditColumns::attach('aset_tr_ledger_aset');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION aset_capture_book_ledger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    asset_row aset_tr_aset%ROWTYPE;
    context jsonb := coalesce(nullif(current_setting('coreerp.fa_ledger_context', true), '')::jsonb, '{}'::jsonb);
    acq numeric := 0; acc numeric := 0; wd numeric := 0; ap numeric := 0;
    entry_type text; entry_date date;
BEGIN
    SELECT * INTO STRICT asset_row FROM aset_tr_aset WHERE tenant_id = NEW.tenant_id AND id = NEW.aset_id;
    IF TG_OP = 'INSERT' THEN
        acq := NEW.acquisition_value; acc := NEW.accumulated_depreciation;
        wd := NEW.write_down_amount; ap := NEW.appreciation_amount;
        entry_type := 'acquisition';
    ELSIF OLD.status <> 'closed' AND NEW.status = 'closed' THEN
        acq := -OLD.acquisition_value; acc := -OLD.accumulated_depreciation;
        wd := -OLD.write_down_amount; ap := -OLD.appreciation_amount;
        entry_type := 'disposal';
    ELSE
        acq := NEW.acquisition_value - OLD.acquisition_value;
        acc := NEW.accumulated_depreciation - OLD.accumulated_depreciation;
        wd := NEW.write_down_amount - OLD.write_down_amount;
        ap := NEW.appreciation_amount - OLD.appreciation_amount;
        entry_type := CASE WHEN acq <> 0 THEN 'acquisition_correction'
            WHEN acc <> 0 THEN 'depreciation' WHEN wd <> 0 THEN 'write_down'
            WHEN ap <> 0 THEN 'appreciation' ELSE 'correction' END;
    END IF;
    IF acq = 0 AND acc = 0 AND wd = 0 AND ap = 0 THEN RETURN NEW; END IF;
    entry_type := coalesce(context->>'type', entry_type);
    entry_date := coalesce((context->>'date')::date, NEW.closed_on,
        CASE WHEN TG_OP = 'INSERT' THEN asset_row.acquired_on END, CURRENT_DATE);
    INSERT INTO aset_tr_ledger_aset(id, tenant_id, aset_id, buku_aset_id, buku_id,
        legal_entity_id, responsible_org_unit_id, group_aset_id, asset_code, asset_name,
        book_code, currency_code, posting_date, posting_type, source_type, source_id,
        document_number, description, acquisition_delta, accumulated_delta, write_down_delta,
        appreciation_delta, amount, created_at, updated_at)
    VALUES (gen_random_uuid(), NEW.tenant_id, NEW.aset_id, NEW.id, NEW.buku_id,
        asset_row.legal_entity_id, asset_row.responsible_org_unit_id, asset_row.group_aset_id,
        asset_row.kode, asset_row.nama, NEW.book_code, asset_row.currency_code,
        entry_date, entry_type, coalesce(context->>'source_type', 'asset-book'),
        coalesce(context->>'source_id', NEW.id), coalesce(context->>'document_number', asset_row.kode),
        context->>'description', acq, acc, wd, ap, acq - acc - wd + ap, now(), now());
    RETURN NEW;
END $$;
CREATE TRIGGER aset_book_ledger AFTER INSERT OR UPDATE ON aset_tr_buku_aset
FOR EACH ROW EXECUTE FUNCTION aset_capture_book_ledger();

CREATE OR REPLACE FUNCTION aset_capture_group_ledger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    book_row aset_tr_buku_aset%ROWTYPE;
    context jsonb := coalesce(nullif(current_setting('coreerp.fa_ledger_context', true), '')::jsonb, '{}'::jsonb);
    target_group text; multiplier integer;
BEGIN
    IF NEW.group_aset_id IS NOT DISTINCT FROM OLD.group_aset_id THEN RETURN NEW; END IF;
    FOR book_row IN SELECT * FROM aset_tr_buku_aset WHERE tenant_id = NEW.tenant_id AND aset_id = NEW.id AND status = 'active' LOOP
        FOR multiplier IN SELECT unnest(ARRAY[-1,1]) LOOP
            target_group := CASE WHEN multiplier = -1 THEN OLD.group_aset_id ELSE NEW.group_aset_id END;
            INSERT INTO aset_tr_ledger_aset(id, tenant_id, aset_id, buku_aset_id, buku_id,
                legal_entity_id, responsible_org_unit_id, group_aset_id, asset_code, asset_name,
                book_code, currency_code, posting_date, posting_type, source_type, source_id,
                document_number, description, acquisition_delta, accumulated_delta, write_down_delta,
                appreciation_delta, amount, created_at, updated_at)
            VALUES (gen_random_uuid(), NEW.tenant_id, NEW.id, book_row.id, book_row.buku_id,
                NEW.legal_entity_id, NEW.responsible_org_unit_id, target_group, NEW.kode, NEW.nama,
                book_row.book_code, NEW.currency_code, coalesce((context->>'date')::date, CURRENT_DATE),
                'reclassification', coalesce(context->>'source_type', 'asset'), coalesce(context->>'source_id', NEW.id),
                coalesce(context->>'document_number', NEW.kode), context->>'description',
                multiplier * book_row.acquisition_value, multiplier * book_row.accumulated_depreciation,
                multiplier * book_row.write_down_amount, multiplier * book_row.appreciation_amount,
                multiplier * book_row.net_book_value, now(), now());
        END LOOP;
    END LOOP;
    RETURN NEW;
END $$;
CREATE TRIGGER aset_group_ledger AFTER UPDATE OF group_aset_id ON aset_tr_aset
FOR EACH ROW EXECUTE FUNCTION aset_capture_group_ledger();

CREATE OR REPLACE FUNCTION aset_guard_ledger_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Catatan ledger aset tidak dapat diubah atau dihapus; buat transaksi pembalik.';
END $$;
CREATE TRIGGER aset_ledger_immutable BEFORE UPDATE OR DELETE ON aset_tr_ledger_aset
FOR EACH ROW EXECUTE FUNCTION aset_guard_ledger_history();
SQL);
    }

    public function down(): void
    {
        // Riwayat tetap disimpan ketika image diturunkan. Versi lama dapat mengabaikan ledger.
    }
};
