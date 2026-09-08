<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work order pemeliharaan aset. Header memikul identitas dokumen, penjadwalan, dan
        // status; aset justru tidak ada di sini melainkan di baris pekerjaan, mengikuti
        // Dynamics 365 F&O, supaya satu perintah kerja dapat mencakup beberapa aset
        // sekaligus tanpa memecah dokumen.
        Schema::create('tr_pemeliharaan_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('responsible_org_unit_id')->index();
            $table->ulid('tipe_work_order_id');
            $table->ulid('tingkat_layanan_id')->nullable();
            $table->text('keterangan')->nullable();
            // ID Core bersifat opaque dan tidak pernah menjadi foreign key lintas modul.
            $table->string('penanggung_jawab_user_id', 64)->nullable();
            // Tiga pasang waktu yang berbeda arti dan tidak boleh saling menggantikan:
            // yang diminta, yang dijanjikan, dan yang benar-benar terjadi.
            $table->dateTime('diharapkan_mulai')->nullable();
            $table->dateTime('diharapkan_selesai')->nullable();
            $table->dateTime('dijadwalkan_mulai')->nullable();
            $table->dateTime('dijadwalkan_selesai')->nullable();
            $table->dateTime('aktual_mulai')->nullable();
            $table->dateTime('aktual_selesai')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'tr_wo_scope_status_ix');
            $table->foreign(['tenant_id', 'tipe_work_order_id'], 'tr_wo_tipe_fk')
                ->references(['tenant_id', 'id'])->on('m_tipe_work_order')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tingkat_layanan_id'], 'tr_wo_layanan_fk')
                ->references(['tenant_id', 'id'])->on('m_tingkat_layanan')->restrictOnDelete();
        });

        // Baris pekerjaan: satu aset, satu jenis pekerjaan, satu pelaksana.
        //
        // Berbeda dari detail transaksi lain di modul ini, baris pekerjaan TIDAK boleh
        // diganti dengan pola hapus-lalu-sisip saat dokumen disunting. Ia memikul hasil
        // checklist, jam aktual, penugasan, sebab, dan tindakan; menghapusnya berarti
        // membuang pekerjaan yang sudah dilakukan orang. Penggantian massal hanya sah
        // selama status masih `draft`; sesudah itu penambahan dan penghapusan baris
        // adalah tindakan eksplisit yang ditegakkan controller.
        Schema::create('tr_pemeliharaan_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('pemeliharaan_aset_id');
            $table->unsignedInteger('line_number');
            $table->ulid('asset_id');
            // Lokasi disalin saat baris dibuat. Aset dapat dipindahkan setelah pekerjaan
            // selesai, dan riwayat harus tetap menunjukkan di mana pekerjaan dikerjakan.
            $table->ulid('asset_location_id')->nullable();
            $table->ulid('maintenance_job_type_id');
            $table->ulid('variant_id')->nullable();
            $table->ulid('trade_id')->nullable();
            $table->string('ditugaskan_ke_user_id', 64)->nullable();
            $table->dateTime('dijadwalkan_mulai')->nullable();
            $table->dateTime('dijadwalkan_selesai')->nullable();
            $table->decimal('estimasi_jam', 8, 2)->nullable();
            $table->decimal('aktual_jam', 8, 2)->nullable();
            $table->string('hasil', 30)->nullable();
            $table->ulid('sebab_kerusakan_id')->nullable();
            $table->ulid('tindakan_perbaikan_id')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'pemeliharaan_aset_id', 'line_number'], 'tr_wo_job_line_uq');
            // Daftar "pekerjaan saya" menyaring tepat pada pasangan ini.
            $table->index(['tenant_id', 'ditugaskan_ke_user_id'], 'tr_wo_job_assignee_ix');
            $table->index(['tenant_id', 'asset_id'], 'tr_wo_job_asset_ix');
            $table->foreign(['tenant_id', 'pemeliharaan_aset_id'], 'tr_wo_job_header_fk')
                ->references(['tenant_id', 'id'])->on('tr_pemeliharaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_id'], 'tr_wo_job_asset_fk')
                ->references(['tenant_id', 'id'])->on('tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_location_id'], 'tr_wo_job_location_fk')
                ->references(['tenant_id', 'id'])->on('m_lokasi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'tr_wo_job_type_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->restrictOnDelete();
            $table->foreign(['tenant_id', 'variant_id'], 'tr_wo_job_variant_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type_variant')->restrictOnDelete();
            $table->foreign(['tenant_id', 'trade_id'], 'tr_wo_job_trade_fk')
                ->references(['tenant_id', 'id'])->on('m_trade')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sebab_kerusakan_id'], 'tr_wo_job_sebab_fk')
                ->references(['tenant_id', 'id'])->on('m_sebab_kerusakan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tindakan_perbaikan_id'], 'tr_wo_job_tindakan_fk')
                ->references(['tenant_id', 'id'])->on('m_tindakan_perbaikan')->restrictOnDelete();
        });

        // Hasil pemeriksaan, disalin dari template checklist saat baris pekerjaan dibuat.
        //
        // Disalin, bukan dirujuk. Template boleh berubah bulan depan tanpa mengubah arti
        // pemeriksaan yang sudah dikerjakan. Ini pola snapshot yang sama dengan aturan
        // profil penyusutan yang disalin ke `tr_buku_aset`.
        Schema::create('tr_pemeliharaan_aset_checklist', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('pemeliharaan_aset_detail_id');
            // Desimal supaya langkah 1.5 dapat disisipkan di antara 1 dan 2 tanpa
            // menomori ulang seluruh prosedur yang sudah dicetak dan dihafal teknisi.
            $table->decimal('line_number', 8, 1);
            $table->string('nama', 255);
            // Kosakata yang sama dengan baris template: `header` (judul, tidak diisi),
            // `text`, `measurement` (angka bersatuan), dan `variable` (pilihan bernilai
            // tetap). `template` tidak pernah tersimpan di sini karena sudah dimekarkan
            // menjadi baris-barisnya saat disalin.
            $table->string('tipe', 20);
            $table->string('satuan', 40)->nullable();
            $table->boolean('wajib')->default(false);
            $table->text('instruksi')->nullable();
            // Jejak asal baris setelah disalin, supaya hasil pemeriksaan tetap dapat
            // ditelusuri ke template atau jenis pekerjaan yang melahirkannya.
            $table->string('sumber', 40)->nullable();
            $table->ulid('sumber_id')->nullable();
            $table->string('nilai', 255)->nullable();
            $table->string('result_code', 20)->nullable();
            // Tanpa penanda ini, gate "baris wajib harus terisi" akan macet di lapangan
            // pada pemeriksaan yang memang tidak berlaku untuk aset bersangkutan.
            $table->boolean('tidak_berlaku')->default(false);
            $table->boolean('diperiksa')->default(false);
            $table->string('diperiksa_oleh_user_id', 64)->nullable();
            $table->dateTime('diperiksa_pada')->nullable();
            $table->text('catatan_teknisi')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'pemeliharaan_aset_detail_id', 'line_number'], 'tr_wo_check_line_uq');
            // Checklist tidak berdiri sendiri: ia hanya bermakna sebagai bagian dari satu
            // baris pekerjaan, jadi ia ikut terhapus bersama induknya.
            $table->foreign(['tenant_id', 'pemeliharaan_aset_detail_id'], 'tr_wo_check_job_fk')
                ->references(['tenant_id', 'id'])->on('tr_pemeliharaan_aset_details')->cascadeOnDelete();
        });

        // Jejak perpindahan status. Modul ini tidak punya tabel audit generik, dan status
        // saja tidak dapat menjawab kapan pekerjaan mulai dikerjakan, siapa yang menutup,
        // atau kenapa dibatalkan.
        Schema::create('tr_pemeliharaan_aset_status_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('pemeliharaan_aset_id');
            $table->string('dari_status', 30);
            $table->string('ke_status', 30);
            $table->string('oleh_user_id', 64)->nullable();
            $table->text('alasan')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'pemeliharaan_aset_id'], 'tr_wo_log_header_ix');
            $table->foreign(['tenant_id', 'pemeliharaan_aset_id'], 'tr_wo_log_header_fk')
                ->references(['tenant_id', 'id'])->on('tr_pemeliharaan_aset')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_pemeliharaan_aset_status_log');
        Schema::dropIfExists('tr_pemeliharaan_aset_checklist');
        Schema::dropIfExists('tr_pemeliharaan_aset_details');
        Schema::dropIfExists('tr_pemeliharaan_aset');
    }
};
