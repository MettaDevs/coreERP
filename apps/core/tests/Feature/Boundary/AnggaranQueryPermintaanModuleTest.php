<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Satu permintaan module tidak boleh menghabiskan lebih dari sekian query.
 *
 * Angka ini ada karena pernah tidak ada. Diukur pada permintaan daftar module yang paling
 * sederhana — satu tabel, satu tenant, tanpa relasi — dan hasilnya **22 query, hanya satu di
 * antaranya mengambil data yang diminta**. Sisanya konteks dan izin yang ditanyakan berulang
 * kali oleh pemanggil yang berbeda: `tenant_memberships` empat kali, lingkup kebijakan enam
 * kali, `organizations` lima kali.
 *
 * Penyebabnya bukan pemindahan ke satu runtime. Kelas-kelasnya memang tidak pernah mengingat
 * jawabannya, dan Core sudah begitu jauh sebelum module masuk; yang dilakukan pemindahan hanya
 * membuatnya terlihat, karena rute module melewati seluruh rantai itu sekaligus.
 *
 * Test ini menahan kemundurannya. Ia bukan test kecepatan — jumlah query stabil dan tidak
 * bergantung mesin, tidak seperti milidetik.
 */
class AnggaranQueryPermintaanModuleTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    /**
     * Batas yang dipilih dengan sengaja, bukan angka sekarang ditambah bantalan.
     *
     * Sembilan query yang sekarang berjalan semuanya berbeda dan masing-masing punya alasan:
     * keanggotaan, tenant, lingkup kebijakan, organisasi, akses provider, tiga langkah rantai
     * role → permission, dan satu query data. Batasnya diberi ruang satu query untuk pekerjaan
     * yang wajar bertambah; lebih dari itu berarti ada yang mulai mengulang lagi, dan itu yang
     * harus dilihat orang sebelum ia sampai ke pengguna.
     */
    private const BATAS = 10;

    public function test_permintaan_daftar_module_tidak_melebihi_anggaran_query(): void
    {
        $tenant = $this->buatTenantUji();
        $this->sebagaiPengguna($tenant, ['management-aset.group-aset.read']);

        // Permintaan pemanasan supaya biaya sekali-jalan tidak ikut terhitung.
        $this->getJson('/api/modules/management-aset/v1/group-aset')->assertOk();

        // Batas permintaan ditiru: ikatan `scoped` dibuang, persis seperti yang dilakukan
        // runtime di antara dua permintaan. Tanpa ini, ingatan dari permintaan pemanasan ikut
        // terpakai dan angkanya jadi lebih bagus daripada kenyataan.
        $this->app->forgetScopedInstances();

        $query = [];
        DB::listen(function ($peristiwa) use (&$query): void {
            $query[] = preg_replace('/\s+/', ' ', $peristiwa->sql);
        });

        $this->getJson('/api/modules/management-aset/v1/group-aset')->assertOk();

        $this->assertLessThanOrEqual(self::BATAS, count($query), sprintf(
            "Satu permintaan daftar module memakai %d query, batasnya %d.\n- %s\n".
            'Yang biasanya terjadi: sebuah kelas konteks ditanyai berulang kali dalam satu '.
            'permintaan dan menjawab dengan query baru tiap kali. Perbaikannya mengingat '.
            'jawabannya selama permintaan itu, bukan menaikkan batas ini.',
            count($query),
            self::BATAS,
            implode("\n- ", array_map(static fn (string $sql): string => substr($sql, 0, 90), $query)),
        ));
    }
}
