<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Setiap migration baru harus dapat dijalankan bersama kode rilis sebelumnya (aturan N-1).
 *
 * Aturannya di `docs/dev/03-release-and-on-prem.md`, bagian "Perubahan skema dan mundur". Bunyinya
 * satu kalimat: **yang dimundurkan saat rilis bermasalah adalah image, bukan database.** Database
 * yang dipulihkan dari cadangan membuang setiap transaksi sesudah pembaruan — di klinik, itu rekam
 * medis dan kuitansi — jadi mundur hanya aman bila skema baru masih dapat dipakai kode lama.
 *
 * ## Yang ditolak, dan kenapa itu yang ditolak
 *
 * Kode rilis sebelumnya membaca dan menulis kolom dengan nama, tipe, dan kewajiban yang ia kenal.
 * Migration yang menghapus, mengganti nama, mengubah tipe, atau mewajibkan isi tanpa nilai bawaan
 * membuat kode itu gagal pada permintaan pertama. Menambah tabel, menambah kolom yang boleh kosong
 * atau punya nilai bawaan, dan melonggarkan constraint tidak ditolak.
 *
 * Perubahan yang memang harus merusak dikerjakan bertahap (expand/contract): kolom baru ditambahkan
 * dan diisi lebih dulu, kode berpindah membacanya, dan penghapusan yang lama baru dikirim di rilis
 * sesudahnya. Migration penghapusan itu menyatakannya dengan penanda `@kontrak`, beserta alasannya.
 * Perubahan yang terbaca pola ini tetapi aman untuk kode lama — misalnya melebarkan tipe kolom —
 * memakai `@kompatibel-mundur`, juga beserta alasannya. Keduanya keputusan yang dibaca peninjau,
 * bukan pengecualian yang diam-diam.
 *
 * ## Kenapa dibaca dari berkas, bukan dari database
 *
 * Yang dijaga adalah **niat** migration, dan niat itu ada di teks `up()`. Database sesudah migrate
 * tidak lagi menyimpan bahwa sebuah kolom pernah ada lalu dihapus. Pembacaannya kasar — ia pola, bukan
 * pengurai PHP — sehingga ia sengaja lebih sering curiga daripada lengah, dan penandanya yang
 * menyelesaikan kecurigaan yang keliru.
 */
final class MigrasiKompatibelMundurTest extends TestCase
{
    /**
     * Migration yang merusak kode lama tetapi lahir sebelum aturan ini berlaku.
     *
     * Ditulis sebelum 16 September 2026 dan sebelum klien on-prem pertama terpasang: tidak ada server
     * yang dapat dimundurkan melewati migration ini. Daftarnya **beku** — satu-satunya arah yang sah
     * adalah berkurang, dan test di bawah menolak entri yang sudah tidak ada atau tidak lagi memuat pola.
     * Migration baru tidak pernah masuk ke sini; ia memakai penanda.
     */
    private const SEBELUM_ATURAN = [
        'apps/core/database/migrations/2026_07_23_010000_rename_module_records_to_app_records.php',
        'apps/core/database/migrations/2026_07_24_010000_standardize_legal_entity_company_code.php',
        'apps/core/database/migrations/2026_07_26_020000_add_fiscal_reset_to_number_sequences.php',
        'apps/core/database/migrations/2026_07_26_030000_widen_number_sequence_reservation_status.php',
        'apps/core/database/migrations/2026_07_26_060000_add_fast_token_hash_to_app_service_credentials.php',
        'apps/core/database/migrations/2026_07_28_091000_correct_workflow_type_app_id.php',
        'apps/core/database/migrations/2026_07_30_150000_make_invitation_codes_reusable.php',
        'apps/core/database/migrations/2026_08_03_090000_rename_workflow_actor_columns_to_memberships.php',
        'apps/core/database/migrations/2026_08_03_120000_add_tenant_security_configuration.php',
        'apps/core/database/migrations/2026_08_17_090000_derive_app_content_path_from_placement.php',
        'apps/core/database/migrations/2026_09_03_000001_widen_division_id_in_translations_and_external_codes.php',
        'apps/core/database/migrations/2026_09_08_110000_make_app_database_name_optional.php',
        'apps/core/database/migrations/2026_09_09_100000_collapse_app_release_images_to_one_edition_image.php',
        'apps/core/database/migrations/2026_09_14_130000_drop_connectivity_and_channel_from_site_registry.php',
        'modules/apperp/management-aset/database/migrations/2026_07_28_092000_widen_asset_user_reference_columns.php',
        'modules/apperp/management-aset/database/migrations/2026_07_30_120000_rename_transaction_tables.php',
        'modules/apperp/management-aset/database/migrations/2026_07_30_123500_remove_asset_satuan_master.php',
        'modules/apperp/management-aset/database/migrations/2026_08_12_110000_align_group_aset_with_fixed_asset_group.php',
        'modules/apperp/management-aset/database/migrations/2026_08_12_120000_make_depreciation_export_opt_in.php',
        'modules/apperp/management-aset/database/migrations/2026_08_15_140000_drop_maintenance_job_type_requirement.php',
        'modules/apperp/management-aset/database/migrations/2026_08_15_160000_retire_work_order_type_mandatory_flags.php',
        'modules/apperp/management-aset/database/migrations/2026_08_18_100000_allow_asset_books_without_a_depreciation_profile.php',
        'modules/apperp/management-aset/database/migrations/2026_08_18_110000_drop_posting_layers_from_group_aset.php',
        'modules/apperp/management-aset/database/migrations/2026_08_19_090000_add_nama_to_asset_register.php',
        'modules/apperp/management-aset/database/migrations/2026_09_08_130000_prefix_tabel_modul.php',
    ];

