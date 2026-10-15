<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->ulid('owner_party_id')->nullable();
            $table->foreign(['tenant_id', 'owner_party_id'])
                ->references(['tenant_id', 'id'])->on('parties')->restrictOnDelete();
        });

        // Data lama belum mencatat pemilik: pilih tautan aktif tertua secara deterministik.
        DB::statement('UPDATE locations l SET owner_party_id = (
            SELECT pl.party_id FROM party_locations pl
            JOIN parties p ON p.id = pl.party_id AND p.tenant_id = pl.tenant_id
            WHERE pl.location_id = l.id AND pl.tenant_id = l.tenant_id AND p.deleted_at IS NULL
            ORDER BY (pl.deleted_at IS NOT NULL), pl.created_at, pl.id LIMIT 1
        )');
    }

    public function down(): void
    {
        // Mundur image mempertahankan kepemilikan yang sudah ditetapkan.
    }
};
