<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_kelompok_harta_fiskal', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('template_key', 160);
            $table->string('jurisdiction', 32);
            $table->string('label', 150);
            $table->string('regulation_reference', 250)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('useful_life_years')->nullable();
            $table->decimal('straight_line_rate_percent', 9, 4)->nullable();
            $table->decimal('reducing_balance_rate_percent', 9, 4)->nullable();
            $table->boolean('allow_reducing_balance')->default(false);
            $table->boolean('depreciable')->default(true);
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'template_key']);
            $table->index(['tenant_id', 'aktif', 'effective_from']);
        });

        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->ulid('kelompok_harta_fiskal_id')->nullable()->index();
            $table->foreign(['tenant_id', 'kelompok_harta_fiskal_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_kelompok_harta_fiskal')
                ->restrictOnDelete();
        });

        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            // Snapshot ini menjaga jejak versi aturan yang dipakai ketika aset diterima.
            // Nilai null pada data lama berarti referensi fiskal belum dipetakan.
            $table->ulid('kelompok_harta_fiskal_id')->nullable()->index();
            $table->foreign(['tenant_id', 'kelompok_harta_fiskal_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_kelompok_harta_fiskal')
                ->restrictOnDelete();
        });

        // Backfill hanya untuk group yang sudah memiliki referensi baru. Kolom legacy
        // `tipe_harta` sengaja tidak ditebak menjadi aturan baru; data lama yang belum
        // dipetakan akan tetap terlihat sebagai null sampai seed/migrasi resmi memilih
        // versi referensinya.
        DB::table('tr_penerimaan_aset as asset')
            ->join('m_group_aset as asset_group', function ($join): void {
                $join->on('asset_group.id', '=', 'asset.group_aset_id')
                    ->on('asset_group.tenant_id', '=', 'asset.tenant_id');
            })
            ->whereNull('asset.kelompok_harta_fiskal_id')
            ->whereNotNull('asset_group.kelompok_harta_fiskal_id')
            ->select('asset.id', 'asset_group.kelompok_harta_fiskal_id')
            ->get()
            ->each(function (object $row): void {
                DB::table('tr_penerimaan_aset')
                    ->where('id', $row->id)
                    ->update(['kelompok_harta_fiskal_id' => $row->kelompok_harta_fiskal_id]);
            });
    }

    public function down(): void
    {
        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'kelompok_harta_fiskal_id']);
            $table->dropIndex(['tenant_id', 'kelompok_harta_fiskal_id']);
            $table->dropColumn('kelompok_harta_fiskal_id');
        });

        Schema::table('m_group_aset', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'kelompok_harta_fiskal_id']);
            $table->dropIndex(['tenant_id', 'kelompok_harta_fiskal_id']);
            $table->dropColumn('kelompok_harta_fiskal_id');
        });

        Schema::dropIfExists('m_kelompok_harta_fiskal');
    }
};