    /** Pola di `up()` yang membuat kode rilis sebelumnya gagal. */
    private const POLA = [
        'menghapus kolom' => '/->dropColumns?\s*\(/',
        'mengganti nama kolom' => '/->renameColumn\s*\(/',
        'mengubah definisi kolom (->change())' => '/->change\s*\(\s*\)/',
        'menghapus tabel' => '/Schema::(drop|dropIfExists|dropColumns)\s*\(/',
        'mengganti nama tabel' => '/Schema::rename\s*\(/',
        'DROP COLUMN' => '/\bDROP\s+COLUMN\b/i',
        'DROP TABLE' => '/\bDROP\s+TABLE\b/i',
        'RENAME COLUMN / RENAME TO' => '/\bRENAME\s+(COLUMN|TO)\b/i',
        'mengubah tipe kolom' => '/\bALTER\s+COLUMN\s+\S+\s+(SET\s+DATA\s+)?TYPE\b/i',
        'SET NOT NULL' => '/\bSET\s+NOT\s+NULL\b/i',
    ];

    /** Tipe kolom Blueprint yang, tanpa nullable atau nilai bawaan, mewajibkan isi pada tabel yang sudah ada. */
    private const TIPE_KOLOM = 'string|text|mediumText|longText|char|integer|tinyInteger|smallInteger|bigInteger|unsignedInteger|unsignedBigInteger|decimal|float|double|boolean|date|dateTime|dateTimeTz|time|timestamp|timestampTz|json|jsonb|uuid|ulid|foreignId|foreignUlid|foreignUuid|enum|ipAddress';

    public function test_setiap_migration_yang_merusak_kode_lama_menyatakan_keputusannya(): void
    {
        $pelanggaran = [];

        foreach ($this->berkasMigration() as $jalur => $isi) {
            if (in_array($jalur, self::SEBELUM_ATURAN, true)) {
                continue;
            }

            $temuan = self::temuan($isi);

            if ($temuan !== [] && self::penanda($isi) === null) {
                $pelanggaran[] = '  - '.$jalur.': '.implode('; ', $temuan);
            }
        }

        $this->assertSame([], $pelanggaran, "Migration berikut membuat kode rilis sebelumnya gagal, tanpa menyatakan keputusannya:\n"
            .implode("\n", $pelanggaran)."\n\n"
            ."Mundur ke rilis sebelumnya hanya mengganti image, tidak memulihkan database — memulihkan database\n"
            ."membuang setiap transaksi sesudah pembaruan. Pilihannya:\n"
            ."1. Ubah menjadi langkah expand: tambah kolom/tabel baru yang boleh kosong atau bernilai bawaan,\n"
            ."   biarkan yang lama, dan hapus yang lama di rilis berikutnya.\n"
            ."2. Bila ini memang langkah contract (yang lama sudah tidak dibaca kode rilis sebelumnya), tulis\n"
            ."   `@kontrak <alasan dan rilis yang berhenti membacanya>` di docblock migration.\n"
            ."3. Bila pola ini keliru menuduh — misalnya melebarkan tipe kolom — tulis\n"
            ."   `@kompatibel-mundur <alasan kode lama tetap bekerja>`.\n"
            .'Aturannya: docs/dev/03-release-and-on-prem.md, bagian "Perubahan skema dan mundur".');
    }

