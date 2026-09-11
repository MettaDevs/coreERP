<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Http\Middleware\ResolveModuleContext;
use App\Support\CurrentWorkspace;
use App\Support\Modules\ModuleRequestContext;
use App\Support\Observabilitas\LaporanKesalahan;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Penjaga untuk laporan kesalahan internal.
 *
 * Tiga sifat yang dijaga, dan ketiganya baru terlihat rusak pada saat paling buruk — ketika
 * seseorang sedang membaca laporan di tengah insiden:
 *
 * 1. **Laporan tetap terbentuk meski konteksnya tidak lengkap.** Rute control-plane tidak
 *    melewati middleware module, jadi seluruh atribut tenant kosong di sana. Laporan yang
 *    gagal tersusun karena itu berarti kesalahan yang paling sering terjadi justru yang
 *    paling jarang tercatat.
 * 2. **Laporan tidak menyentuh database ketika database yang gagal.** Ini yang paling mudah
 *    hilang dalam refactor berikutnya, dan akibatnya bukan test merah melainkan permintaan
 *    yang menggantung satu batas waktu koneksi untuk setiap kesalahan.
 * 3. **Kesalahan 4xx tidak dilaporkan.** 404 bukan kesalahan internal; membiarkannya masuk
 *    mengubur laporan yang berarti di bawah lalu lintas biasa.
 */
class LaporanKesalahanTest extends TestCase
{
    public function test_konteks_module_lengkap_muncul_di_laporan(): void
    {
        $permintaan = Request::create('https://erp.test/management-aset/entitas', 'POST');
        $permintaan->attributes->set(ModuleRequestContext::TENANT_ID, 'tenant-01');
        $permintaan->attributes->set(ResolveModuleContext::MODULE_AKTIF, 'management-aset');
        $permintaan->attributes->set(ModuleRequestContext::LEGAL_ENTITY_ID, 'entitas-01');
        $permintaan->attributes->set(ModuleRequestContext::ORG_UNIT_ID, 'unit-01');
        $permintaan->attributes->set(ModuleRequestContext::USER_ID, 'pengguna-01');

        $laporan = LaporanKesalahan::dari(new RuntimeException('gagal menyimpan'), $permintaan);

        $teks = $laporan->keTeks();
        $this->assertStringContainsString('POST', $teks);
        $this->assertStringContainsString('/management-aset/entitas', $teks);
        $this->assertStringContainsString('tenant-01', $teks);
        $this->assertStringContainsString('management-aset', $teks);
        $this->assertStringContainsString('gagal menyimpan', $teks);

        $atribut = $laporan->keAtribut();
        $this->assertSame('tenant-01', $atribut['coreerp.tenant_id']);
        $this->assertSame('management-aset', $atribut['coreerp.module_id']);
        $this->assertSame('entitas-01', $atribut['coreerp.legal_entity_id']);
        $this->assertSame('unit-01', $atribut['coreerp.org_unit_id']);
        $this->assertSame('pengguna-01', $atribut['coreerp.user_id']);
        $this->assertSame(RuntimeException::class, $atribut['exception.type']);
        $this->assertSame(500, $atribut['http.response.status_code']);
    }

    public function test_rute_tanpa_konteks_tenant_tetap_menghasilkan_laporan(): void
    {
        // Persis bentuk sebuah permintaan ke `dashboard` atau `settings/*`: tidak satu pun
        // middleware module berjalan, jadi tidak satu pun atribut tersedia.
        $permintaan = Request::create('https://erp.test/dashboard', 'GET');

        $laporan = LaporanKesalahan::dari(new RuntimeException('meledak'), $permintaan);

        $teks = $laporan->keTeks();
        $this->assertStringContainsString('meledak', $teks);
        $this->assertStringContainsString('tenant   : -', $teks);
        $this->assertStringContainsString('module   : -', $teks);

        $this->assertArrayNotHasKey('coreerp.tenant_id', array_filter($laporan->keAtribut()));
    }

