<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Support\Modules\PelaksanaTenant;
use App\Support\Modules\TenantScope;
use App\Support\Observabilitas\LaporanKesalahan;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga untuk kesalahan yang terjadi **di luar** permintaan HTTP.
 *
 * Perintah artisan dan pekerja antrean adalah tempat kesalahan paling mudah luput: tidak ada
 * yang menatap layar ketika mereka gagal. Sebelum penjaga ini ada, laporan mereka berisi tepat
 * satu keterangan — "di luar permintaan HTTP" — yang memberi tahu di mana kesalahannya
 * **tidak** terjadi dan tidak satu pun tentang di mana ia terjadi.
 */
class KonteksLaporanKonsolTest extends TestCase
{
    /** @var array<int, string> */
    private array $argvAsli = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->argvAsli = $_SERVER['argv'] ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argvAsli;

        parent::tearDown();
    }

    private function laporan(): string
    {
        return LaporanKesalahan::dari(new RuntimeException('gagal'), null)->keTeks();
    }

    public function test_menyebut_perintah_yang_sedang_berjalan(): void
    {
        // Pertanyaan pertama siapa pun yang membuka laporan konsol adalah "apa yang sedang
        // berjalan". Tanpa ini yang tersisa hanyalah jejak tumpukan.
        $_SERVER['argv'] = ['artisan', 'module:migrate', 'management-aset', '--pretend'];

        $this->assertStringContainsString(
            'perintah : module:migrate management-aset --pretend',
            $this->laporan(),
        );
    }

    public function test_nama_berkas_tidak_ikut_tercetak(): void
    {
        // `artisan` sama saja untuk setiap baris, dan jalur absolutnya memakan tempat tanpa
        // menambah keterangan.
        $_SERVER['argv'] = ['/repo/apps/core/artisan', 'inspire'];

        $laporan = $this->laporan();

        $this->assertStringContainsString('perintah : inspire', $laporan);
        $this->assertStringNotContainsString('/repo/apps/core/artisan', $laporan);
    }

    public function test_tenant_aktif_ikut_terbawa_dari_ikatan_container(): void
    {
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        // Ikatan yang sama dengan yang dibaca TenantScope. Ia bukan tebakan: tanpa ikatan itu
        // query module tidak berjalan sama sekali, jadi pekerjaan yang menyentuh data sebuah
        // tenant pasti memilikinya.
        $laporan = app(PelaksanaTenant::class)->jalankanUntuk(
            '01kyvaf15a83dn64qp2zfr88pn',
            fn (): string => $this->laporan(),
        );

        $this->assertStringContainsString('tenant   : 01kyvaf15a83dn64qp2zfr88pn', $laporan);
    }

    public function test_tanpa_tenant_terikat_dilaporkan_kosong_bukan_ditebak(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        app()->instance(TenantScope::KUNCI, null);

        // Perintah lintas tenant memang tidak punya satu tenant. Menuliskan `-` adalah keadaan
        // sebenarnya; menebak akan membuat laporan berbohong dengan percaya diri.
        $this->assertStringContainsString('tenant   : -', $this->laporan());
    }
}
