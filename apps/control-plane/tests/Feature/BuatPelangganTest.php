<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\User;
use ControlPlane\Tests\SkemaCore;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PermintaanKeluar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * Operator benar-benar dapat melahirkan pelanggan, dan setiap cara gagalnya benar-benar terbaca.
 *
 * Seluruh test di sini memakai `Http::fake()`. Itu bukan kemudahan: yang diuji adalah **perilaku
 * konsol ketika Core menjawab begini atau begitu**, dan satu-satunya cara menguji "Core tidak dapat
 * dihubungi" tanpa mematikan Core sungguhan adalah memalsukan sisi jaringannya. Yang tidak dijaga
 * suite ini karena itu juga jelas, dan dicatat apa adanya: bahwa Core sungguhan benar-benar
 * menjawab dengan bentuk yang dipalsukan di sini adalah janji kontrak, bukan sesuatu yang berkas
 * ini buktikan.
 *
 * `Http::preventStrayRequests()` berdiri di setiap test justru karena itu — supaya satu panggilan
 * yang lolos dari palsuan menjadi kegagalan yang berisik, bukan permintaan sungguhan yang
 * diam-diam keluar dari mesin yang menjalankan test.
 */
class BuatPelangganTest extends TestCase
{
    use SkemaCore;

    private const ALAMAT = 'http://core.uji:8000';

    private const TUJUAN = self::ALAMAT.'/api/internal/v1/tenants';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.alamat' => self::ALAMAT, 'core.token' => 'kunci-uji']);

