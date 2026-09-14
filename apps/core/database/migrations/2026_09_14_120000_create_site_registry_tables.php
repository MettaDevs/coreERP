<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registry situs: server milik klien yang dikelola dari admin.erp lewat agen.
 *
 * Rancangannya di `docs/todo/on-prem-dikelola/README.md`. Tabel-tabel ini milik sisi pusat, sama
 * seperti registry lingkungan; di database lingkungan dan di server klien mereka ada karena migration
 * dibagi, dan kosong.
 *
 * Seperti registry lingkungan, aturan yang tidak boleh dapat dilanggar ditulis sebagai constraint.
 * admin.erp bukan satu-satunya penulis yang mungkin — alur rilis mendaftarkan rilis, dan suatu hari
 * perintah artisan akan ikut menulis — jadi aturan yang hanya dijaga satu controller akan lolos pada
 * penulis berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('profile', 30);
            $table->string('edition', 80);
            $table->string('address', 255)->nullable();
            $table->string('connectivity', 10);

            // Jendela pembaruan yang disepakati dengan klien. Kosong keduanya berarti kapan saja.
            $table->time('update_window_start')->nullable();
            $table->time('update_window_end')->nullable();
            $table->string('timezone', 40)->default('Asia/Jakarta');

            // Kunci publik situs. Kunci privatnya tidak pernah meninggalkan server klien, jadi
            // database ini yang bocor tidak dapat dipakai meniru agen mana pun.
            $table->text('public_key')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->string('reported_edition', 80)->nullable();
            $table->string('reported_release', 40)->nullable();
            $table->string('reported_digest', 100)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_via', 10)->nullable();
            // Laporan terakhir apa adanya. Riwayat lengkapnya di `site_reports`, yang hanya menyimpan
            // laporan yang isinya berubah — heartbeat per menit akan menjadi setengah juta baris
            // per situs per tahun, dan baris di repo ini tidak dihapus.
            $table->jsonb('last_report')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_profil_dikenal CHECK (profile IN ('managed_on_prem'))");
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_konektivitas_dikenal CHECK (connectivity IN ('online', 'offline'))");
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_jendela_berpasangan CHECK ((update_window_start IS NULL) = (update_window_end IS NULL))');
        // Terdaftar berarti ada kunci publiknya, dan sebaliknya. Keduanya lahir di satu tindakan.
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_kunci_berpasangan CHECK ((public_key IS NULL) = (enrolled_at IS NULL))');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_terlihat_berpasangan CHECK ((last_seen_at IS NULL) = (last_seen_via IS NULL))');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_terlihat_lewat_dikenal CHECK (last_seen_via IS NULL OR last_seen_via IN ('heartbeat', 'file'))");

        /*
         * Token pendaftaran. Yang disimpan hash-nya: token yang bocor dari database ini tidak dapat
         * dipakai mendaftar.
         *
         * Sekali pakai ditegakkan di sini, bukan hanya di controller: dua agen yang mendaftar dengan
         * token yang sama pada detik yang sama sama-sama lolos pemeriksaan kode, dan hanya UPDATE
         * bersyarat `used_at IS NULL` yang memutuskan siapa yang duluan.
         */
        Schema::create('site_enrollment_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('channel', 10);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });

        DB::statement("ALTER TABLE site_enrollment_tokens ADD CONSTRAINT site_enrollment_tokens_kanal_dikenal CHECK (channel IN ('online', 'offline'))");

        /*
         * Antrean operasi untuk agen.
         *
         * Berbeda dari `environment_operations` pada satu hal yang menentukan: operasi di sini
         * **menunggu diambil**. Operator memintanya sekarang; agen mengambilnya pada kunjungan
         * berikutnya, mungkin berjam-jam kemudian di dalam jendela pembaruan.
         */
        Schema::create('site_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('operation', 30);
            $table->jsonb('parameters')->default('{}');
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step', 120)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'requested_at']);
        });

        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_jenis_dikenal CHECK (operation IN ('upgrade', 'backup', 'install_license', 'rotate_key', 'send_diagnostics'))");
        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_status_dikenal CHECK (status IN ('requested', 'running', 'succeeded', 'failed', 'cancelled', 'expired'))");
        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_gagal_beralasan CHECK (status <> 'failed' OR failure_message IS NOT NULL)");
        // Yang sedang berjalan selalu punya tenggat. Tanpa tenggat, agen yang mati di tengah jalan
        // memegang operasi selamanya dan situs itu tidak dapat diperintah lagi.
        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_berjalan_bertenggat CHECK (status <> 'running' OR (lease_until IS NOT NULL AND started_at IS NOT NULL))");
        DB::statement("ALTER TABLE site_operations ADD CONSTRAINT site_operations_selesai_sejalan CHECK ((status IN ('requested', 'running')) = (finished_at IS NULL))");

        // Satu yang berjalan per situs: `update.sh` dan pencadangan yang berjalan bersamaan saling
        // merusak database yang sama.
        DB::statement("CREATE UNIQUE INDEX site_operations_satu_berjalan ON site_operations (site_id) WHERE status = 'running'");
        // Dan satu permintaan per jenis per situs. Operator yang menekan "Perbarui" dua kali tidak
        // boleh melahirkan dua pembaruan beruntun.
        DB::statement("CREATE UNIQUE INDEX site_operations_satu_permintaan_per_jenis ON site_operations (site_id, operation) WHERE status = 'requested'");

        /*
         * Laporan yang isinya berubah, beserta asalnya.
         */
        Schema::create('site_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('via', 10);
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('reported_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['site_id', 'received_at']);
        });

        DB::statement("ALTER TABLE site_reports ADD CONSTRAINT site_reports_asal_dikenal CHECK (via IN ('heartbeat', 'file'))");

        /*
         * Berkas rilis bertanda tangan, didaftarkan alur rilis.
         *
         * Isinya disimpan apa adanya karena agen memverifikasinya sendiri dengan kunci publik rilis:
         * satu byte yang berubah di sini membuat tanda tangannya tidak sah, dan agen menolaknya.
         */
        Schema::create('site_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('edition', 80);
            $table->string('release', 40);
            $table->string('image', 255);
            $table->string('digest', 100);
            $table->text('manifest');
            $table->text('compose');
            $table->text('update_script');
            $table->text('checksums');
            $table->text('signature');
            $table->timestamps();

            $table->unique(['edition', 'release']);
        });

        /*
         * Jejak tindakan operator: siapa, terhadap apa, apa, kapan, dan hasilnya.
         *
         * Hanya-tambah, dan ditegakkan database. Jejak audit yang dapat disunting orang yang
         * tindakannya dicatat bukan jejak audit.
         */
        Schema::create('operator_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 40);
            $table->string('subject_id', 40)->nullable();
            $table->jsonb('detail')->default('{}');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION operator_audit_events_tolak_ubah() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'operator_audit_events hanya boleh ditambah, tidak diubah atau dihapus';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER operator_audit_events_hanya_tambah
                BEFORE UPDATE OR DELETE ON operator_audit_events
                FOR EACH ROW EXECUTE FUNCTION operator_audit_events_tolak_ubah();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_hanya_tambah ON operator_audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS operator_audit_events_tolak_ubah()');
        Schema::dropIfExists('operator_audit_events');
        Schema::dropIfExists('site_releases');
        Schema::dropIfExists('site_reports');
        Schema::dropIfExists('site_operations');
        Schema::dropIfExists('site_enrollment_tokens');
        Schema::dropIfExists('sites');
    }
};
