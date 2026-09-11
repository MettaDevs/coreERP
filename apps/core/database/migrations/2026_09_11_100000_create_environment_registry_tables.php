<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Registry environment: satu pelanggan boleh punya lebih dari satu tempat kerja.
 *
 * Desainnya di `docs/todo/environment-dan-pusat-admin/README.md`. Irisan pertama sengaja tidak
 * membuat database kedua — ia hanya mendirikan registry-nya, sehingga setiap tenant yang sudah ada
 * memperoleh satu environment `production` yang menunjuk database yang sedang dipakai.
 *
 * `tenant_deployments` tidak dibongkar. Ia sudah tidak punya satu pun pembaca sejak jalur hosting
 * container dibuang pada 10 September 2026, jadi ia bergabung ke daftar tabel yatim yang memang
 * dibiarkan — membuang tabel berisi data pelanggan melanggar aturan penghapusan lunak repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->string('slug', 120);

            // Kosong berarti "ikut database koneksi bawaan". Itu keadaan pooled dan on-prem, dan ia
            // permanen: banyak tenant memang berbagi satu database di sana. Nama hanya terisi ketika
            // sebuah environment benar-benar memperoleh database sendiri — dan sejak saat itu unique
            // index di bawah yang menjaga dua environment tidak menunjuk database yang sama.
            // 63 adalah batas identifier PostgreSQL; jangan dilebarkan.
            $table->string('database_name', 63)->nullable()->unique();

            $table->string('status', 20);
            $table->ulid('source_environment_id')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('outbound_allowed');
            $table->timestamp('schema_migrated_at')->nullable();
            $table->string('schema_fingerprint', 255)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'kind']);
        });

        // Foreign key ke tabel ini sendiri dipasang terpisah, bukan di dalam `Schema::create`.
        // Laravel mengubah `->primary()` yang menempel pada kolom menjadi perintah tersendiri yang
        // dijalankan SESUDAH perintah foreign key, sehingga PostgreSQL menolak: pada saat foreign
        // key-nya dipasang, kunci yang dirujuknya memang belum ada.
        Schema::table('environments', function (Blueprint $table): void {
            $table->foreign('source_environment_id')->references('id')->on('environments')->restrictOnDelete();
        });

        // Keadaan yang tidak boleh dapat diwakili. Masing-masing menutup satu kesalahan yang
        // kalau hanya dijaga kode akan lolos pada jalur yang lupa dilewati.
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_kind_dikenal CHECK (kind IN ('production', 'sandbox', 'demo'))");
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_status_dikenal CHECK (status IN ('provisioning', 'copying', 'active', 'maintenance', 'degraded', 'suspended', 'soft_deleted'))");

        // Seluruh fitur ini ada supaya salinan produksi tidak dapat menghubungi pelanggan produksi.
        // Karena itu "beri sandbox ini email sehari saja" adalah perubahan skema dan sebuah
        // percakapan, bukan satu baris UPDATE yang tidak dilihat siapa pun.
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_keluar_ikut_jenis CHECK (outbound_allowed = (kind = 'production'))");

        // Produksi tidak pernah lahir dari salinan.
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_sumber_hanya_sandbox CHECK (source_environment_id IS NULL OR kind = 'sandbox')");

        // Demo tanpa tanggal berakhir adalah bug yang tidak disadari siapa pun sampai disknya penuh.
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_demo_berakhir CHECK (kind <> 'demo' OR expires_at IS NOT NULL)");

        // Menghapus tanpa jadwal berarti menyimpan selamanya tanpa ada yang memutuskannya.
        DB::statement('ALTER TABLE environments ADD CONSTRAINT environments_hapus_berpasangan CHECK ((deleted_at IS NULL) = (purge_after IS NULL))');
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_status_hapus_sejalan CHECK ((status = 'soft_deleted') = (deleted_at IS NOT NULL))");

        // "Dua produksi" bukan keadaan yang sistem ini kenal.
        DB::statement("CREATE UNIQUE INDEX environments_satu_produksi ON environments (tenant_id) WHERE kind = 'production' AND deleted_at IS NULL");
        DB::statement('CREATE UNIQUE INDEX environments_slug_per_tenant ON environments (tenant_id, slug) WHERE deleted_at IS NULL');

        /*
         * Indeks navigasi, BUKAN sumber otorisasi.
         *
         * Tabel ini hanya menjawab "environment mana yang muncul di pengalih milik pengguna ini".
         * Yang menjawab "boleh berbuat apa di dalamnya" tetap `tenant_memberships` beserta rantai
         * role-nya. Bedanya mengikat: baris basi di sini hanya boleh menghasilkan 403 saat masuk,
         * tidak pernah satu pun izin tambahan. Orang pertama yang mengoptimalkan akan tergoda
         * membaca tabel ini sebagai hak akses — jangan.
         */
        Schema::create('environment_members', function (Blueprint $table): void {
            $table->foreignUlid('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamps();

            $table->primary(['environment_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE environment_members ADD CONSTRAINT environment_members_status_dikenal CHECK (status IN ('active', 'suspended'))");

        /*
         * Riwayat per environment, dan jawaban langsung atas cacat berulang repo ini: kegagalan
         * yang tidak dapat dibaca siapa pun. `step` menyimpan langkah terakhir yang tercapai dan
         * `failure_message` menyimpan sebabnya apa adanya.
         *
         * Ia menunjuk environment dengan `restrictOnDelete` supaya riwayat sebuah environment yang
         * dihapus tidak ikut lenyap bersamanya — justru riwayat itu yang dibutuhkan untuk memahami
         * kenapa ia dihapus.
         */
        Schema::create('environment_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('environment_id')->constrained('environments')->restrictOnDelete();
            $table->string('operation', 30);
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('source_environment_id')->nullable();
            $table->string('step', 60)->nullable();
            $table->text('failure_message')->nullable();
            $table->jsonb('detail')->default('{}');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['environment_id', 'started_at']);
        });

        DB::statement("ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_jenis_dikenal CHECK (operation IN ('provision', 'copy', 'migrate', 'disarm', 'suspend', 'resume', 'soft_delete', 'restore', 'purge', 'expire'))");
        DB::statement("ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_status_dikenal CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_selesai_sejalan CHECK ((status = 'running') = (finished_at IS NULL))");
        DB::statement("ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_gagal_beralasan CHECK (status <> 'failed' OR failure_message IS NOT NULL)");

        // Kunci termurah yang tersedia tanpa Redis, sekaligus yang menegakkan anjuran membatasi
        // satu penyalinan pada satu waktu.
        DB::statement("CREATE UNIQUE INDEX environment_operations_satu_berjalan ON environment_operations (environment_id) WHERE status = 'running'");

        $this->backfill();
    }

    /**
     * Tiap tenant yang sudah ada memperoleh satu environment `production`.
     *
     * Ini bukan status yang dikarang: tenant-tenant itu memang sudah bekerja di sebuah tempat, dan
     * tempat itu adalah database yang sedang dipakai. `database_name` dibiarkan kosong karena
     * itulah artinya — ikut koneksi bawaan, bukan database tersendiri.
     */
    private function backfill(): void
    {
        $sekarang = now();

        foreach (DB::table('tenants')->select('id', 'slug')->orderBy('id')->cursor() as $tenant) {
            DB::table('environments')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'kind' => 'production',
                'name' => 'Production',
                'slug' => $tenant->slug,
                'database_name' => null,
                'status' => 'active',
                'outbound_allowed' => true,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }

        // Status membership tidak dibatasi skema dan hanya pernah ditulis `active`. Nilai lain
        // dipetakan ke `suspended` alih-alih disalin apa adanya: satu nilai tak terduga di data
        // lama akan membuat migrasi ini gagal di tengah, dan indeks navigasi tidak layak
        // menjatuhkan sebuah migrasi.
        DB::statement(
            <<<'SQL'
                INSERT INTO environment_members (environment_id, user_id, status, created_at, updated_at)
                SELECT e.id,
                       m.user_id,
                       CASE WHEN m.status = 'active' THEN 'active' ELSE 'suspended' END,
                       ?,
                       ?
                FROM environments e
                JOIN tenant_memberships m ON m.tenant_id = e.tenant_id
                WHERE e.kind = 'production'
                SQL,
            [$sekarang, $sekarang],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_operations');
        Schema::dropIfExists('environment_members');
        Schema::dropIfExists('environments');
    }
};
