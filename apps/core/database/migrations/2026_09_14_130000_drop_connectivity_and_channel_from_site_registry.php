<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registry situs hanya mengenal satu jalan: agen yang menarik operasinya dari admin.erp lewat HTTPS.
 *
 * Pemilik produk memutuskan pada 14 September 2026 bahwa klien tanpa internet tidak akan pernah ada.
 * Empat kolom di `create_site_registry_tables` hanya ada untuk membedakan jalan yang lain itu, dan
 * dengan satu jalan isinya selalu sama:
 *
 * - `sites.connectivity` — selalu `online`;
 * - `sites.last_seen_via` — selalu `heartbeat`, atau kosong bersama `last_seen_at`;
 * - `site_enrollment_tokens.channel` — selalu `online`;
 * - `site_reports.via` — selalu `heartbeat`.
 *
 * ## Menolak, bukan membuang
 *
 * Baris yang isinya jalan online tidak kehilangan apa pun: nilainya dapat diturunkan lagi, dan
 * `down()` memang menurunkannya. Baris yang menyebut jalan lain memuat fakta yang tidak dapat
 * diturunkan — situs mana yang pernah didaftarkan tanpa internet, laporan mana yang datang sebagai
 * file. Migration ini berhenti dengan daftarnya alih-alih membuangnya diam-diam; memutuskan nasib
 * situs itu keputusan orang, bukan migration.
 *
 * Tabel ini juga ada, kosong, di setiap database lingkungan dan di server klien karena migration-nya
 * dibagi. Di sana pemeriksaannya tidak menemukan apa pun dan kolomnya langsung dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dikunci sebelum diperiksa. Konsol yang masih menjalankan kode lama selama penyebaran dapat
        // menulis baris jalan lain di antara pemeriksaan dan pembuangan kolom; baris itu akan hilang
        // tanpa pernah terbaca oleh pemeriksaan di bawah. Langsung ACCESS EXCLUSIVE, kunci yang memang
        // dituntut DROP COLUMN: kunci yang lebih lemah lalu dinaikkan dapat berbuntu dengan pembaca
        // yang sedang menunggu untuk menulis.
        DB::statement('LOCK TABLE sites, site_enrollment_tokens, site_reports IN ACCESS EXCLUSIVE MODE');

        $this->refuseRowsFromTheOtherPath();

        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_konektivitas_dikenal');
        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_terlihat_berpasangan');
        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_terlihat_lewat_dikenal');
        DB::statement('ALTER TABLE site_enrollment_tokens DROP CONSTRAINT site_enrollment_tokens_kanal_dikenal');
        DB::statement('ALTER TABLE site_reports DROP CONSTRAINT site_reports_asal_dikenal');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['connectivity', 'last_seen_via']);
        });

        Schema::table('site_enrollment_tokens', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });

        Schema::table('site_reports', function (Blueprint $table): void {
            $table->dropColumn('via');
        });
    }

    /**
     * Mengembalikan keempat kolom beserta constraint aslinya, diisi nilai jalan online.
     *
     * Setiap baris yang ada sesudah `up()` lahir lewat jalan online, jadi nilainya bukan tebakan:
     * situs `online`, token `online`, laporan `heartbeat`, dan `last_seen_via` `heartbeat` tepat pada
     * situs yang pernah terlihat — pasangan yang dituntut `sites_terlihat_berpasangan`.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('connectivity', 10)->default('online');
            $table->string('last_seen_via', 10)->nullable();
        });

        DB::statement("UPDATE sites SET last_seen_via = 'heartbeat' WHERE last_seen_at IS NOT NULL");
        DB::statement('ALTER TABLE sites ALTER COLUMN connectivity DROP DEFAULT');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_konektivitas_dikenal CHECK (connectivity IN ('online', 'offline'))");
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_terlihat_berpasangan CHECK ((last_seen_at IS NULL) = (last_seen_via IS NULL))');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_terlihat_lewat_dikenal CHECK (last_seen_via IS NULL OR last_seen_via IN ('heartbeat', 'file'))");

        Schema::table('site_enrollment_tokens', function (Blueprint $table): void {
            $table->string('channel', 10)->default('online');
        });

        DB::statement('ALTER TABLE site_enrollment_tokens ALTER COLUMN channel DROP DEFAULT');
        DB::statement("ALTER TABLE site_enrollment_tokens ADD CONSTRAINT site_enrollment_tokens_kanal_dikenal CHECK (channel IN ('online', 'offline'))");

        Schema::table('site_reports', function (Blueprint $table): void {
            $table->string('via', 10)->default('heartbeat');
        });

        DB::statement('ALTER TABLE site_reports ALTER COLUMN via DROP DEFAULT');
        DB::statement("ALTER TABLE site_reports ADD CONSTRAINT site_reports_asal_dikenal CHECK (via IN ('heartbeat', 'file'))");
    }

    private function refuseRowsFromTheOtherPath(): void
    {
        $lines = [];

        $sites = DB::table('sites')
            ->where('connectivity', 'offline')
            ->orWhere('last_seen_via', 'file')
            ->orderBy('name')
            ->get(['id', 'name', 'connectivity', 'last_seen_via']);

        foreach ($sites as $site) {
            $lines[] = sprintf(
                '  - sites %s "%s": connectivity = %s, last_seen_via = %s',
                $site->id,
                $site->name,
                $site->connectivity,
                $site->last_seen_via ?? 'kosong',
            );
        }

        $tokens = DB::table('site_enrollment_tokens')
            ->where('channel', 'offline')
            ->groupBy('site_id')
            ->orderBy('site_id')
            ->selectRaw('site_id, count(*) as jumlah')
            ->get();

        foreach ($tokens as $token) {
            $lines[] = sprintf('  - site_enrollment_tokens situs %s: %d token channel = offline', $token->site_id, $token->jumlah);
        }

        $reports = DB::table('site_reports')
            ->where('via', 'file')
            ->groupBy('site_id')
            ->orderBy('site_id')
            ->selectRaw('site_id, count(*) as jumlah')
            ->get();

        foreach ($reports as $report) {
            $lines[] = sprintf('  - site_reports situs %s: %d laporan via = file', $report->site_id, $report->jumlah);
        }

        if ($lines === []) {
            return;
        }

        throw new RuntimeException(
            "Registry situs kini hanya mengenal situs yang menarik operasinya dari admin.erp lewat internet,\n"
            ."dan migration ini membuang kolom yang membedakan jalan tanpa internet. Baris berikut masih\n"
            ."menyebut jalan itu, dan isinya akan hilang bersama kolomnya:\n"
            .implode("\n", $lines)."\n"
            ."Tidak ada yang diubah. Putuskan nasib baris itu dengan sadar — salin dulu bila riwayatnya perlu\n"
            .'disimpan, lalu ubah atau hapus — dan jalankan migrate lagi.'
        );
    }
};
