<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX aset_tr_work_order_scroll_ix ON aset_tr_pemeliharaan_aset (tenant_id, legal_entity_id, (CASE WHEN created_at IS NULL THEN 1 ELSE 0 END), created_at DESC, id DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS aset_tr_work_order_scroll_ix');
    }
};
