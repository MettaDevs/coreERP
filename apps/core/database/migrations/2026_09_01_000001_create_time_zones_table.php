<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('time_zones')) {
            Schema::create('time_zones', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('iana_name', 100)->index();
                $table->string('display_name', 150);
                $table->string('utc_offset', 10);
                $table->string('country_code', 2)->index();
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->foreign('country_code')
                    ->references('code')
                    ->on('ref_countries')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_zones');
    }
};
