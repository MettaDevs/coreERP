<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postal_addresses', function (Blueprint $table): void {
            foreach (['province' => 'ref_provinces', 'regency' => 'ref_regencies', 'district' => 'ref_districts', 'village' => 'ref_villages', 'postal_code' => 'ref_postal_codes'] as $field => $reference) {
                $table->foreignUlid($field.'_id')->nullable()->constrained($reference)->restrictOnDelete();
            }
        });
        // Alamat lama tetap memakai teksnya; jangan menebak ID dari nama yang bisa sama.
    }

    public function down(): void
    {
        // Mundur image mempertahankan relasi wilayah dan teks alamat lama.
    }
};
