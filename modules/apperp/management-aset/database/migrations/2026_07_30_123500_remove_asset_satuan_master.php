<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility for development databases that ran the original local master before it moved to Core.
        if (Schema::hasTable('m_satuan')) {
            Schema::table('tr_perencanaan_aset_details', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'satuan_id']);
            });
            Schema::dropIfExists('m_satuan');
        }
    }

    public function down(): void {}
};
