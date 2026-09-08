<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->ulid('default_depreciation_profile_id')->nullable()->index();
            $table->string('default_book_code', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->dropColumn(['default_depreciation_profile_id', 'default_book_code']);
        });
    }
};
