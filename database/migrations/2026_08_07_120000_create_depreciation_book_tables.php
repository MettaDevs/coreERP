<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buku penyusutan; padanan "Book" di Dynamics 365 F&O. Satu aset dapat memiliki
        // beberapa buku sekaligus: satu komersial untuk pembukuan dan satu fiskal untuk
        // pajak. Keduanya berdiri sendiri dan diisi terpisah, bukan turunan otomatis.
        Schema::create('m_buku_penyusutan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->string('posting_layer', 20)->default('current');
            // App ini tidak menjurnal; ia mengekspor ke backoffice. Buku fiskal biasanya
            // tidak ikut diekspor agar backoffice tidak menjurnal dua kali untuk aset
            // yang sama. Padanan toggle "Post to general ledger" di F&O.
            $table->boolean('export_to_backoffice')->default(true);
            $table->ulid('depreciation_profile_id')->nullable();
            // Profil pengganti saat saldo menurun sudah lebih kecil daripada garis lurus
            // sisa umur; pola "RB switch to SLLR" pada Books di F&O.
            $table->ulid('alternative_profile_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'depreciation_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'alternative_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
        });

        // Matriks group x buku; padanan "Fixed asset group/book" di F&O. Di sinilah
        // ditentukan aset dari group tertentu mendapat buku apa saja dan dengan aturan apa.
        // Bukan master penuh: tanpa kode dan tanpa nomor.
        Schema::create('m_group_buku_penyusutan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('group_aset_id');
            $table->ulid('buku_id');
            $table->ulid('depreciation_profile_id')->nullable();
            $table->ulid('alternative_profile_id')->nullable();
            $table->unsignedInteger('useful_life_periods')->nullable();
            // Menentukan perlakuan bulan/tahun pertama; delapan pilihan seperti F&O.
            $table->string('convention', 30)->nullable();
            $table->boolean('depreciate')->default(true);
            // Wajib ada: guard arsip pada MasterDataController menyaring anak dengan
            // `whereNull('deleted_at')` tanpa syarat, jadi tabel tanpa kolom ini membuat
            // pengarsipan group gagal sebagai 500 di PostgreSQL.
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'group_aset_id', 'buku_id']);
            $table->foreign(['tenant_id', 'group_aset_id'])->references(['tenant_id', 'id'])->on('m_group_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'buku_id'])->references(['tenant_id', 'id'])->on('m_buku_penyusutan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'depreciation_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'alternative_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
        });

        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->ulid('buku_id')->nullable();
            // Disalin dari matriks saat aset diterima. Nilai ini snapshot aturan pada saat
            // itu; mengubah matriks kelak tidak menulis ulang buku yang sudah berjalan.
            $table->unsignedInteger('useful_life_periods')->nullable();
            $table->string('convention', 30)->nullable();
            $table->ulid('alternative_profile_id')->nullable();
            // Tanggal aset benar-benar mulai disusutkan, hasil penerapan convention atas
            // `placed_in_service_on`. F&O menghitung dari placed in service, bukan dari
            // tanggal perolehan.
            $table->date('depreciation_start_on')->nullable();
            // Padanan "Calculate depreciation" pada asset book di F&O. Aset di bawah
            // ambang kapitalisasi group tetap tercatat, tetapi bukunya tidak menyusut.
            $table->boolean('depreciate')->default(true);
            $table->index(['tenant_id', 'buku_id']);
            $table->foreign(['tenant_id', 'buku_id'])->references(['tenant_id', 'id'])->on('m_buku_penyusutan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'alternative_profile_id'])->references(['tenant_id', 'id'])->on('m_profil_penyusutan')->restrictOnDelete();
        });

        // Satu aset kini punya beberapa buku sekaligus, jadi keunikannya berpindah dari
        // kode buku ke identitas bukunya. `book_code` tetap ada sebagai label yang
        // disalin dari master buku, bukan lagi sebagai penentu keunikan.
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            // Nama indeks masih berprefiks `t_`: tabel di-rename dari `t_buku_aset` ke
            // `tr_buku_aset`, tetapi nama indeks dan foreign key tidak ikut berubah.
            $table->dropUnique('t_buku_aset_tenant_id_asset_id_book_code_unique');
            $table->unique(['tenant_id', 'asset_id', 'buku_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'asset_id', 'buku_id']);
            $table->unique(['tenant_id', 'asset_id', 'book_code'], 't_buku_aset_tenant_id_asset_id_book_code_unique');
            $table->dropForeign(['tenant_id', 'buku_id']);
            $table->dropForeign(['tenant_id', 'alternative_profile_id']);
            $table->dropIndex(['tenant_id', 'buku_id']);
            $table->dropColumn(['buku_id', 'useful_life_periods', 'convention', 'alternative_profile_id', 'depreciation_start_on', 'depreciate']);
        });

        Schema::dropIfExists('m_group_buku_penyusutan');
        Schema::dropIfExists('m_buku_penyusutan');
    }
};
