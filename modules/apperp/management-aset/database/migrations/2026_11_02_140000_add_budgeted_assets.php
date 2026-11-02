<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @kompatibel-mundur Hanya melonggarkan nullable tanggal/mata uang tanpa mengganti tipe atau nilai baris aktual lama. Kolom baru memiliki default/nullable; buku dan ledger aktual untuk record rencana ditolak database, termasuk bila image lama dipakai kembali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_aset', function (Blueprint $table): void {
            $table->boolean('is_budgeted')->default(false);
            $table->date('acquired_on')->nullable()->change();
            $table->string('currency_code', 3)->nullable()->change();
        });
        Schema::table('aset_tr_perencanaan_aset_details', function (Blueprint $table): void {
            $table->ulid('planned_asset_id')->nullable();
            $table->string('planned_asset_code')->nullable();
            $table->foreign(['tenant_id', 'planned_asset_id'], 'asset_plan_selected_asset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION aset_guard_budgeted_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.is_budgeted IS DISTINCT FROM OLD.is_budgeted THEN
        RAISE EXCEPTION 'Jenis aset rencana/aktual tidak dapat diubah pada kartu tersimpan.';
    END IF;
    IF NEW.is_budgeted <> (NEW.lifecycle_state = 'budgeted') THEN
        RAISE EXCEPTION 'Status aset rencana tidak sesuai.';
    END IF;
    IF NEW.is_budgeted AND (NEW.acquisition_value <> 0 OR NEW.acquired_on IS NOT NULL OR NEW.placed_in_service_on IS NOT NULL) THEN
        RAISE EXCEPTION 'Aset rencana tidak membawa perolehan atau pemakaian aktual.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aset_budgeted_identity BEFORE INSERT OR UPDATE ON aset_tr_aset
FOR EACH ROW EXECUTE FUNCTION aset_guard_budgeted_identity();
CREATE OR REPLACE FUNCTION aset_guard_budgeted_actual_entry() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM aset_tr_aset WHERE tenant_id = NEW.tenant_id AND id = NEW.aset_id AND is_budgeted) THEN
        RAISE EXCEPTION 'Aset sebagai perencanaan tidak dapat disusutkan atau diposting sebagai transaksi aktual.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aset_budgeted_book BEFORE INSERT OR UPDATE ON aset_tr_buku_aset
FOR EACH ROW EXECUTE FUNCTION aset_guard_budgeted_actual_entry();
CREATE TRIGGER aset_budgeted_ledger BEFORE INSERT ON aset_tr_ledger_aset
FOR EACH ROW EXECUTE FUNCTION aset_guard_budgeted_actual_entry();
SQL);
    }

    public function down(): void
    {
        // Identitas aset rencana dan rincian dokumen tetap dipertahankan.
    }
};
