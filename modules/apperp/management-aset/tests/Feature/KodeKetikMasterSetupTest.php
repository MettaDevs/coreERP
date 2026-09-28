<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Kode group aset dan buku penyusutan diketik, bukan diterbitkan urutan nomor (feed posting
 * finance, K-24 dan TODO 8.7).
 *
 * Kode keduanya ikut terkirim ke aplikasi finance dan tertanam di tabel penerjemah pembacanya,
 * jadi harus terbaca manusia, tidak berubah sesudah disimpan, dan tidak pernah dipakai ulang.
 */
class KodeKetikMasterSetupTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    /** @return array<string, array{string, string}> */
    public static function masterBerkodeKetik(): array
    {
        return [
            'group aset' => ['group-aset', 'aset_m_group_aset'],
            'buku penyusutan' => ['buku-penyusutan', 'aset_m_buku_penyusutan'],
        ];
    }

    #[DataProvider('masterBerkodeKetik')]
    public function test_kode_diketik_dirapikan_ke_huruf_besar_dan_tidak_menerbitkan_nomor(string $resource, string $tabel): void
    {
        $this->buat($resource, ['kode' => ' kendaraan ', 'nama' => 'Kendaraan'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'KENDARAAN');

        $this->assertDatabaseHas($tabel, ['tenant_id' => $this->tenantId, 'kode' => 'KENDARAAN']);
        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Kode ketik tidak boleh menghabiskan nomor urut.');
    }

    #[DataProvider('masterBerkodeKetik')]
    public function test_kode_wajib_dan_bentuknya_diperiksa(string $resource, string $tabel): void
    {
        foreach ([[], ['kode' => ''], ['kode' => 'ALAT MEDIS'], ['kode' => 'KEND_ARAAN'], ['kode' => '-KEND'], ['kode' => str_repeat('A', 31)], ['kode' => 12]] as $kiriman) {
            $this->buat($resource, [...$kiriman, 'nama' => 'Kendaraan'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('kode');
        }

        $this->buat($resource, ['kode' => 'ALAT-MEDIS-2', 'nama' => 'Alat medis'])->assertCreated();
        $this->assertSame(1, DB::table($tabel)->where('tenant_id', $this->tenantId)->count());
    }

    #[DataProvider('masterBerkodeKetik')]
    public function test_kode_yang_pernah_dipakai_tidak_dipakai_ulang_walau_diarsipkan(string $resource, string $tabel): void
    {
        $id = (string) $this->buat($resource, ['kode' => 'KENDARAAN', 'nama' => 'Kendaraan'])->assertCreated()->json('data.id');
        $this->sebagaiPengguna($this->tenantId, $this->izin($resource))
            ->deleteJson('/api/modules/management-aset/v1/'.$resource.'/'.$id)
            ->assertNoContent();

        $this->buat($resource, ['kode' => 'kendaraan', 'nama' => 'Kendaraan baru'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kode' => 'sudah dipakai']);

        // Tenant lain punya ruang kodenya sendiri.
        $tenantLain = $this->buatTenantUji();
        $this->sebagaiPengguna($tenantLain, $this->izin($resource))
            ->withHeader('Idempotency-Key', $resource.':'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, ['kode' => 'KENDARAAN', 'nama' => 'Kendaraan'])
            ->assertCreated();
    }

    #[DataProvider('masterBerkodeKetik')]
    public function test_kode_tidak_berubah_sesudah_disimpan(string $resource, string $tabel): void
    {
        $id = (string) $this->buat($resource, ['kode' => 'KENDARAAN', 'nama' => 'Kendaraan'])->json('data.id');

        $this->sebagaiPengguna($this->tenantId, $this->izin($resource))
            ->patchJson('/api/modules/management-aset/v1/'.$resource.'/'.$id, ['kode' => 'MOBIL', 'nama' => 'Kendaraan dinas'])
            ->assertOk()
            ->assertJsonPath('data.kode', 'KENDARAAN')
            ->assertJsonPath('data.nama', 'Kendaraan dinas');
    }

    #[DataProvider('masterBerkodeKetik')]
    public function test_kiriman_ulang_dengan_kunci_sama_tidak_ditolak_sebagai_kode_ganda(string $resource, string $tabel): void
    {
        $kunci = $resource.':'.Str::ulid();
        $pertama = $this->buat($resource, ['kode' => 'KENDARAAN', 'nama' => 'Kendaraan'], $kunci)->assertCreated();
        $ulang = $this->buat($resource, ['kode' => 'KENDARAAN', 'nama' => 'Kendaraan'], $kunci);

        $this->assertContains($ulang->status(), [200, 201]);
        $this->assertSame($pertama->json('data.id'), $ulang->json('data.id'));
        $this->assertSame(1, DB::table($tabel)->where('tenant_id', $this->tenantId)->count());
    }

    public function test_buku_starter_mendapat_kode_ketik_dan_master_lain_tetap_bernomor(): void
    {
        $this->app->make(PelaksanaUntukTenant::class)->jalankanUntuk(
            $this->tenantId,
            fn (): array => $this->app->make(ProvisionIndonesiaStarterData::class)->forTenant($this->tenantId),
        );

        $this->assertSame(
            ['FISKAL', 'KOMERSIAL'],
            DB::table('aset_m_buku_penyusutan')->where('tenant_id', $this->tenantId)->orderBy('kode')->pluck('kode')->all(),
        );
        // Profil penyusutan bukan master berkode ketik: tetap memakai urutan nomornya.
        $this->assertStringStartsWith(
            $this->awalanNomor('management-aset.profil-penyusutan').'-',
            (string) DB::table('aset_m_profil_penyusutan')->where('tenant_id', $this->tenantId)->value('kode'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function buat(string $resource, array $payload, ?string $kunci = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->izin($resource))
            ->withHeader('Idempotency-Key', $kunci ?? $resource.':'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload);
    }

    /** @return list<string> */
    private function izin(string $resource): array
    {
        return array_map(
            static fn (string $aksi): string => 'management-aset.'.$resource.'.'.$aksi,
            ['read', 'create', 'update', 'archive'],
        );
    }
}
