<?php

declare(strict_types=1);

namespace Modules\PenerbitContoh\ChangeMe\Tests\Feature;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\PenerbitContoh\ChangeMe\Models\Contoh;
use RuntimeException;
use Tests\TestCase;

/**
 * Penyaringan tenant module ini, dibuktikan atas database yang sungguhan.
 *
 * **Test ini adalah test pertama module baru, dan ia sengaja bukan test yang kosong.** Semua
 * tenant sekarang berbagi satu tabel, jadi kebocoran antar tenant tidak pernah gagal dengan
 * sendirinya — ia tampak seperti daftar yang isinya kebetulan banyak. Sebuah `assertTrue(true)`
 * di tempat ini akan hijau selamanya sambil membiarkan persis kegagalan yang paling mahal.
 *
 * Tiga hal dibuktikan, dan ketiganya berbeda jenis:
 *
 * 1. **Baca.** Daftar hanya memulangkan baris tenant aktif.
 * 2. **Tulis ke tenant lain.** Menyimpan baris atas nama tenant lain dibatalkan. Sebuah scope
 *    baca tidak pernah melihat baris yang sedang ditulis, jadi butir 1 tidak pernah menangkap
 *    ini.
 * 3. **Tanpa tenant aktif.** Query dibatalkan, bukan dijalankan tanpa saringan. Ini yang
 *    membedakan penyaringan yang gagal-menutup dari penyaringan yang diam-diam terbuka pada
 *    pekerjaan latar yang lupa menyetel konteks.
 *
 * Baris pembanding disisipkan lewat query builder, bukan lewat model, dan itu disengaja:
 * `MilikTenant` membatalkan penyimpanan baris milik tenant selain tenant aktif — persis yang
 * dibuktikan butir 2. Menuntut penyemaian memakai model berarti membuat test kebocoran antar
 * tenant mustahil ditulis.
 */
class PenyaringanTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_hanya_memulangkan_baris_tenant_aktif(): void
    {
        $tenantIni = $this->tenantUji();
        $tenantLain = $this->tenantUji();

        $this->seedContoh($tenantIni, 'C-001', 'Baris tenant ini');
        $this->seedContoh($tenantLain, 'C-002', 'Baris pelanggan lain');

        $nama = $this->untukTenant($tenantIni, static fn (): array => array_map(
            static fn (Contoh $baris): string => $baris->nama,
            Contoh::query()->orderBy('kode')->get()->all(),
        ));

        $this->assertSame(['Baris tenant ini'], $nama, 'Daftar memuat baris milik tenant lain.');
    }

    public function test_baris_baru_mewarisi_tenant_aktif_tanpa_module_menuliskannya(): void
    {
        $tenantIni = $this->tenantUji();

        $dibuat = $this->untukTenant($tenantIni, static fn (): Contoh => Contoh::query()->create([
            'kode' => 'C-003',
            'nama' => 'Baris tanpa tenant_id tertulis',
        ]));

        $this->assertSame($tenantIni, $dibuat->tenant_id, 'tenant_id tidak diisi dari tenant aktif.');
    }

    public function test_menyimpan_baris_atas_nama_tenant_lain_dibatalkan(): void
    {
        $tenantIni = $this->tenantUji();
        $tenantLain = $this->tenantUji();

        $this->expectException(RuntimeException::class);

        $this->untukTenant($tenantIni, static fn (): Contoh => Contoh::query()->create([
            'tenant_id' => $tenantLain,
            'kode' => 'C-004',
            'nama' => 'Baris titipan',
        ]));
    }

    public function test_query_tanpa_tenant_aktif_dibatalkan_bukan_dibiarkan(): void
    {
        $this->seedContoh($this->tenantUji(), 'C-005', 'Baris siapa pun');

        $this->expectException(RuntimeException::class);

        Contoh::query()->get();
    }

    /**
     * Menjalankan sepotong pekerjaan dengan tenant tertentu sebagai tenant aktif.
     *
     * Di permintaan HTTP tenant diikat middleware `konteks-module`. Test memanggil model
     * secara langsung, jadi tenantnya harus disebut — dan `PelaksanaUntukTenant` satu-satunya
     * cara module boleh menyebutnya.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    private function untukTenant(string $tenantId, callable $aksi): mixed
    {
        return $this->app->make(PelaksanaUntukTenant::class)->jalankanUntuk($tenantId, $aksi);
    }

    /**
     * Id tenant untuk test ini.
     *
     * Tabel module tidak menunjuk tabel tenant Core lewat kunci asing — module tidak
     * menyentuh tabel module maupun tabel Core lain — jadi id yang dibuat di sini sudah cukup
     * untuk membuktikan penyaringannya.
     */
    private function tenantUji(): string
    {
        $this->pastikanTabelModuleAda();

        return (string) Str::ulid();
    }

    /**
     * Tabel module dibuat test ini sendiri bila belum ada.
     *
     * Test tidak memasang module, dan migration module tidak ikut migration Core. Tanpa baris
     * ini setiap test di berkas ini gagal dengan "relation does not exist" — pesan yang tidak
     * menyebut sebabnya sama sekali.
     */
    private function pastikanTabelModuleAda(): void
    {
        if (Schema::hasTable('change_me_m_contoh')) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function seedContoh(string $tenantId, string $kode, string $nama): void
    {
        DB::table('change_me_m_contoh')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'kode' => $kode,
            'nama' => $nama,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
