<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Klasifikasi Party standar platform. Bukan master yang dikelola tenant.
        Schema::create('party_types', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('name', 100);
            $table->timestamps();
        });

        $now = now();
        DB::table('party_types')->insert([
            ['code' => 'person', 'name' => 'Person', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'organization', 'name' => 'Organization', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'legal_entity', 'name' => 'Legal entity', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'team', 'name' => 'Team', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'operating_unit', 'name' => 'Operating unit', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('party_types');
    }
};
