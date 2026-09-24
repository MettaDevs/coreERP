<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "sudah di-post" pada periode penyusutan (feed posting finance, TODO 11.1; K-14).
 *
 * `posted_posting_id` menyebut posting finance yang membawa periode itu: `asset.depreciation` untuk
 * periode asli yang ikut proses "Post penyusutan", `asset.depreciation_reversal` untuk baris pembalik
 * yang periode aslinya sudah di-post. Periode final yang kolom ini kosong belum di-post. Kolom itu
 * sekaligus satu-satunya jalan periode yang sama ikut dua posting: proses post hanya mengambil yang
 * masih kosong, di bawah kunci baris.
 *
 * Indeksnya mengikuti bentuk pertanyaan proses post: periode final satu entitas legal pada satu
 * tanggal akhir, yang belum di-post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_penyusutan_aset', function (Blueprint $table): void {
            $table->string('posted_posting_id', 120)->nullable();
            $table->index(['tenant_id', 'legal_entity_id', 'period_ends_on'], 'aset_tr_penyusutan_aset_posting_index');
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_penyusutan_aset', function (Blueprint $table): void {
            $table->dropIndex('aset_tr_penyusutan_aset_posting_index');
            $table->dropColumn('posted_posting_id');
        });
    }
};
