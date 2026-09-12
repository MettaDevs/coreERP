<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Tenant;
use App\Support\Pusat\AlamatLingkungan;
use App\Support\Pusat\LingkunganAktif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alamat menentukan lingkungan, dan alamat yang bukan alamat lingkungan tidak mengubah apa pun.
 *
 * Yang paling penting dijaga di sini bukan jalur yang berhasil melainkan **jalur yang tidak
 * menyala**: seluruh penempatan on-prem, seluruh lingkungan pengembangan, dan seluruh test suite
 * yang sudah ada berjalan tanpa `COREERP_DOMAIN_DASAR`. Kalau middleware ini ikut campur di sana,
 * yang rusak bukan fitur baru melainkan segalanya yang sudah jalan.
 */
class AlamatLingkunganTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Alamat', 'slug' => 'uji-alamat', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Alamat',
            'slug' => 'ujialamat',
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------ membangun dan mengurai

    public function test_produksi_tanpa_label_jenis(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertSame(
            'ivs.contoh.co.id',
            AlamatLingkungan::untuk('ivs', 'ivs', 'production'),
        );
    }

    public function test_selain_produksi_membawa_label_jenisnya(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertSame(
            'ivs--uat.sandbox.contoh.co.id',
            AlamatLingkungan::untuk('ivs', 'uat', 'sandbox'),
        );
    }

    public function test_kedua_sisi_boleh_bertanda_hubung(): void
    {
        // Ini yang membunuh pemisah tanda-hubung-tunggal: SLUG TENANT juga bertanda hubung —
        // `uniqueSlug()` meng-slugify nama badan hukum, jadi "PT Sinar Abadi" menjadi
        // `pt-sinar-abadi`. Dengan satu tanda hubung, alamat ini ambigu dari kedua arah.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $alamat = AlamatLingkungan::dariHost('pt-sinar-abadi--peragaan-penjualan.demo.contoh.co.id');

        $this->assertNotNull($alamat);
        $this->assertSame('pt-sinar-abadi', $alamat->tenant);
        $this->assertSame('peragaan-penjualan', $alamat->lingkungan);
        $this->assertSame('demo', $alamat->jenis);
    }

    public function test_yang_dibangun_dapat_dibaca_kembali(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $host = AlamatLingkungan::untuk('ivs', 'uji-coba', 'sandbox');
        $this->assertNotNull($host);

        $kembali = AlamatLingkungan::dariHost($host);

        $this->assertNotNull($kembali);
        $this->assertSame('ivs', $kembali->tenant);
        $this->assertSame('uji-coba', $kembali->lingkungan);
        $this->assertSame('sandbox', $kembali->jenis);
    }

    public function test_porta_tidak_mengganggu(): void
    {
        // `pelanggan.demo.localhost:8000` adalah bentuk yang dipakai selama pengembangan.
        config(['coreerp.domain_dasar' => 'localhost']);

        $alamat = AlamatLingkungan::dariHost('ivs--uji.demo.localhost:8000');

        $this->assertNotNull($alamat);
        $this->assertSame('ivs', $alamat->tenant);
    }

    // ------------------------------------------------------------------ jalur yang tidak menyala

    public function test_tanpa_domain_dasar_tidak_ada_yang_diurai(): void
    {
        config(['coreerp.domain_dasar' => null]);

        $this->assertNull(AlamatLingkungan::dariHost('apa.pun.contoh.co.id'));
        $this->assertNull(AlamatLingkungan::untuk('ivs', 'ivs', 'production'));
    }

    public function test_alamat_pangkal_bukan_alamat_lingkungan(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertNull(AlamatLingkungan::dariHost('contoh.co.id'));
    }

    public function test_domain_lain_tidak_pernah_dikenali(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertNull(AlamatLingkungan::dariHost('ivs.penyerang.co.id'));
        // Akhiran yang mirip tetapi bukan subdomain. Tanpa titik pemisah, `jahatcontoh.co.id`
        // akan lolos pemeriksaan yang hanya memakai `str_ends_with` atas domainnya saja.
        $this->assertNull(AlamatLingkungan::dariHost('jahatcontoh.co.id'));
    }

    public function test_tiga_label_bukan_bentuk_yang_pernah_kita_cetak(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertNull(AlamatLingkungan::dariHost('a.b.c.contoh.co.id'));
    }

    // ------------------------------------------------------------------ middleware

    public function test_permintaan_biasa_tidak_terpengaruh_sama_sekali(): void
    {
        config(['coreerp.domain_dasar' => null]);

        $this->get('/login')->assertOk();
        $this->assertFalse(app()->bound(LingkunganAktif::KUNCI));
    }

    public function test_alamat_lingkungan_aktif_mengikat_identitasnya(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $lingkungan = $this->lingkungan('demo', 'peragaan', 'active');

        $this->get('http://ujialamat--peragaan.demo.contoh.co.id/login')->assertOk();

        $this->assertTrue(app()->bound(LingkunganAktif::KUNCI));
        $this->assertSame($lingkungan->id, app(LingkunganAktif::KUNCI));
    }

    public function test_lingkungan_yang_belum_aktif_menjawab_404(): void
    {
        // Hanya `active` yang boleh dirutekan. Melayaninya dengan database bawaan adalah cara
        // termurah memperlihatkan data tenant lain.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->lingkungan('demo', 'belumjadi', 'provisioning');

        $this->get('http://ujialamat--belumjadi.demo.contoh.co.id/login')->assertNotFound();
        $this->assertFalse(app()->bound(LingkunganAktif::KUNCI));
    }

    public function test_alamat_yang_tidak_menunjuk_lingkungan_mana_pun_menjawab_404(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        // 404 dan bukan 403: keberadaan sebuah lingkungan adalah informasi, dan 403 memberi tahu
        // penanya bahwa pelanggan itu memang punya demo.
        $this->get('http://ujialamat--tidakada.demo.contoh.co.id/login')->assertNotFound();
    }

    public function test_jenis_yang_salah_pada_alamat_tidak_menemukan_apa_pun(): void
    {
        // Lingkungannya ada, tetapi berjenis demo. Alamat sandbox tidak boleh menemukannya —
        // kalau bisa, label jenis pada domain berhenti berarti apa pun.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->lingkungan('demo', 'peragaan', 'active');

        $this->get('http://ujialamat--peragaan.sandbox.contoh.co.id/login')->assertNotFound();
    }

    public function test_label_tanpa_pemisah_ganda_ditolak(): void
    {
        // Bentuk lama, yang sempat dicetak percobaan pertama. Ia ambigu, jadi ditolak alih-alih
        // ditebak — menebak menghasilkan tenant yang tidak pernah ada, diam-diam.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertNull(AlamatLingkungan::dariHost('pt-sinar-abadi-peragaan.demo.contoh.co.id'));
    }

    public function test_pemisah_ganda_lebih_dari_sekali_ditolak(): void
    {
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->assertNull(AlamatLingkungan::dariHost('a--b--c.demo.contoh.co.id'));
    }

    public function test_alamat_berbentuk_lingkungan_yang_tidak_terurai_menjawab_404(): void
    {
        // Dengan DNS wildcard, setiap label yang pernah diketik siapa pun sampai ke sini.
        // Menyajikan aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari alamat
        // mana saja yang dikarang orang.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->get('http://karangan-orang.demo.contoh.co.id/login')->assertNotFound();
    }

    public function test_label_konsol_tidak_dituntut_menunjuk_lingkungan(): void
    {
        // Konsol operator dan alamat pemasaran hidup di bawah domain yang sama dan berbentuk satu
        // label — persis bentuk alamat produksi. Tanpa pengecualian ini, konsolnya mati dengan 404
        // yang tidak menyebut sebabnya sama sekali.
        config(['coreerp.domain_dasar' => 'contoh.co.id']);

        $this->get('http://admin.contoh.co.id/login')->assertOk();
        $this->get('http://www.contoh.co.id/login')->assertOk();
    }

    private function lingkungan(string $jenis, string $slug, string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $jenis,
            'name' => 'Uji '.$slug,
            'slug' => $slug,
            'database_name' => null,
            'status' => $status,
            'outbound_allowed' => $jenis === 'production',
            'expires_at' => $jenis === 'demo' ? now()->addMonth() : null,
        ]);
    }
}
