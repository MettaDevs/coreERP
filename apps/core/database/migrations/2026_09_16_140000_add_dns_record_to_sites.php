<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record DNS yang dibuat admin.erp untuk alamat aplikasi server klien.
 *
 * Produksi di server klien kini mendapat alamat otomatis dengan bentuk yang sama dengan produksi di server
 * kita, `<tenant>.<domain dasar>`. Wildcard domain dasar menunjuk server kita, jadi supaya alamat itu sampai
 * ke mesin klien admin.erp membuat record khusus untuk nama tersebut di Cloudflare — record dengan nama
 * persis mengalahkan wildcard.
 *
 * Yang dicatat di sini adalah record yang **dibuat admin.erp sendiri**:
 *
 * - `dns_record_id` — id record di Cloudflare. Pencabutan menghapus record lewat id ini, bukan lewat nama:
 *   menghapus lewat nama berarti menghapus apa pun yang kebetulan bernama sama, termasuk record yang dibuat
 *   orang dengan tangan.
 * - `dns_name` dan `dns_target` — nama dan isi record saat terakhir ditulis. Layar membandingkan `dns_target`
 *   dengan alamat server yang tercatat sekarang untuk menyebut record yang belum mengikuti perubahan.
 * - `dns_synced_at` — kapan record itu terakhir ditulis.
 *
 * Keempatnya lahir dan hilang bersama, ditegakkan CHECK: record yang id-nya tercatat tanpa namanya tidak
 * dapat ditampilkan, dan nama tanpa id tidak dapat dihapus dengan aman.
 *
 * Nullable dan tanpa nilai bawaan, jadi rilis sebelumnya tetap menulis tabel yang sama tanpa ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('dns_record_id', 64)->nullable();
            $table->string('dns_name', 255)->nullable();
            $table->string('dns_target', 255)->nullable();
            $table->timestamp('dns_synced_at')->nullable();
        });

        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_dns_berpasangan CHECK ((dns_record_id IS NULL) = (dns_name IS NULL) AND (dns_name IS NULL) = (dns_target IS NULL) AND (dns_target IS NULL) = (dns_synced_at IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_dns_berpasangan');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['dns_record_id', 'dns_name', 'dns_target', 'dns_synced_at']);
        });
    }
};
