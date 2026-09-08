<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_perencanaan_aset_details', function (Blueprint $table): void {
            $table->ulid('satuan_id')->nullable()->after('jenis_aset_id');
            $table->index(['tenant_id', 'satuan_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tr_perencanaan_aset_details', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'satuan_id']);
            $table->dropColumn('satuan_id');
        });
    }
};
