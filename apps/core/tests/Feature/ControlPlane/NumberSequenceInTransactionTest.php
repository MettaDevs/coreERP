<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use App\Support\Modules\Contracts\PenerbitNomor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Keuntungan nyata pertama dari satu runtime, dibuktikan bukan dijanjikan.
 *
 * Selama module dan Core berada di dua database, nomor sudah tersimpan di database Core walau
 * dokumennya gagal disimpan di database module. Hasilnya lubang pada urutan yang menurut
 * peraturan tidak boleh berlubang, dan lubang itu harus dijelaskan ke pemeriksa dengan cerita
 * tentang kegagalan jaringan.
 *
 * Setelah satu koneksi, penerbitan nomor berada **di dalam** transaksi dokumen. Dokumen
 * gagal, nomornya ikut batal, tidak ada yang perlu dijelaskan.
 *
 * DatabaseTruncation, bukan RefreshDatabase: test ini membuka dan menutup transaksinya
 * sendiri, dan transaksi pembungkus milik RefreshDatabase akan mengubah arti setiap
 * pengamatannya.
 */
class NumberSequenceInTransactionTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $exceptTables = [
        'country_regions',
        'hierarchy_purposes',
        'number_sequence_profiles',
        'party_types',
    ];

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE clients, apps RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_nomor_ikut_batal_saat_transaksi_dokumen_gagal(): void
    {
        $konteks = $this->sequenceContoh();
        $penerbit = $this->app->make(PenerbitNomor::class);

        $pertama = $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());
        $this->assertSame('000001', $pertama['number']);

        $sebelum = $this->nomorBerikutnya();

        try {
            DB::transaction(function () use ($penerbit, $konteks): void {
                $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());

                // Dokumennya gagal disimpan. Di dunia dua database, nomor di atas sudah
                // terlanjur tersimpan di database Core dan hilang selamanya.
                throw new RuntimeException('Dokumen gagal disimpan.');
            });
        } catch (RuntimeException) {
            // Kegagalan yang memang disengaja.
        }

        $this->assertSame($sebelum, $this->nomorBerikutnya(), 'Nomor berikutnya melompat; berarti nomor yang batal tidak ikut dikembalikan.');
        $this->assertSame(
            1,
            DB::table('number_sequence_issues')->count(),
            'Baris penerbitan dari transaksi yang gagal ikut tersimpan; berarti ia berada di luar transaksi dokumen.'
        );

        $berikutnya = $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());

        $this->assertSame('000002', $berikutnya['number'], 'Urutan berlubang: nomor 000002 hilang karena dokumen yang gagal.');
    }

    public function test_nomor_tetap_terbit_saat_transaksi_dokumen_berhasil(): void
    {
        $konteks = $this->sequenceContoh();
        $penerbit = $this->app->make(PenerbitNomor::class);

        $hasil = DB::transaction(fn (): array => $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid()));

        $this->assertSame('000001', $hasil['number']);
        $this->assertSame(1, DB::table('number_sequence_issues')->count());
    }

    public function test_dua_koneksi_menerbitkan_nomor_berbeda_tanpa_duplikat(): void
    {
        $konteks = $this->sequenceContoh();
        $penerbit = $this->app->make(PenerbitNomor::class);

        // Koneksi kedua berdiri untuk instance API kedua: dua proses, satu PostgreSQL.
        // Itu bentuk penempatan yang direncanakan, jadi ia yang diuji.
        $utama = DB::connection('pgsql_test');
        $kedua = DB::connection('pgsql_test_secondary');

        $utama->beginTransaction();
        $satu = $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());
        $utama->commit();

        $kedua->beginTransaction();
        $dua = $penerbit->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());
        $kedua->commit();

        $this->assertNotSame($satu['number'], $dua['number'], 'Dua koneksi menerbitkan nomor yang sama.');
        $this->assertSame(['000001', '000002'], collect([$satu['number'], $dua['number']])->sort()->values()->all());
    }

    private function nomorBerikutnya(): int
    {
        return (int) DB::table('number_sequence_allocations')->orderBy('created_at')->value('next_number');
    }

    /** @return array{tenant_id: string, app_id: string} */
    private function sequenceContoh(): array
    {
        $tenantId = (string) Str::ulid();
        $appId = 'sample-app';
        $clientId = (string) Str::ulid();

        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Klien Contoh', 'slug' => 'klien-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Tenant Contoh', 'slug' => 'tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);

        $reference = NumberSequenceReference::query()->create([
            'app_id' => $appId,
            'code' => 'sample-app.document',
            'name' => 'Nomor dokumen',
            'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit'],
        ]);

        TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => false,
            'preallocation_quantity' => 1,
            'minimum_number' => 1,
            'segments' => [['type' => 'number', 'length' => 6]],
        ]);

        return ['tenant_id' => $tenantId, 'app_id' => $appId];
    }
}