    public function test_kegagalan_kueri_menampilkan_sql_dan_pesan_driver(): void
    {
        $laporan = LaporanKesalahan::dari($this->kesalahanKueri(), Request::create('https://erp.test/x', 'POST'));

        $teks = $laporan->keTeks();
        $this->assertStringContainsString('duplicate key value', $teks);
        $this->assertStringContainsString('insert into "aset"', $teks);
        $this->assertStringContainsString("'AST-001'", $teks, 'nilai binding ikut ditulis');
        $this->assertStringContainsString('SQLSTATE 23505', $teks);

        $atribut = $laporan->keAtribut();
        $this->assertSame('pgsql', $atribut['db.system']);
        $this->assertSame('core_erp', $atribut['db.namespace']);
        $this->assertSame('23505', $atribut['db.response.status_code']);
        $this->assertStringContainsString('?', (string) $atribut['db.query.text'], 'bentuk berparameter dipertahankan untuk pengelompokan');
    }

    /**
     * Penjaga paling penting di berkas ini.
     *
     * `CurrentWorkspace::membership()` menjalankan query **dan** menulis sesi. Memanggilnya
     * saat yang gagal justru database berarti menanyakan pada database kenapa database mati:
     * satu batas waktu koneksi dibayar untuk setiap laporan, dan penangan kesalahan berisiko
     * melempar kesalahan kedua di atas kesalahan pertama.
     *
     * Penjaga ini tidak akan terlihat hilang dari luar — laporannya tetap terbentuk, hanya
     * lebih lambat dan lebih rapuh. Karena itu ia diuji dari dalam container.
     */
    public function test_kegagalan_database_tidak_menyentuh_sesi_maupun_database_lagi(): void
    {
        $disentuh = false;

        $this->app->bind(CurrentWorkspace::class, function () use (&$disentuh): CurrentWorkspace {
            $disentuh = true;

            return new CurrentWorkspace;
        });

        $permintaan = Request::create('https://erp.test/dashboard', 'GET');
        $permintaan->setLaravelSession($this->app['session']->driver());

        LaporanKesalahan::dari($this->kesalahanKueri(), $permintaan);

        $this->assertFalse($disentuh, 'CurrentWorkspace tidak boleh diselesaikan ketika kesalahannya menyangkut database');
    }

    public function test_pdo_exception_telanjang_juga_dianggap_kegagalan_database(): void
    {
        // Koneksi ditolak sebelum satu query pun tersusun tidak pernah menjadi
        // `QueryException` — padahal justru itu keadaan ketika database paling tidak boleh
        // disentuh lagi.
        $this->assertTrue(LaporanKesalahan::kegagalanDatabase(new PDOException('connection refused')));
        $this->assertTrue(LaporanKesalahan::kegagalanDatabase(new RuntimeException('dibungkus', 0, new PDOException('refused'))));
        $this->assertFalse(LaporanKesalahan::kegagalanDatabase(new RuntimeException('biasa')));
    }

    public function test_bentuk_konsol_tanpa_permintaan(): void
    {
        $laporan = LaporanKesalahan::dari(new RuntimeException('job gagal'), null);

        $teks = $laporan->keTeks();
        $this->assertStringContainsString('konsol', $teks);
        $this->assertStringContainsString('job gagal', $teks);
        $this->assertStringNotContainsString('rute', $teks);

        $atribut = $laporan->keAtribut();
        $this->assertArrayNotHasKey('url.full', $atribut);
        $this->assertArrayNotHasKey('http.request.method', $atribut);
    }

    public function test_kesalahan_4xx_tidak_layak_dilaporkan(): void
    {
        $this->assertFalse(LaporanKesalahan::layakDilaporkan(new NotFoundHttpException));
        $this->assertTrue(LaporanKesalahan::layakDilaporkan(new RuntimeException('nyata')));
    }

    private function kesalahanKueri(): QueryException
    {
        return new QueryException(
            connectionName: 'pgsql',
            sql: 'insert into "aset" ("kode", "nama") values (?, ?)',
            bindings: ['AST-001', 'Mesin A'],
            previous: new class('duplicate key value violates unique constraint "aset_kode_unique"') extends PDOException
            {
                public function __construct(string $pesan)
                {
                    parent::__construct($pesan);
                    $this->errorInfo = ['23505', 7, $pesan];
                }
            },
            connectionDetails: ['driver' => 'pgsql', 'database' => 'core_erp', 'host' => 'core-db', 'port' => 5432],
            readWriteType: 'write',
        );
    }
}
