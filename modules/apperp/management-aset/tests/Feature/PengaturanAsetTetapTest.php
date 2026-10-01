<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Pengaturan aset tetap, padanan Fixed Asset Setup Business Central: satu kartu per tenant.
 */
class PengaturanAsetTetapTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const URL = '/api/modules/management-aset/v1/pengaturan-aset-tetap';

    private const IZIN = ['management-aset.fixed-asset-parameters.read', 'management-aset.fixed-asset-parameters.update'];

    public function test_setup_is_empty_until_first_saved_then_reads_back(): void
    {
        $tenant = $this->buatTenantUji();
        $buku = $this->buku($tenant, 'Buku komersial');

        $this->sebagaiPengguna($tenant, self::IZIN)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.buku_penyusutan_bawaan_id', null);

        $this->sebagaiPengguna($tenant, self::IZIN)->putJson(self::URL, ['buku_penyusutan_bawaan_id' => $buku, 'version' => 0])
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.buku_penyusutan_bawaan.nama', 'Buku komersial');

        $this->sebagaiPengguna($tenant, self::IZIN)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.buku_penyusutan_bawaan_id', $buku);

        // Versi basi ditolak; versi terbaru boleh mengosongkan.
        $this->sebagaiPengguna($tenant, self::IZIN)->putJson(self::URL, ['buku_penyusutan_bawaan_id' => null, 'version' => 0])->assertConflict();
        $this->sebagaiPengguna($tenant, self::IZIN)->putJson(self::URL, ['buku_penyusutan_bawaan_id' => null, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.buku_penyusutan_bawaan_id', null);
        $this->assertSame(1, DB::table('aset_pengaturan_aset_tetap')->where('tenant_id', $tenant)->count());
    }

    public function test_setup_belongs_to_one_tenant(): void
    {
        $tenantA = $this->buatTenantUji();
        $tenantB = $this->buatTenantUji();
        $bukuB = $this->buku($tenantB, 'Buku tenant B');

        // Buku tenant lain tidak dapat dipilih.
        $this->sebagaiPengguna($tenantA, self::IZIN)->putJson(self::URL, ['buku_penyusutan_bawaan_id' => $bukuB, 'version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['buku_penyusutan_bawaan_id']);

        $this->sebagaiPengguna($tenantB, self::IZIN)->putJson(self::URL, ['buku_penyusutan_bawaan_id' => $bukuB, 'version' => 0])->assertOk();

        // Pengaturan tenant B tidak terbaca dari tenant A.
        $this->sebagaiPengguna($tenantA, self::IZIN)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.buku_penyusutan_bawaan_id', null);
    }

    public function test_saving_needs_the_update_permission(): void
    {
        $tenant = $this->buatTenantUji();

        $this->sebagaiPengguna($tenant, ['management-aset.fixed-asset-parameters.read'])
            ->putJson(self::URL, ['buku_penyusutan_bawaan_id' => null, 'version' => 0])
            ->assertForbidden();
    }

    private function buku(string $tenant, string $nama): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'buku-'.$id, 'kode' => 'B'.Str::random(6),
            'nama' => $nama, 'aktif' => true, 'posting_layer' => 'current', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
