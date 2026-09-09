<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Kegagalan penerbitan nomor, setelah Core berada di proses yang sama.
 *
 * Berkas ini pernah berisi sepuluh test tentang kegagalan jaringan: kredensial ditolak, batas
 * laju terlampaui, Core tidak terjangkau, dan klasifikasi 4xx serta 5xx menjadi kode kesalahan
 * yang berbeda-beda. Semuanya lahir dari masalah nyata — token service yang tidak sinkron
 * pernah terbaca seolah layanan nomor sedang tumbang.
 *
 * **Tidak satu pun dari kegagalan itu bisa terjadi lagi.** Tidak ada permintaan HTTP, tidak ada
 * token, tidak ada batas laju, dan Core tidak bisa "tidak terjangkau" dari dirinya sendiri.
 * Mempertahankan test-test itu berarti menjaga mekanisme yang sudah tidak ada: ia akan hijau
 * selamanya tanpa membuktikan apa pun, dan orang berikutnya akan percaya penanganan kegagalan
 * masih teruji.
 *
 * Yang menggantikannya dua kegagalan yang **masih mungkin**, dan satu jaminan baru yang dulu
 * mustahil diuji sama sekali.
 */
class NumberSequenceFailureTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    /**
     * Tenant tanpa urutan nomor aktif ditolak, dan penolakannya bukan 503.
     *
     * 503 berarti "layanan belum dapat dihubungi", dan itu tidak pernah lagi benar. Yang terjadi
     * adalah permintaannya sendiri tidak bisa dipenuhi: tenant ini belum punya urutan nomor untuk
     * referensi yang diminta. Status yang jujur untuk itu 422.
     */
    public function test_tenant_tanpa_urutan_nomor_ditolak_dan_tidak_menyimpan_apa_pun(): void
    {
        DB::table('tenant_number_sequences')->where('tenant_id', $this->tenantId)->delete();

        $this->buatGroup()->assertStatus(422);

        $this->assertSame(0, DB::table('aset_m_group_aset')->count(), 'Master tersimpan padahal nomornya gagal terbit.');
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    /**
     * Kegagalan tercatat beserta referensinya, dan tanpa satu pun kredensial.
     *
     * Bagian "tanpa kredensial" dipertahankan dari test lama meski tokennya sendiri sudah tidak
     * ada: aturannya tetap berlaku untuk apa pun yang kelak ikut dicatat, dan aturan yang
     * penjaganya dibuang akan dilanggar pada perubahan berikutnya.
     */
    public function test_kegagalan_tercatat_beserta_referensinya(): void
    {
        DB::table('tenant_number_sequences')->where('tenant_id', $this->tenantId)->delete();

        $tercatat = [];
        Log::listen(function ($pesan) use (&$tercatat): void {
            $tercatat[] = ['message' => $pesan->message, 'context' => $pesan->context];
        });

        $this->buatGroup()->assertStatus(422);

        $baris = collect($tercatat)->firstWhere(fn (array $item): bool => str_contains($item['message'], 'Penerbitan nomor gagal'));

        $this->assertNotNull($baris, 'Kegagalan penerbitan nomor harus tercatat di log.');
        $this->assertSame('management-aset.group-aset', $baris['context']['reference']);
        $this->assertSame($this->tenantId, $baris['context']['tenant_id']);
        $this->assertStringNotContainsString('service-token', json_encode($baris['context'], JSON_THROW_ON_ERROR));
    }

    /**
     * Jaminan yang dulu mustahil diuji: nomor ikut batal ketika dokumennya gagal disimpan.
     *
     * Selama penerbitan berjalan lewat HTTP, ia berada di luar transaksi dokumen — nomor sudah
     * terbit di Core sementara dokumennya batal, dan penghitung melompat tanpa ada dokumen yang
     * memakainya. Lompatan itu yang harus dijelaskan ke pemeriksa.
     *
     * Sekarang keduanya satu transaksi pada koneksi yang sama. Test ini yang membuktikannya, dan
     * ia satu-satunya alasan terkuat seluruh pemindahan ke satu runtime ini ada.
     */
    public function test_nomor_ikut_batal_ketika_penyimpanan_dokumen_gagal(): void
    {
        $this->buatGroup()->assertCreated();

        $sesudahSukses = $this->jumlahNomorTerbit();
        $this->assertSame(1, $sesudahSukses);

        // Kegagalan harus terjadi **setelah** nomor diminta, bukan pada validasi — kalau
        // penyimpanannya ditolak sebelum penerbitan, test ini hijau tanpa membuktikan apa pun.
        //
        // Caranya: satu baris disisipkan lebih dulu dengan kode yang akan diterbitkan
        // berikutnya. Validasi meloloskannya (kode tidak pernah datang dari klien), penerbitan
        // berjalan, lalu penyimpanan ditolak indeks unik — persis urutan yang diperlukan.
        DB::table('aset_m_group_aset')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'creation_key' => 'penghalang-'.Str::ulid(),
            'kode' => $this->awalanNomor('management-aset.group-aset').'-000002',
            'nama' => 'Penghalang',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.create'])
                ->withHeader('Idempotency-Key', 'group-aset:'.Str::ulid())
                ->postJson('/api/modules/management-aset/v1/group-aset', ['nama' => 'Bangunan Kedua']);
        } catch (\Throwable) {
            // Kegagalannya memang yang diharapkan; yang diperiksa akibatnya di bawah.
        }

        $this->assertSame($sesudahSukses, $this->jumlahNomorTerbit(), 'Nomor tetap terbit padahal recordnya batal.');
        // Dua baris: yang berhasil dibuat di awal, dan penghalang yang disisipkan test ini.
        $this->assertSame(2, DB::table('aset_m_group_aset')->count());
    }

    /** @return TestResponse<Response> */
    private function buatGroup(): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.create'])
            ->withHeader('Idempotency-Key', 'group-aset:'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/group-aset', ['nama' => 'Bangunan']);
    }
}
