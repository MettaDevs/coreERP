<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Foreign key tidak boleh menyeberang batas antara sisi pusat dan sisi environment.
 *
 * Aturannya dikunci di `docs/todo/environment-dan-pusat-admin/README.md`, pada bagian urutan
 * pemisahan: batas dulu, database, baru repo. Bunyinya satu kalimat — tabel sisi environment tidak
 * menunjuk tabel sisi pusat, dan sebaliknya.
 *
 * ## Kenapa penjaganya dibuat hari ini, bukan nanti
 *
 * Hari ini kedua sisi masih satu database, jadi setiap constraint yang menyeberang **berfungsi
 * dengan baik**. Tidak ada yang gagal, tidak ada yang lambat, tidak ada satu pun test yang merah.
 * Pelanggarannya baru menampakkan diri pada hari sisi pusat pindah ke database sendiri — dan pada
 * hari itu ia bukan lagi satu baris migration, melainkan merancang ulang integritas referensial
 * dan menulis ulang setiap `cascadeOnDelete` yang ikut hilang sebagai logika aplikasi. Yang
 * membayar ongkos itu bukan orang yang menambahkannya.
 *
 * Jadi yang dijaga di sini bukan benar atau salahnya skema sekarang. Yang dijaga adalah **angkanya
 * tidak tumbuh diam-diam**.
 *
 * ## Kenapa dibaca dari PostgreSQL, bukan dari daftar
 *
 * Daftar foreign key yang ditulis tangan akan basi tanpa memberi tahu siapa pun: migration baru
 * mendarat, daftarnya tidak ikut diperbarui, dan penjaganya tetap hijau sambil menjaga keadaan yang
 * sudah tidak ada. Karena itu constraint-nya dibaca dari `pg_constraint` — apa yang benar-benar
 * berdiri di database sesudah seluruh migration berjalan, bukan apa yang seseorang ingat menulis.
 *
 * Nama tabel sisi pusat pun tidak ditulis ulang di sini. Ia diturunkan dari model yang memakai
 * `OwnedByControlPlane`, penanda yang sama yang dipakai `BatasPusatTest`. Dua daftar yang sama isinya di dua
 * berkas akan menyimpang; yang satu ini tidak bisa, karena sumbernya memang cuma satu.
 *
 * ## Yang tidak terlihat oleh penjaga ini
 *
 * Ia membaca schema test sesudah migration Core saja. Tabel module tidak ikut dimigrasi di sini —
 * itu pekerjaan `ModuleTableBoundaryTest`, dan ia menjalankan migration module di schema yang sama
 * dengan alasannya sendiri. Hari ini tidak ada satu pun migration module yang membuat foreign key
 * ke `tenants` atau `users`, jadi lubangnya masih kosong; kalau kelak ada, penjaga inilah yang
 * harus diperluas, bukan yang lain.
 */
class FkMenyeberangBatasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Foreign key menyeberang yang sudah telanjur ada, dihitung per tabel tujuan.
     *
     * Ini bukan daftar yang ditulis tangan — ia **anggaran**. Isinya diukur dari database, bukan
     * dikarang, dan satu-satunya arah yang sah baginya adalah turun. Angkanya sengaja disimpan
     * sebagai jumlah, bukan sebagai daftar nama constraint: daftar nama membuat orang menambahkan
     * satu baris tanpa merasa menambah apa-apa, sedangkan angka yang naik terlihat pada diff
     * sebagai apa adanya — satu constraint lagi yang harus dibongkar saat databasenya dipisah.
     *
     * Diukur pada 12 September 2026, sesudah `2026_09_11_100000_create_environment_registry_tables`.
     * Catatan dua di antaranya, karena keduanya mengejutkan kalau tidak ditulis:
     *
     * - `tenant_memberships` ikut ditunjuk dari sisi environment — `role_assignments` dan
     *   `sod_conflicts`. PRD hanya menyebut `tenants` dan `users`, jadi ketiga ini ongkos yang
     *   belum terhitung di sana.
     * - `environments` ditunjuk oleh `environment_members`, yang sebenarnya milik sisi pusat juga.
     *   Ia tidak punya model Eloquent — `RegisterBusiness` menulisnya lewat `DB::table` — sehingga
     *   tidak ada tempat memasang `OwnedByControlPlane` dan penurunan di bawah menempatkannya di sisi
     *   environment. Begitu ia punya model bertanda, baris ini hilang dengan sendirinya dan
     *   angkanya wajib diturunkan.
     *
     * @var array<string, int>
     */
    private const ANGGARAN = [
        'environments' => 1,
        'tenant_memberships' => 3,
        'tenants' => 25,
        'users' => 4,
    ];

    public function test_tidak_ada_fk_dari_sisi_pusat_ke_sisi_environment(): void
    {
        $kelompok = self::kelompokkan($this->foreignKeyDiDatabase(), $this->tabelSisiPusat());

        $this->assertSame([], self::sebutkan($kelompok['pusat-environment']), <<<'PESAN'
            Ada tabel sisi pusat yang menunjuk tabel sisi environment. Arah ini tidak punya anggaran
            dan tidak pernah punya: sisi pusat hidup terus sementara sebuah environment boleh disalin,
            dikosongkan, dan dihapus — constraint ini akan menggantung ke baris yang sudah tidak ada.

            Simpan id-nya sebagai kolom biasa tanpa `constrained()`, atau pindahkan tabelnya ke sisi
            environment kalau isinya memang milik satu environment.
            PESAN);
    }

    public function test_fk_dari_sisi_environment_ke_sisi_pusat_tidak_bertambah(): void
    {
        $menyeberang = self::kelompokkan($this->foreignKeyDiDatabase(), $this->tabelSisiPusat())['environment-pusat'];
        $selisih = self::selisihTerhadapAnggaran($menyeberang, self::ANGGARAN);

        $this->assertSame([], $selisih['bertambah'], self::pesanBertambah($selisih['bertambah'], $menyeberang));
        $this->assertSame([], $selisih['berkurang'], self::pesanBerkurang($selisih['berkurang']));
    }

    /**
     * Penjaganya benar-benar bisa merah, dibuktikan pada database sungguhan.
     *
     * Satu tabel dibuat di tengah test dengan foreign key ke `users`, lalu constraint-nya dibaca
     * ulang lewat jalur yang sama persis dengan yang dipakai penjaga di atas. Kalau pembacaannya
     * salah alamat — schema keliru, filter `contype` keliru, query yang tidak mengembalikan apa pun
     * — tabel ini tidak akan muncul dan test ini yang memberi tahu, bukan pull request orang lain
     * enam bulan lagi. Transaksi `RefreshDatabase` yang membuang tabelnya kembali.
     */
    public function test_tabel_baru_yang_menyeberang_benar_benar_tertangkap(): void
    {
        DB::statement('CREATE TABLE penjaga_batas_percobaan (id bigserial PRIMARY KEY, user_id bigint REFERENCES users(id))');

        try {
            $menyeberang = self::kelompokkan($this->foreignKeyDiDatabase(), $this->tabelSisiPusat())['environment-pusat'];
            $selisih = self::selisihTerhadapAnggaran($menyeberang, self::ANGGARAN);

            $this->assertSame(['users' => 1], $selisih['bertambah'], 'Tabel baru dengan foreign key menyeberang lolos dari pembacanya. Penjaga ini tidak menjaga apa pun.');
            $this->assertStringContainsString('penjaga_batas_percobaan.user_id -> users', self::pesanBertambah($selisih['bertambah'], $menyeberang));
        } finally {
            DB::statement('DROP TABLE penjaga_batas_percobaan');
        }
    }

    /**
     * Pengelompokannya diuji atas daftar palsu, supaya keempat arahnya benar-benar pernah dilalui.
     *
     * Database sungguhan hari ini hanya punya tiga dari empat arah — tidak ada satu pun pusat →
     * environment. Arah yang tidak pernah dilalui adalah arah yang bisa saja salah tanpa ada yang
     * tahu, jadi ia dijalankan di sini atas daftar karangan.
     */
    public function test_pengelompokannya_mengenali_keempat_arah(): void
    {
        $kelompok = self::kelompokkan(
            [
                ['tabel' => 'tenant_memberships', 'kolom' => 'tenant_id', 'tujuan' => 'tenants'],
                ['tabel' => 'organizations', 'kolom' => 'parent_id', 'tujuan' => 'organizations'],
                ['tabel' => 'organizations', 'kolom' => 'tenant_id', 'tujuan' => 'tenants'],
                ['tabel' => 'tenants', 'kolom' => 'organization_id', 'tujuan' => 'organizations'],
            ],
            ['tenants', 'users', 'tenant_memberships'],
        );

        $this->assertSame(['tenant_memberships.tenant_id -> tenants'], self::sebutkan($kelompok['pusat-pusat']));
        $this->assertSame(['organizations.parent_id -> organizations'], self::sebutkan($kelompok['environment-environment']));
        $this->assertSame(['organizations.tenant_id -> tenants'], self::sebutkan($kelompok['environment-pusat']));
        $this->assertSame(['tenants.organization_id -> organizations'], self::sebutkan($kelompok['pusat-environment']));
    }

    /**
     * Anggarannya menangkap pertambahan, penyusutan, dan tujuan yang belum pernah tercatat.
     *
     * Ketiganya diuji atas daftar palsu karena ketiganya tidak dapat dimunculkan sekaligus di
     * database tanpa membongkar migration. Yang ketiga yang paling penting: tujuan baru harus
     * dihitung sebagai pertambahan penuh, bukan diam-diam dianggap nol lawan nol.
     */
    public function test_anggarannya_menangkap_pertambahan_dan_penyusutan(): void
    {
        $selisih = self::selisihTerhadapAnggaran(
            [
                ['tabel' => 'organizations', 'kolom' => 'tenant_id', 'tujuan' => 'tenants'],
                ['tabel' => 'roles', 'kolom' => 'tenant_id', 'tujuan' => 'tenants'],
                ['tabel' => 'roles', 'kolom' => 'pemilik_id', 'tujuan' => 'clients'],
            ],
            ['tenants' => 1, 'users' => 2],
        );

        $this->assertSame(['clients' => 1, 'tenants' => 1], $selisih['bertambah']);
        $this->assertSame(['users' => 2], $selisih['berkurang']);
    }

    public function test_penurunan_tabel_sisi_pusat_sejalan_dengan_penjaga_penandanya(): void
    {
        $diturunkan = $this->tabelSisiPusat();

        $this->assertNotSame([], $diturunkan, 'Tidak satu pun model bertanda OwnedByControlPlane terbaca; penurunannya salah alamat dan seluruh penjaga ini lulus tanpa menguji apa pun.');

        foreach (BatasPusatTest::modelSisiPusat() as [$kelas, $tabel]) {
            $this->assertContains($tabel, $diturunkan, sprintf(
                'Tabel `%s` disepakati milik sisi pusat oleh BatasPusatTest, tetapi tidak muncul saat diturunkan dari %s. '
                .'Kedua penjaga sedang melihat dunia yang berbeda, dan yang ini akan menghitung foreign key ke tabel itu sebagai bukan pelanggaran.',
                $tabel,
                $kelas,
            ));
        }
    }

    /**
     * Nama tabel sisi pusat, diturunkan dari model yang memakai `OwnedByControlPlane`.
     *
     * Modelnya dipindai dari berkas, bukan didaftar. Model yang lupa didaftarkan adalah persis
     * kegagalan yang penjaga ini ada untuk menangkapnya, jadi daftarnya tidak boleh jadi bahan
     * masukannya sendiri.
     *
     * @return list<string>
     */
    private function tabelSisiPusat(): array
    {
        $tabel = [];

        /** @var \SplFileInfo $berkas */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models'), \FilesystemIterator::SKIP_DOTS)) as $berkas) {
            if (! $berkas->isFile() || $berkas->getExtension() !== 'php') {
                continue;
            }

            $kelas = 'App\\Models\\'.str_replace(
                [app_path('Models').DIRECTORY_SEPARATOR, '/', '.php'],
                ['', '\\', ''],
                $berkas->getPathname(),
            );

            if (! class_exists($kelas) || ! is_subclass_of($kelas, Model::class)) {
                continue;
            }

            if (! in_array(OwnedByControlPlane::class, class_uses_recursive($kelas), true)) {
                continue;
            }

            /** @var Model $model */
            $model = new $kelas;
            $tabel[] = $model->getTable();
        }

        $tabel = array_values(array_unique($tabel));
        sort($tabel);

        return $tabel;
    }

    /**
     * Seluruh foreign key yang benar-benar berdiri di schema yang sedang dipakai.
     *
     * `current_schema()`, bukan `'public'`: suite ini berjalan di schema `coreerp_test`, dan pada
     * jalur paralel ia berpindah lagi. Nama schema yang ditulis mati akan membuat pembacaannya
     * mengembalikan nol baris — dan nol baris berarti penjaga ini hijau selamanya tanpa pernah
     * membaca apa pun. Itu sebabnya jumlahnya ikut diperiksa di bawah.
     *
     * @return list<array{tabel: string, kolom: string, tujuan: string}>
     */
    private function foreignKeyDiDatabase(): array
    {
        $baris = DB::select(<<<'SQL'
            SELECT sumber.relname AS tabel,
                   tujuan.relname AS tujuan,
                   (
                       SELECT string_agg(a.attname, ', ' ORDER BY k.urutan)
                       FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, urutan)
                       JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
                   ) AS kolom
            FROM pg_constraint c
            JOIN pg_class sumber ON sumber.oid = c.conrelid
            JOIN pg_class tujuan ON tujuan.oid = c.confrelid
            JOIN pg_namespace n ON n.oid = sumber.relnamespace
            WHERE c.contype = 'f'
              AND n.nspname = current_schema()
            ORDER BY sumber.relname, kolom, tujuan.relname
        SQL);

        $this->assertGreaterThan(
            100,
            count($baris),
            'Pembacaan foreign key mengembalikan hampir tidak ada apa-apa. Schema-nya salah atau migration-nya belum jalan; '
            .'apa pun sebabnya, penjaga ini sedang lulus tanpa melihat satu pun constraint.',
        );

        return array_map(
            static fn (object $b): array => [
                'tabel' => (string) $b->tabel,
                'kolom' => (string) $b->kolom,
                'tujuan' => (string) $b->tujuan,
            ],
            $baris,
        );
    }

    /**
     * Tiap foreign key ditaruh ke salah satu dari empat arah, menurut sisi mana tabelnya berada.
     *
     * @param  list<array{tabel: string, kolom: string, tujuan: string}>  $foreignKey
     * @param  list<string>  $tabelPusat
     * @return array{
     *     'pusat-pusat': list<array{tabel: string, kolom: string, tujuan: string}>,
     *     'environment-environment': list<array{tabel: string, kolom: string, tujuan: string}>,
     *     'environment-pusat': list<array{tabel: string, kolom: string, tujuan: string}>,
     *     'pusat-environment': list<array{tabel: string, kolom: string, tujuan: string}>
     * }
     */
    private static function kelompokkan(array $foreignKey, array $tabelPusat): array
    {
        $kelompok = [
            'pusat-pusat' => [],
            'environment-environment' => [],
            'environment-pusat' => [],
            'pusat-environment' => [],
        ];

        foreach ($foreignKey as $fk) {
            // Sisi environment ditentukan sebagai "bukan pusat", bukan lewat daftarnya sendiri.
            // Tabel baru lahir jauh lebih sering di sisi environment, dan tabel yang tidak dikenali
            // oleh daftar mana pun harus jatuh ke sisi yang diperiksa, bukan ke sisi yang dilewati.
            $dariPusat = in_array($fk['tabel'], $tabelPusat, true);
            $kePusat = in_array($fk['tujuan'], $tabelPusat, true);

            $arah = match (true) {
                $dariPusat && $kePusat => 'pusat-pusat',
                ! $dariPusat && ! $kePusat => 'environment-environment',
                ! $dariPusat => 'environment-pusat',
                default => 'pusat-environment',
            };

            $kelompok[$arah][] = $fk;
        }

        return $kelompok;
    }

    /**
     * Selisih antara yang benar-benar ada dan yang dianggarkan, per tabel tujuan.
     *
     * Tujuan yang tidak ada di anggaran dihitung sebagai pertambahan penuh. Itu bukan detail:
     * tabel sisi pusat berikutnya yang mulai ditunjuk dari sisi environment justru akan datang
     * lewat nama yang belum pernah tercatat di sini.
     *
     * @param  list<array{tabel: string, kolom: string, tujuan: string}>  $menyeberang
     * @param  array<string, int>  $anggaran
     * @return array{bertambah: array<string, int>, berkurang: array<string, int>}
     */
    private static function selisihTerhadapAnggaran(array $menyeberang, array $anggaran): array
    {
        $nyata = [];

        foreach ($menyeberang as $fk) {
            $nyata[$fk['tujuan']] = ($nyata[$fk['tujuan']] ?? 0) + 1;
        }

        $bertambah = [];
        $berkurang = [];

        foreach (array_unique([...array_keys($nyata), ...array_keys($anggaran)]) as $tujuan) {
            $selisih = ($nyata[$tujuan] ?? 0) - ($anggaran[$tujuan] ?? 0);

            if ($selisih > 0) {
                $bertambah[$tujuan] = $selisih;
            } elseif ($selisih < 0) {
                $berkurang[$tujuan] = -$selisih;
            }
        }

        ksort($bertambah);
        ksort($berkurang);

        return ['bertambah' => $bertambah, 'berkurang' => $berkurang];
    }

    /**
     * @param  list<array{tabel: string, kolom: string, tujuan: string}>  $foreignKey
     * @return list<string>
     */
    private static function sebutkan(array $foreignKey): array
    {
        return array_map(
            static fn (array $fk): string => $fk['tabel'].'.'.$fk['kolom'].' -> '.$fk['tujuan'],
            $foreignKey,
        );
    }

    /**
     * @param  array<string, int>  $bertambah
     * @param  list<array{tabel: string, kolom: string, tujuan: string}>  $menyeberang
     */
    private static function pesanBertambah(array $bertambah, array $menyeberang): string
    {
        $terdakwa = [];

        foreach (array_keys($bertambah) as $tujuan) {
            foreach (self::sebutkan(array_values(array_filter($menyeberang, static fn (array $fk): bool => $fk['tujuan'] === $tujuan))) as $baris) {
                $terdakwa[] = '  - '.$baris;
            }
        }

        return sprintf(
            <<<'PESAN'
                Ada foreign key baru dari sisi environment ke sisi pusat: %s.

                Yang menunjuk tabel itu, seluruhnya — punyamu ada di antaranya, dan ia yang tidak ada di `main`:
                %s

                Batas pusat/environment dikunci di docs/todo/environment-dan-pusat-admin/README.md. Hari ini
                kedua sisi masih satu database, jadi constraint ini jalan dan tidak ada yang rusak. Ia baru
                menggigit pada hari sisi pusat pindah ke database sendiri: PostgreSQL tidak mengenal foreign
                key lintas database, jadi constraint ini harus dibongkar dan setiap `cascadeOnDelete` yang
                ikut hilang harus ditulis ulang sebagai logika aplikasi yang seseorang harus ingat memanggilnya.

                Tiga pilihanmu:
                1. Simpan id-nya sebagai kolom biasa, tanpa `constrained()`. Ini jalan yang biasanya benar:
                   yang hilang cuma pemeriksaan yang toh akan hilang juga nanti.
                2. Kalau baris itu sebenarnya milik satu tenant, tunjuk tabel sisi environment yang sudah
                   memegang tenant itu, bukan `tenants` langsung.
                3. Kalau memang harus ada, naikkan angkanya di konstanta ANGGARAN pada berkas ini dan tulis
                   alasannya di pull request. Menaikkannya sah — tetapi itu satu constraint lagi yang harus
                   dibongkar saat databasenya dipisah, dan angkanya sengaja dibuat terlihat supaya keputusan
                   itu diambil dengan sadar, bukan lewat begitu saja.
                PESAN,
            implode(', ', array_map(
                static fn (string $tujuan, int $n): string => $tujuan.' bertambah '.$n,
                array_keys($bertambah),
                array_values($bertambah),
            )),
            implode("\n", $terdakwa),
        );
    }

    /**
     * @param  array<string, int>  $berkurang
     */
    private static function pesanBerkurang(array $berkurang): string
    {
        return sprintf(
            <<<'PESAN'
                Foreign key yang menyeberang batas berkurang: %s. Ini kabar baik, dan yang perlu kamu lakukan
                cuma satu — turunkan angkanya di konstanta ANGGARAN pada berkas ini.

                Anggarannya harus selalu sama persis dengan keadaan, bukan sekadar batas atas. Kalau ia
                dibiarkan lebih tinggi, ruang kosong yang kamu tinggalkan akan terisi diam-diam oleh
                constraint baru dan penjaga ini tidak akan berkata apa-apa.
                PESAN,
            implode(', ', array_map(
                static fn (string $tujuan, int $n): string => $tujuan.' berkurang '.$n,
                array_keys($berkurang),
                array_values($berkurang),
            )),
        );
    }
}
