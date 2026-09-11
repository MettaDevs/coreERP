<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\EditionResolver;
use App\Support\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Penghitung isi edisi, diuji pada modul buatan.
 *
 * Katalog hari ini baru punya satu modul bisnis, jadi aturan yang menentukan — rantai
 * dependency yang dalam, dan modul penghubung yang menunggu kedua sisinya — tidak bisa
 * dibuktikan pada repo apa adanya. Menunggu modul kedua mendarat berarti aturan ini baru
 * diketahui bekerja pada saat ia pertama kali harus bekerja, yaitu pada image pertama yang
 * dikirim ke pelanggan.
 *
 * Modul palsu ditulis ke folder sementara, bukan ke `modules/` yang sungguhan: sisa dari run
 * yang gagal di tengah akan terbaca `module:list`, penjaga batas, Pint, dan PHPStan — kegagalan
 * yang muncul di tempat yang sama sekali tidak berhubungan dengan sebabnya.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel.
 */
class EditionResolverTest extends TestCase
{
    private ?string $akarSementara = null;

    protected function tearDown(): void
    {
        if ($this->akarSementara !== null) {
            $this->hapusFolder($this->akarSementara);
            $this->akarSementara = null;
        }

        parent::tearDown();
    }

    /**
     * Dependency ditutup sedalam rantainya, bukan satu tingkat.
     */
    public function test_dependency_ditutup_secara_transitif(): void
    {
        $resolver = $this->resolverUntuk([
            'apotek' => ['depends_on' => ['inventori']],
            'inventori' => ['depends_on' => ['satuan']],
            'satuan' => [],
            'rawat-jalan' => [],
        ]);

        $this->assertSame(
            ['apotek', 'inventori', 'satuan'],
            $resolver->dariDaftar(['apotek']),
            'Membeli apotek harus membawa inventori dan satuan, dan tidak membawa rawat jalan.',
        );
    }

    /**
     * Inti task ini: modul penghubung ikut hanya ketika kedua sisinya ada.
     */
    public function test_modul_penghubung_ikut_hanya_ketika_kedua_sisinya_ada(): void
    {
        $daftar = [
            'apotek' => [],
            'rawat-jalan' => [],
            'apotek-rawat-jalan' => ['kind' => 'link', 'depends_on' => ['apotek', 'rawat-jalan']],
        ];

        $this->assertSame(
            ['apotek'],
            $this->resolverUntuk($daftar)->dariDaftar(['apotek']),
            'Satu sisi saja tidak cukup; penghubungnya tidak boleh ikut.',
        );

        $this->assertSame(
            ['apotek', 'apotek-rawat-jalan', 'rawat-jalan'],
            $this->resolverUntuk($daftar)->dariDaftar(['apotek', 'rawat-jalan']),
            'Kedua sisi ada, jadi penghubungnya ikut tanpa perlu disebut manifest edisi.',
        );
    }

    /**
     * Penghubung tidak menarik sisinya masuk.
     *
     * Kalau ia menarik, membeli satu integrasi diam-diam membeli dua modul yang tidak dibayar
     * pelanggan — dan itu terlihat sebagai image yang lebih besar, bukan sebagai kesalahan.
     */
    public function test_modul_penghubung_tidak_menarik_sisinya_masuk(): void
    {
        $resolver = $this->resolverUntuk([
            'apotek' => [],
            'rawat-jalan' => [],
            'apotek-rawat-jalan' => ['kind' => 'link', 'depends_on' => ['apotek', 'rawat-jalan']],
        ]);

        $this->assertSame(
            ['apotek', 'apotek-rawat-jalan', 'rawat-jalan'],
            $resolver->dariDaftar(['apotek-rawat-jalan', 'apotek', 'rawat-jalan']),
            'Penghubung yang disebut langsung tetap hanya ikut bersama sisinya, bukan membawanya.',
        );
    }

    /**
     * Penghubung yang bergantung pada penghubung lain ikut juga.
     *
     * Sekali jalan akan melewatkannya, dan yang terlewat tidak berbunyi: image tetap terbangun,
     * hanya integrasinya yang diam-diam tidak ada.
     */
    public function test_penghubung_berlapis_ikut_seluruhnya(): void
    {
        $resolver = $this->resolverUntuk([
            'apotek' => [],
            'rawat-jalan' => [],
            'apotek-rawat-jalan' => ['kind' => 'link', 'depends_on' => ['apotek', 'rawat-jalan']],
            'laporan-terpadu' => ['kind' => 'link', 'depends_on' => ['apotek-rawat-jalan']],
        ]);

        $this->assertSame(
            ['apotek', 'apotek-rawat-jalan', 'laporan-terpadu', 'rawat-jalan'],
            $resolver->dariDaftar(['apotek', 'rawat-jalan']),
        );
    }

