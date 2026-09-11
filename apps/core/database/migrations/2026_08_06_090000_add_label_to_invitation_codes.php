<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode undangan berupa deretan huruf acak, jadi tanpa keterangan admin tidak
 * dapat mengingat sebuah kode dibuat untuk siapa atau untuk keperluan apa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table): void {
            $table->string('label', 120)->nullable()->after('system_role');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};
