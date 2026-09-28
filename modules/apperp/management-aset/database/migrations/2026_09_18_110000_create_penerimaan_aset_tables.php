<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen penerimaan aset: satu lembar, banyak aset.
 *
 * **Kenapa dokumennya perlu ada padahal register sudah bisa menerima aset.** Register
 * menerima satu aset per permintaan. Dua puluh kursi yang datang dengan satu surat jalan
 * karena itu harus diketik dua puluh kali, dan dua puluh kali itu tidak ada satu pun
 * berkas yang mengikatnya menjadi satu penerimaan — padahal yang diperiksa pemeriksa
 * adalah surat jalannya, bukan barisnya satu per satu.
 *
 * **Kenapa jumlah ada di baris, bukan di aset.** Setiap aset tetap satu baris di
 * register dengan kodenya sendiri, karena kode aset adalah yang tertempel di barangnya
 * dan yang disebut saat aset dimutasi, dipelihara, atau dilepas. Jadi `jumlah` di sini
 * bukan kolom kuantitas yang ikut hidup bersama aset; ia hanya menyatakan berapa aset
 * yang dilahirkan baris ini pada saat dokumen diselesaikan.
 *
 * **Kenapa kode aset tidak diturunkan dari nomor dokumen.** Kode aset adalah kunci alami
 * yang dipakai seumur hidup aset; ia tidak boleh bergantung pada dokumen yang masih bisa
 * dikoreksi atau dibatalkan. Kodenya tetap diambil berurutan dari number sequence Core
 * `management-aset.aset`, sama seperti aset yang diterima satuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Baris permintaan pembelian belum pernah menjadi tujuan foreign key, jadi ia
        // hanya punya primary key pada `id`. PostgreSQL menuntut kendala unik yang persis
        // menutupi kolom yang dirujuk, sehingga pasangan `(tenant_id, id)` harus ada lebih
        // dulu — pola yang sama yang dipakai setiap tabel lain di modul ini.
        Schema::table('aset_tr_permintaan_pengadaan_aset_details', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'aset_tr_pp_details_tenant_id_uq');
        });

        Schema::create('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            // Unit pengguna: yang memakai asetnya, dan yang menjadi
            // `responsible_org_unit_id` pada setiap aset yang lahir dari dokumen ini.
            // Dokumen dan aset karena itu selalu berada pada lingkup yang sama — tidak ada
            // cara menyusun penerimaan yang melahirkan aset di luar jangkauan penyusunnya.
            $table->ulid('responsible_org_unit_id')->index();
            // Unit penerima: loket atau gudang yang menerima fisiknya. Sering berbeda dari
            // unit pengguna, dan tidak menentukan lingkup apa pun — ia hanya dicatat pada
            // penempatan pertama sebagai keterangan siapa yang membubuhkan tanda tangan.
            $table->ulid('receiving_org_unit_id')->nullable();
            $table->date('tanggal');
            // PSAK 16 par. 55: penyusutan dimulai saat aset siap digunakan, bukan saat ia
            // tiba. Keduanya sering sama hari, tetapi tidak selalu — barang yang menunggu
            // pemasangan bisa berbulan-bulan. Karena satu dokumen adalah satu kedatangan,
            // tanggalnya ada di kepala, bukan di baris.
            $table->date('tanggal_siap_pakai')->nullable();
            // ID pengguna Core bersifat opaque dan tidak pernah menjadi foreign key
            // lintas modul.
            $table->string('diterima_oleh_user_id', 64)->nullable();
            $table->string('penanggung_jawab_user_id', 64)->nullable();
            $table->ulid('lokasi_aset_id')->nullable();
            $table->string('currency_code', 3);
            $table->text('keterangan')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_penerimaan_scope_ix');
            $table->foreign(['tenant_id', 'lokasi_aset_id'], 'aset_tr_penerimaan_lokasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
        });

        // Baris penerimaan: satu jenis barang, sebanyak yang datang.
        //
        // Nilainya per unit, bukan total, karena itulah yang tertulis di faktur dan itu
        // pula yang menentukan apakah barangnya melewati ambang kapitalisasi. Dua puluh
        // kursi lima ratus ribu tetap dua puluh aset lima ratus ribu; ia tidak menjadi
        // satu aset sepuluh juta hanya karena dibeli bersamaan.
        Schema::create('aset_tr_penerimaan_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('penerimaan_aset_id');
            $table->unsignedInteger('line_number');
            $table->string('nama', 150);
            $table->ulid('group_aset_id');
            $table->ulid('jenis_aset_id');
            $table->ulid('kondisi_aset_id')->nullable();
            $table->ulid('pabrikan_aset_id')->nullable();
            $table->ulid('model_aset_id')->nullable();
            $table->string('model_number', 150)->nullable();
            $table->unsignedInteger('jumlah');
            $table->decimal('nilai_per_unit', 18, 2);
            $table->decimal('residu_per_unit', 18, 2)->default(0);
            // Baris permintaan pembelian yang dipenuhi baris ini, bila penerimaannya
            // memang berasal dari permintaan. Sisa yang belum diterima dihitung dari
            // jumlah aset yang sudah lahir untuk baris permintaan itu, bukan dari kolom
            // sisa yang harus dijaga tetap benar.
            $table->ulid('permintaan_pembelian_detail_id')->nullable();
            // Nilai atribut berlaku untuk seluruh aset yang lahir dari baris ini: dua
            // puluh kursi dari satu kiriman memang berwarna sama. Yang membedakan antar
            // unit — nomor seri — sengaja tidak di sini; ia diisi setelah barangnya
            // dibuka, lewat layar yang mendaftar aset hasil dokumen ini.
            $table->json('atribut')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'penerimaan_aset_id', 'line_number'], 'aset_tr_penerimaan_line_uq');
            $table->index(['tenant_id', 'permintaan_pembelian_detail_id'], 'aset_tr_penerimaan_line_pp_ix');
            $table->foreign(['tenant_id', 'penerimaan_aset_id'], 'aset_tr_penerimaan_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'group_aset_id'], 'aset_tr_penerimaan_line_group_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_group_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'aset_tr_penerimaan_line_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'kondisi_aset_id'], 'aset_tr_penerimaan_line_kondisi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_kondisi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pabrikan_aset_id'], 'aset_tr_penerimaan_line_pabrikan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_pabrikan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'model_aset_id'], 'aset_tr_penerimaan_line_model_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_model_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'permintaan_pembelian_detail_id'], 'aset_tr_penerimaan_line_pp_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_permintaan_pengadaan_aset_details')->restrictOnDelete();
        });

        // Aset menyebut dokumen yang melahirkannya. Dua kolom, bukan satu: barisnya yang
        // menyimpan nilai per unit, atribut, dan rujukan permintaan pembelian, sehingga
        // sisa permintaan dapat dihitung tanpa membaca ulang seluruh dokumen.
        Schema::table('aset_tr_aset', function (Blueprint $table): void {
            $table->ulid('penerimaan_aset_id')->nullable();
            $table->ulid('penerimaan_aset_detail_id')->nullable();
            $table->index(['tenant_id', 'penerimaan_aset_id'], 'aset_tr_aset_penerimaan_ix');
            $table->index(['tenant_id', 'penerimaan_aset_detail_id'], 'aset_tr_aset_penerimaan_line_ix');
            $table->foreign(['tenant_id', 'penerimaan_aset_id'], 'aset_tr_aset_penerimaan_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'penerimaan_aset_detail_id'], 'aset_tr_aset_penerimaan_line_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_penerimaan_aset_details')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_aset', function (Blueprint $table): void {
            $table->dropForeign('aset_tr_aset_penerimaan_line_fk');
            $table->dropForeign('aset_tr_aset_penerimaan_fk');
            $table->dropIndex('aset_tr_aset_penerimaan_line_ix');
            $table->dropIndex('aset_tr_aset_penerimaan_ix');
            $table->dropColumn(['penerimaan_aset_detail_id', 'penerimaan_aset_id']);
        });
        Schema::dropIfExists('aset_tr_penerimaan_aset_details');
        Schema::dropIfExists('aset_tr_penerimaan_aset');
        Schema::table('aset_tr_permintaan_pengadaan_aset_details', function (Blueprint $table): void {
            $table->dropUnique('aset_tr_pp_details_tenant_id_uq');
        });
    }
};
