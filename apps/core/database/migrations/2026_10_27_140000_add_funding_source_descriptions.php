<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @kompatibel-mundur Tabel funding_sources baru diperkenalkan bersama master ini; image N-1 tidak membacanya.
 * Kode lama dan seluruh referensi ID dipertahankan. Kode hanya dilonggarkan menjadi opsional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_sources', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('code', 50)->nullable()->change();
        });
    }

    public function down(): void {}
};