    /**
     * Bahan uji internal ditolak, bukan disaring diam-diam.
     */
    public function test_modul_bahan_uji_ditolak_pada_edisi_mana_pun(): void
    {
        $resolver = $this->resolverUntuk([
            'apotek' => [],
            'contoh-a' => ['kind' => 'internal-fixture'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bahan uji internal');

        $resolver->dariDaftar(['apotek', 'contoh-a']);
    }

    /**
     * Id yang tidak ada di repo ditolak, dan pesannya menyebut yang ada.
     *
     * Menyaringnya tanpa suara menghasilkan image yang berhasil dibangun dan kekurangan modul
     * yang dibayar pelanggan — kegagalan yang baru ketahuan di tangan pengguna.
     */
    public function test_modul_yang_tidak_ada_di_repo_ditolak(): void
    {
        $resolver = $this->resolverUntuk(['apotek' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yang tidak ada di repo');

        $resolver->dariDaftar(['modul-yang-tidak-pernah-ada']);
    }

    /**
     * Manifest yang saling menunjuk tidak membuat penelusurannya berputar selamanya.
     *
     * Bentuk ini seharusnya ditolak saat pendaftaran katalog, tetapi penghitung edisi berjalan
     * di CI tanpa database dan tidak pernah melewati pemeriksaan itu. Yang menahannya di sini
     * penandaan sebelum turun, bukan pemeriksaan cycle tersendiri.
     */
    public function test_dependency_yang_berputar_tidak_membuat_penelusuran_menggantung(): void
    {
        $resolver = $this->resolverUntuk([
            'satu' => ['depends_on' => ['dua']],
            'dua' => ['depends_on' => ['satu']],
        ]);

        $this->assertSame(['dua', 'satu'], $resolver->dariDaftar(['satu']));
    }

    /**
     * Edisi yang tidak membeli apa pun sah, dan hasilnya kosong.
     */
    public function test_edisi_tanpa_modul_menghasilkan_daftar_kosong(): void
    {
        $this->assertSame([], $this->resolverUntuk(['apotek' => []])->dariDaftar([]));
    }

    /**
     * Kedua manifest edisi yang ada di repo benar-benar bisa dihitung.
     *
     * Ini yang menghubungkan test ini dengan berkas sungguhan: aturannya boleh dibuktikan pada
     * modul buatan, tetapi manifest yang ikut ter-commit harus tetap sah hari ini.
     */
    public function test_manifest_edisi_di_repo_bisa_dihitung(): void
    {
        $akar = dirname(__DIR__, 5);
        $resolver = new EditionResolver(new ModuleRegistry($akar.'/modules'));

        $this->assertSame(['management-aset'], $resolver->dariBerkas($akar.'/editions/apotek-sejahtera.yaml'));
        $this->assertSame([], $resolver->dariBerkas($akar.'/editions/praktek-dr-budi.yaml'));
    }

    /**
     * Resolver yang membaca folder modul palsu.
     *
     * @param  array<string, array{kind?: string, depends_on?: list<string>}>  $modul
     */
    private function resolverUntuk(array $modul): EditionResolver
    {
        $akar = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-edisi-'.bin2hex(random_bytes(6));
        mkdir($akar.'/modules/apperp', 0o777, true);
        $this->akarSementara = $akar;

        foreach ($modul as $id => $entri) {
            $folder = $akar.'/modules/apperp/'.$id;
            mkdir($folder, 0o777, true);

            $baris = [
                'id: '.$id,
                'name: '.ucwords(str_replace('-', ' ', $id)),
                'version: 0.1.0',
                'publisher: apperp',
                'kind: '.($entri['kind'] ?? 'business-app'),
                'table_prefix: '.str_replace('-', '_', $id).'_',
            ];

            $depends = $entri['depends_on'] ?? [];
            $baris[] = $depends === []
                // Bentuknya peta id ke rentang versi, sama dengan manifest sungguhan dan sama
                // dengan yang dibaca katalog provider. Rentangnya sendiri tidak dipakai
                // penghitung edisi — yang dibacanya kuncinya — tetapi menuliskannya sebagai
                // daftar membuat manifest buatan ini berbeda bentuk dari yang sungguhan, dan
                // sejak itu test berhenti menguji yang nyata.
                ? 'dependsOn: {}'
                : "dependsOn:\n".implode("\n", array_map(static fn (string $d): string => '  '.$d.': ^0.1', $depends));

            file_put_contents($folder.'/app.yaml', implode("\n", $baris)."\n");
        }

        return new EditionResolver(new ModuleRegistry($akar.'/modules'));
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
