<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghapus persyaratan skill dan sertifikat milik jenis pekerjaan maintenance.
     *
     * Tabel ini menyimpan skill dan sertifikat sebagai teks bebas di dalam database aset.
     * Di Dynamics 365, keduanya adalah kompetensi milik Human Resources yang dipasang pada
     * pekerja; job type dan trade hanya menyimpan persyaratan yang MERUJUK kompetensi itu,
     * dan gunanya satu: menjadwalkan hanya pekerja yang skill serta sertifikatnya cocok.
     *
     * Menyimpannya sebagai teks di sini melanggar batas modul — fakta workforce dimiliki
     * Core, bukan aplikasi ini — dan tidak akan pernah cocok dengan kompetensi pekerja
     * karena keduanya string yang tidak berhubungan. Penjadwalan berbasis kompetensi juga
     * belum ada, sehingga tidak ada satu pun pembaca datanya.
     *
     * Dihapus selagi masih kosong. Ketika kontrak Workforce Core tersedia, persyaratan
     * dibangun ulang sebagai referensi opaque ke kompetensi Core, bukan sebagai teks.
     *
     * Migration pembuatnya sengaja tidak disunting: ia sudah dijalankan di lingkungan dev,
     * sehingga menyuntingnya hanya akan membuat database lama tetap memiliki tabel ini
     * sementara database baru tidak.
     */
    public function up(): void
    {
        Schema::dropIfExists('m_maintenance_job_type_requirement');
    }

    public function down(): void
    {
        Schema::create('m_maintenance_job_type_requirement', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('maintenance_job_type_id');
            $table->string('requirement_type', 20);
            $table->string('nama', 150);
            $table->string('level', 50)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'maintenance_job_type_id', 'requirement_type', 'nama'], 'm_mnt_job_req_identity_uq');
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'm_mnt_job_req_job_type_fk')
                ->references(['tenant_id', 'id'])->on('m_maintenance_job_type')->cascadeOnDelete();
        });
    }
};
