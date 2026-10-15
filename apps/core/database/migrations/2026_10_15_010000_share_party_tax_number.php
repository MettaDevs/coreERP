<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jangan memilih salah satu NPWP secara diam-diam bila akun satu pihak berbeda.
        $conflicts = DB::selectOne("SELECT count(*) AS total FROM (SELECT tenant_id, party_id FROM vendors WHERE nullif(trim(tax_number), '') IS NOT NULL GROUP BY tenant_id, party_id HAVING count(DISTINCT regexp_replace(tax_number, '[^0-9]', '', 'g')) > 1) conflicts");
        if ((int) $conflicts->total > 0) {
            throw new RuntimeException('Ada akun vendor dari pihak yang sama dengan NPWP berbeda. Samakan NPWP pihak tersebut sebelum menjalankan migration.');
        }

        Schema::table('parties', function (Blueprint $table): void {
            $table->string('tax_number', 32)->nullable();
        });
        DB::statement("UPDATE parties p SET tax_number = source.tax_number FROM (SELECT tenant_id, party_id, min(trim(tax_number)) AS tax_number FROM vendors WHERE nullif(trim(tax_number), '') IS NOT NULL GROUP BY tenant_id, party_id) source WHERE p.tenant_id = source.tenant_id AND p.id = source.party_id");
        // Kolom vendor dipertahankan untuk kontrak pembaca dan image N-1.
        DB::statement('UPDATE vendors v SET tax_number = p.tax_number FROM parties p WHERE v.tenant_id = p.tenant_id AND v.party_id = p.id AND v.tax_number IS DISTINCT FROM p.tax_number');
    }

    public function down(): void
    {
        // Mundur image tidak menghapus identitas pajak.
    }
};