        Http::preventStrayRequests();
    }

    private function operator(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator Uji',
            'email' => 'operator@contoh.test',
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('provider_access')->insert([
            'user_id' => $id,
            'role' => 'provider_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function pelangganBiasa(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator',
            'email' => Str::lower(Str::random(8)).'@contoh.test',
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function isian(): array
    {
        return [
            'nama_badan_hukum' => 'PT Sumber Sehat Nusantara',
            'nama_admin' => 'Siti Rahmawati',
            'email_admin' => 'siti@sumbersehat.test',
            'app_ids' => ['hr'],
        ];
    }

    private function galat(string $kunci): string
    {
        $kantong = session('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $kantong);

        return $kantong->first($kunci);
    }

    private function tenant(string $nama): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $nama,
            'slug' => Str::slug($nama).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $nama,
            'slug' => Str::slug($nama).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    public function test_kata_sandi_sementara_benar_benar_sampai_ke_layar(): void
    {
        // Pasangan hijau dari seluruh jalur merah di bawahnya, dan alasan layar ini ada sama
        // sekali: tanpa kata sandi yang terbaca, admin pertama tidak punya cara masuk.
        Http::fake(['*' => Http::response([
            'tenant_id' => '01JTENANTUJI0000000000000',
            'environment_id' => '01JENVUJI00000000000000',
            'email' => 'siti@sumbersehat.test',
            'kata_sandi_sementara' => 'Gerbang-Sore-4417',
        ], 201)]);

        $this->actingAs($this->operator());

        $this->post('/pelanggan', $this->isian())->assertRedirect('/pelanggan');

        Http::assertSent(fn (PermintaanKeluar $permintaan): bool => $permintaan->url() === self::TUJUAN
            && $permintaan->hasHeader('Authorization', 'Bearer kunci-uji')
            && $permintaan['nama_badan_hukum'] === 'PT Sumber Sehat Nusantara'
            && $permintaan['nama_admin'] === 'Siti Rahmawati'
            && $permintaan['email_admin'] === 'siti@sumbersehat.test'
            && $permintaan['app_ids'] === ['hr']);

        $this->get('/pelanggan')
            ->assertOk()
            ->assertSee('Gerbang-Sore-4417')
            ->assertSee('siti@sumbersehat.test');
    }

    public function test_kata_sandi_sementara_tidak_muncul_pada_pemuatan_berikutnya(): void
    {
        Http::fake(['*' => Http::response([
            'tenant_id' => '01JTENANTUJI0000000000000',
            'email' => 'siti@sumbersehat.test',
            'kata_sandi_sementara' => 'Gerbang-Sore-4417',
        ], 201)]);

        $this->actingAs($this->operator());
        $this->post('/pelanggan', $this->isian())->assertRedirect('/pelanggan');

        $this->get('/pelanggan')->assertSee('Gerbang-Sore-4417');

        // Sekali, dan benar-benar sekali. Kata sandi yang masih terbaca besok adalah kata sandi
        // yang tersimpan di suatu tempat — dan tempat itu akan ditemukan orang lain.
        $this->get('/pelanggan')->assertOk()->assertDontSee('Gerbang-Sore-4417');
    }

    public function test_penolakan_core_menjadi_galat_pada_isian_yang_ditunjuknya(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Data yang diberikan tidak sah.',
            'errors' => ['email_admin' => ['Email ini sudah terdaftar.']],
        ], 422)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->isian())
            // Galat formulir, bukan halaman 500. Keduanya berarti "ditolak", tetapi hanya yang
            // pertama yang menyebutkan apa yang harus diperbaiki — dan di isian mana.
            ->assertSessionHasErrors(['email_admin' => 'Email ini sudah terdaftar.']);
    }

    public function test_app_yang_tidak_tersedia_ditolak_oleh_core_bukan_oleh_konsol(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Data yang diberikan tidak sah.',
            'errors' => ['app_ids' => ['App "hr" tidak tersedia di edisi ini.']],
        ], 422)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->isian())
            ->assertSessionHasErrors(['app_ids' => 'App "hr" tidak tersedia di edisi ini.']);

        // Konsol tetap mengirimkannya. Menyaring lebih dulu di sini berarti dua daftar app yang
        // harus tetap sama, dan yang kedua akan basi lebih dulu.
        Http::assertSentCount(1);
    }

    public function test_kunci_yang_salah_menyebut_setelan_yang_harus_diperbaiki(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->isian())
            ->assertSessionHasErrors('core');

        // "Unauthenticated." adalah jawaban bawaan Laravel, dan ia tidak memberi tahu siapa pun
        // bahwa yang harus disunting adalah setelan di konsol ini.
        $pesan = $this->galat('core');
        $this->assertStringContainsString('CONTROL_PLANE_TOKEN', $pesan);
        $this->assertStringContainsString(self::TUJUAN, $pesan);
    }

    public function test_core_yang_tidak_terjangkau_menyebut_alamat_yang_dicoba(): void
    {
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 6: Could not resolve host: core.uji',
        ));

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->isian())
            ->assertSessionHasErrors('core');

        $pesan = $this->galat('core');

        // Sebab paling sering adalah COREERP_URL salah setel, dan ia hanya dapat terlihat kalau
        // alamat yang dicoba ikut tertulis. Galat aslinya juga tidak ditelan.
        $this->assertStringContainsString(self::TUJUAN, $pesan);
        $this->assertStringContainsString('COREERP_URL', $pesan);
        $this->assertStringContainsString('Could not resolve host', $pesan);
    }

    public function test_jawaban_tanpa_kata_sandi_tidak_lolos_diam_diam(): void
    {
        // 2xx yang bentuknya tidak sesuai kontrak adalah keadaan paling berbahaya di alur ini:
        // pelanggannya mungkin sudah lahir, dan satu-satunya salinan kata sandinya baru hilang.
        Http::fake(['*' => Http::response(['tenant_id' => '01JTENANTUJI0000000000000'], 201)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->isian())
            ->assertSessionHasErrors('core');

        $pesan = $this->galat('core');
        $this->assertStringContainsString(self::TUJUAN, $pesan);
        $this->assertStringContainsString('periksa daftar pelanggan', $pesan);
    }

    public function test_isian_kosong_ditolak_sebelum_core_dipanggil(): void
    {
        Http::fake();

        $this->actingAs($this->operator())
            ->post('/pelanggan', [
                'nama_badan_hukum' => '',
                'nama_admin' => '',
                'email_admin' => 'bukan-email',
                'app_ids' => [],
            ])
            ->assertSessionHasErrors(['nama_badan_hukum', 'nama_admin', 'email_admin', 'app_ids']);

        Http::assertNothingSent();
    }

    public function test_bukan_operator_tidak_melihat_apa_pun(): void
    {
        Http::fake();

        // 404, bukan 403. Pengguna biasa tidak perlu tahu alamat ini ada.
        $this->actingAs($this->pelangganBiasa())->get('/pelanggan')->assertNotFound();

        $this->actingAs($this->pelangganBiasa())
            ->post('/pelanggan', $this->isian())
            ->assertNotFound();

        // Penjaga yang menolak halamannya tetapi membiarkan panggilannya keluar adalah penjaga
        // yang tidak menjaga apa pun.
        Http::assertNothingSent();
    }

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/pelanggan')->assertRedirect('/login');
    }

    public function test_daftar_menghitung_lingkungan_yang_masih_hidup(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant('PT Punya Dua');

        foreach (['Uji A', 'Uji B', 'Sudah Dibuang'] as $nama) {
            Lingkungan::query()->create([
                'tenant_id' => $tenant,
                'kind' => 'sandbox',
                'name' => $nama,
                'slug' => Str::slug($nama),
                'status' => 'provisioning',
                'outbound_allowed' => false,
                'created_by' => $operator->id,
            ]);
        }

        // Ketiganya sekaligus, karena skema Core menolak dihapus setengah-setengah: dua CHECK
        // constraint mengikat `deleted_at`, `purge_after`, dan `status` menjadi satu keadaan.
        DB::table('environments')
            ->where('tenant_id', $tenant)
            ->where('name', 'Sudah Dibuang')
            ->update([
                'status' => 'soft_deleted',
                'deleted_at' => now(),
                'purge_after' => now()->addDays(30),
            ]);

        $this->actingAs($operator)->get('/pelanggan')
            ->assertOk()
            ->assertSee('PT Punya Dua')
            // Angkanya harus sama dengan yang terbaca di layar Lingkungan, yang juga menyaring
            // yang sudah dihapus lunak. Dua angka yang menyebut hal sama dengan nilai berbeda
            // membuat yang membacanya berhenti mempercayai keduanya.
            ->assertSee('"lingkungan":2', false);
    }

    public function test_hanya_app_tersedia_yang_ditawarkan(): void
    {
        DB::table('apps')->insert([
            [
                'id' => 'hr',
                'name' => 'Kepegawaian',
                'version' => '1.0.0',
                'status' => 'available',
                'database_name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'belum-jadi',
                'name' => 'Rencana Produksi',
                'version' => '0.1.0',
                'status' => 'draft',
                'database_name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Menawarkan app yang belum tersedia berarti membiarkan operator menjanjikannya kepada
        // pelanggan di telepon, lalu Core menolaknya beberapa detik kemudian.
        $this->actingAs($this->operator())->get('/pelanggan')
            ->assertOk()
            ->assertSee('Kepegawaian')
            ->assertDontSee('Rencana Produksi');
    }
}
