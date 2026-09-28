<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dokumen mutasi aset: perpindahan lokasi, unit pengguna, dan penanggung jawab.
        //
        // Ini padanan "Install asset at location" pada Asset Management Dynamics 365 F&O —
        // sumbu fisik, bukan sumbu finansial. Padanan sumbu finansial di F&O adalah
        // "Transfer fixed assets", yang memindahkan dimensi keuangan **per buku** dan
        // menerbitkan jurnal. Modul ini tidak menjurnal, sehingga dimensi per buku tidak
        // punya arti apa pun di sini dan sengaja tidak disimpan; lihat catatan pada
        // controller untuk di mana ia akan menempel bila Finance kelak menjurnal.
        //
        // Berbeda dari F&O yang memasang satu aset (beserta anaknya) per aksi, dokumen ini
        // memuat beberapa aset sekaligus karena berita acara serah terima Indonesia memang
        // satu lembar untuk semua barang yang berpindah tangan pada saat yang sama.
        Schema::create('aset_tr_mutasi_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            // Unit kerja yang memiliki dokumennya. Ia tetap unit asal walau asetnya sudah
            // berpindah: yang membuat berita acara adalah pihak yang menyerahkan.
            $table->ulid('responsible_org_unit_id')->index();
            $table->date('tanggal');
            // Tujuan berlaku untuk seluruh baris. Satu berita acara adalah satu serah
            // terima antara dua pihak; aset yang pindah ke tempat berbeda adalah dokumen
            // berbeda, sama seperti F&O yang memasang aset pada satu functional location
            // per aksi.
            $table->ulid('tujuan_lokasi_id')->nullable();
            $table->ulid('tujuan_org_unit_id')->index();
            // ID pengguna Core bersifat opaque dan tidak pernah menjadi foreign key
            // lintas modul.
            $table->string('diserahkan_oleh_user_id', 64)->nullable();
            $table->string('diterima_oleh_user_id', 64)->nullable();
            $table->string('alasan', 250);
            $table->text('keterangan')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_mutasi_scope_ix');
            $table->foreign(['tenant_id', 'tujuan_lokasi_id'], 'aset_tr_mutasi_lokasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
        });

        // Baris mutasi: satu aset per baris, dengan kondisi yang disaksikan saat serah
        // terima dan snapshot keadaan asalnya.
        //
        // Kolom `asal_*` sengaja kosong selama dokumen masih draf. Keadaan asal yang benar
        // adalah keadaan pada saat serah terima benar-benar terjadi, bukan pada saat
        // dokumen diketik — aset masih boleh berpindah di antara keduanya. Selama draf,
        // layar membacanya langsung dari aset; saat diselesaikan, nilainya dibekukan di
        // sini supaya berita acara yang dicetak ulang tahun depan tetap berbunyi sama.
        Schema::create('aset_tr_mutasi_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('mutasi_aset_id');
            $table->unsignedInteger('line_number');
            $table->ulid('asset_id');
            $table->ulid('kondisi_aset_id')->nullable();
            $table->ulid('asal_lokasi_id')->nullable();
            $table->ulid('asal_org_unit_id')->nullable();
            $table->string('asal_custodian_user_id', 64)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'mutasi_aset_id', 'line_number'], 'aset_tr_mutasi_line_uq');
            // Satu aset tidak boleh muncul dua kali pada berita acara yang sama: dua baris
            // berarti dua penempatan bertanggal sama untuk aset yang sama, dan tidak ada
            // yang dapat menentukan mana yang berlaku.
            $table->unique(['tenant_id', 'mutasi_aset_id', 'asset_id'], 'aset_tr_mutasi_line_asset_uq');
            $table->index(['tenant_id', 'asset_id'], 'aset_tr_mutasi_line_asset_ix');
            $table->foreign(['tenant_id', 'mutasi_aset_id'], 'aset_tr_mutasi_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_mutasi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_id'], 'aset_tr_mutasi_line_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'kondisi_aset_id'], 'aset_tr_mutasi_line_kondisi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_kondisi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asal_lokasi_id'], 'aset_tr_mutasi_line_asal_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
        });

        // Penempatan yang lahir dari dokumen menyebut dokumennya. Tanpa ini, riwayat
        // penempatan dapat menunjukkan bahwa aset berpindah tetapi tidak dengan berita
        // acara mana — dan berita acara adalah satu-satunya bukti serah terimanya.
        Schema::table('aset_tr_penempatan_aset', function (Blueprint $table): void {
            $table->ulid('mutasi_aset_id')->nullable();
            $table->index(['tenant_id', 'mutasi_aset_id'], 'aset_tr_penempatan_mutasi_ix');
            $table->foreign(['tenant_id', 'mutasi_aset_id'], 'aset_tr_penempatan_mutasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_mutasi_aset')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_penempatan_aset', function (Blueprint $table): void {
            $table->dropForeign('aset_tr_penempatan_mutasi_fk');
            $table->dropIndex('aset_tr_penempatan_mutasi_ix');
            $table->dropColumn('mutasi_aset_id');
        });
        Schema::dropIfExists('aset_tr_mutasi_aset_details');
        Schema::dropIfExists('aset_tr_mutasi_aset');
    }
};