    public function test_daftar_sebelum_aturan_hanya_boleh_berkurang(): void
    {
        $berkas = $this->berkasMigration();
        $basi = [];

        foreach (self::SEBELUM_ATURAN as $jalur) {
            if (! isset($berkas[$jalur])) {
                $basi[] = '  - '.$jalur.' (berkasnya tidak ada lagi)';
            } elseif (self::temuan($berkas[$jalur]) === []) {
                $basi[] = '  - '.$jalur.' (tidak lagi memuat pola yang merusak)';
            }
        }

        $this->assertSame([], $basi, "Entri SEBELUM_ATURAN berikut sudah tidak berlaku. Hapus dari daftar — entri yang tertinggal\n"
            ."adalah tempat kosong yang kelak diisi migration baru tanpa ada yang menyadarinya:\n".implode("\n", $basi));
    }

    /**
     * Pembacaannya benar-benar menangkap setiap bentuk, dan tidak menuduh bentuk yang aman.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function contoh(): iterable
    {
        yield 'hapus kolom' => [self::migration("Schema::table('a', function (Blueprint \$table) {\n    \$table->dropColumn('b');\n});"), true];
        yield 'hapus beberapa kolom' => [self::migration("Schema::table('a', function (Blueprint \$table) {\n    \$table->dropColumns(['b', 'c']);\n});"), true];
        yield 'ganti nama kolom' => [self::migration("Schema::table('a', function (Blueprint \$table) {\n    \$table->renameColumn('b', 'c');\n});"), true];
        yield 'ubah definisi kolom' => [self::migration("Schema::table('a', function (Blueprint \$table) {\n    \$table->string('b', 10)->change();\n});"), true];
        yield 'hapus tabel' => [self::migration("Schema::dropIfExists('a');"), true];
        yield 'ganti nama tabel' => [self::migration("Schema::rename('a', 'b');"), true];
        yield 'SQL drop column' => [self::migration("DB::statement('ALTER TABLE a DROP COLUMN b');"), true];
        yield 'SQL rename' => [self::migration("DB::statement('ALTER TABLE a RENAME COLUMN b TO c');"), true];
        yield 'SQL ubah tipe' => [self::migration("DB::statement('ALTER TABLE a ALTER COLUMN b TYPE bigint');"), true];
        yield 'SQL wajib isi' => [self::migration("DB::statement('ALTER TABLE a ALTER COLUMN b SET NOT NULL');"), true];
        yield 'kolom wajib tanpa bawaan pada tabel lama' => [self::migration("Schema::table('a', function (Blueprint \$table): void {\n    \$table->string('b', 20);\n});"), true];
        yield 'foreign wajib pada tabel lama' => [self::migration("Schema::table('a', function (Blueprint \$table): void {\n    \$table->foreignUlid('b')->constrained();\n});"), true];

        yield 'tabel baru' => [self::migration("Schema::create('a', function (Blueprint \$table) {\n    \$table->ulid('id')->primary();\n    \$table->string('b');\n});"), false];
        yield 'kolom boleh kosong' => [self::migration("Schema::table('a', function (Blueprint \$table): void {\n    \$table->ulid('b')->nullable();\n});"), false];
        yield 'kolom bernilai bawaan' => [self::migration("Schema::table('a', function (Blueprint \$table): void {\n    \$table->string('hosting', 20)->default('provider');\n});"), false];
        yield 'index dan constraint' => [self::migration("Schema::table('a', function (Blueprint \$table): void {\n    \$table->index(['b', 'c']);\n});\nDB::statement('ALTER TABLE a ADD CONSTRAINT a_b CHECK (b > 0)');"), false];
        yield 'penghapusan hanya di down()' => [self::migration("Schema::create('a', function (Blueprint \$table) {\n    \$table->ulid('id');\n});", "Schema::dropIfExists('a');"), false];
    }

    #[DataProvider('contoh')]
    public function test_pembacaannya_menangkap_yang_merusak_dan_melewati_yang_aman(string $migration, bool $merusak): void
    {
        $this->assertSame($merusak, self::temuan($migration) !== [], $merusak
            ? 'Bentuk yang merusak kode lama lolos dari pembacaan. Penjaga ini tidak menjaga apa pun untuk bentuk itu.'
            : 'Bentuk yang aman bagi kode lama dituduh merusak.');
    }

    public function test_penanda_hanya_sah_dengan_alasan(): void
    {
        $this->assertSame('kontrak', self::penanda("/**\n * @kontrak kolom lama tidak dibaca lagi sejak rilis 1.3.0\n */"));
        $this->assertSame('kompatibel-mundur', self::penanda("/**\n * @kompatibel-mundur varchar dilebarkan, kode lama menulis nilai yang lebih pendek\n */"));
        $this->assertNull(self::penanda("/**\n * @kontrak\n */"), 'Penanda tanpa alasan sama dengan pengecualian diam-diam.');
        $this->assertNull(self::penanda("/**\n * @kontrak aman\n */"), 'Alasan satu kata tidak menjelaskan apa pun kepada peninjau.');
        $this->assertNull(self::penanda('// kontrak'), 'Hanya bentuk @penanda di docblock yang dihitung.');
    }

    /** @return array<string, string> jalur relatif akar repo → isi */
    private function berkasMigration(): array
    {
        $akar = dirname(__DIR__, 5);
        $pola = [
            $akar.'/apps/core/database/migrations/*.php',
            $akar.'/modules/*/*/database/migrations/*.php',
        ];
        $hasil = [];

        foreach ($pola as $glob) {
            foreach (glob($glob) ?: [] as $berkas) {
                $isi = file_get_contents($berkas);
                $jalur = str_replace('\\', '/', substr($berkas, strlen($akar) + 1));
                $hasil[$jalur] = is_string($isi) ? $isi : '';
            }
        }

        $this->assertGreaterThan(100, count($hasil), 'Hampir tidak ada migration yang terbaca; jalurnya salah dan penjaga ini lulus tanpa membaca apa pun.');
        ksort($hasil);

        return $hasil;
    }

    /** @return list<string> */
    private static function temuan(string $isi): array
    {
        $up = self::badanUp($isi);
        $temuan = [];

        foreach (self::POLA as $nama => $regex) {
            if (preg_match($regex, $up) === 1) {
                $temuan[] = $nama;
            }
        }

        // Kolom wajib tanpa nilai bawaan pada tabel yang sudah ada: kode lama menyisipkan baris tanpa
        // kolom itu, dan PostgreSQL menolaknya. Tabel baru tidak terpengaruh — kode lama tidak mengenalnya.
        if (preg_match_all('/Schema::table\s*\(\s*[\'"](\w+)[\'"].*?\n\s*\}\s*\)\s*;/s', $up, $blok, PREG_SET_ORDER) > 0) {
            foreach ($blok as [$teks, $tabel]) {
                foreach (explode(';', $teks) as $pernyataan) {
                    if (preg_match('/\$table->('.self::TIPE_KOLOM.')\s*\(\s*[\'"](\w+)[\'"]/', $pernyataan, $kolom) === 1
                        && preg_match('/->(nullable|default|useCurrent|storedAs|virtualAs|change)\s*\(/', $pernyataan) !== 1) {
                        $temuan[] = 'kolom wajib tanpa nilai bawaan '.$tabel.'.'.$kolom[2];
                    }
                }
            }
        }

        return $temuan;
    }

    /** Isi `up()`: dari tanda tangan method itu sampai `down()`, atau sampai akhir berkas. */
    private static function badanUp(string $isi): string
    {
        if (preg_match('/function\s+up\s*\(\s*\)[^{]*\{/', $isi, $awal, PREG_OFFSET_CAPTURE) !== 1) {
            return $isi;
        }

        $mulai = $awal[0][1] + strlen($awal[0][0]);
        $sisa = substr($isi, $mulai);

        return preg_match('/function\s+down\s*\(/', $sisa, $akhir, PREG_OFFSET_CAPTURE) === 1
            ? substr($sisa, 0, $akhir[0][1])
            : $sisa;
    }

    /** Penanda yang sah: `@kontrak` atau `@kompatibel-mundur` di docblock, dengan alasan paling sedikit tiga kata. */
    private static function penanda(string $isi): ?string
    {
        if (preg_match('/^\s*\*\s*@(kontrak|kompatibel-mundur)[ \t]+(\S+[ \t]+\S+[ \t]+\S+.*)$/m', $isi, $cocok) !== 1) {
            return null;
        }

        return $cocok[1];
    }

    private static function migration(string $up, string $down = ''): string
    {
        return "<?php\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n".$up."\n    }\n\n    public function down(): void\n    {\n".$down."\n    }\n};\n";
    }
}
