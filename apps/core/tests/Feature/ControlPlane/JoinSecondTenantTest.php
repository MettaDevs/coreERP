<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Satu orang boleh berada di banyak tenant — konsultan, akuntan, dan operator vendor.
 *
 * ## Kenapa ini bukan sekadar mencabut satu pemeriksaan
 *
 * Bentuk yang paling jelas adalah membuang penolakan "email sudah punya akun" pada penukaran kode.
 * Itu membuka **pengambilalihan akun**: kode undangan dirancang untuk dibagikan, jadi siapa pun
 * yang memegangnya dapat mengetik email orang lain beserta kata sandi pilihannya sendiri, dan
 * keluar sebagai pemilik akun itu.
 *
 * Karena itu penolakannya tetap berdiri untuk orang baru, dan yang ditambahkan adalah jalur bagi
 * orang yang **sudah membuktikan dirinya dengan masuk**. Test di bawah menjaga keduanya sekaligus:
 * kemampuan barunya, dan lubang yang tidak boleh ikut terbuka bersamanya.
 */
class JoinSecondTenantTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerSatu;

    private User $ownerDua;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);

        $this->ownerSatu = app(RegisterBusiness::class)->handle([
            'name' => 'Owner Satu',
            'business_name' => 'PT Satu',
            'app_ids' => ['app-uji'],
            'email' => 'owner-satu@contoh.test',
            'password' => 'password',
        ]);

        $this->ownerDua = app(RegisterBusiness::class)->handle([
            'name' => 'Owner Dua',
            'business_name' => 'PT Dua',
            'app_ids' => ['app-uji'],
            'email' => 'owner-dua@contoh.test',
            'password' => 'password',
        ]);
    }

    /**
     * Kemampuan barunya: akun yang sudah ada menukar kode sambil masuk, dan memperoleh tempat
     * kerja kedua tanpa akun kedua.
     */
    public function test_an_existing_account_can_join_a_second_tenant_while_signed_in(): void
    {
        $code = $this->invitationFrom($this->ownerDua);

        $this->actingAs($this->ownerSatu)
            ->postJson('/api/v1/invitation-redemptions', ['code' => $code])
            ->assertCreated();

        $this->assertSame(
            2,
            TenantMembership::query()->where('user_id', $this->ownerSatu->id)->where('status', 'active')->count(),
            'Akun yang sama seharusnya memegang keanggotaan di kedua tenant.'
        );

        // Dan tidak melahirkan akun kedua. Itu justru gejala yang ingin dihindari: satu orang
        // dengan dua akun berarti dua kata sandi, dua passkey, dan dua tempat untuk dicabut ketika
        // ia keluar dari salah satu perusahaan.
        $this->assertSame(1, User::query()->where('email', 'owner-satu@contoh.test')->count());
    }

    /**
     * Pasangan merahnya, dan ia yang paling penting di berkas ini.
     *
     * Tanpa ini, "orang yang sudah punya akun boleh menukar kode" dapat dipenuhi jalur yang juga
     * menerima kata sandi baru — dan itu bukan fitur melainkan pengambilalihan akun yang lulus
     * test.
     */
    public function test_redeeming_while_signed_in_never_touches_the_account_itself(): void
    {
        $code = $this->invitationFrom($this->ownerDua);

        $this->actingAs($this->ownerSatu)
            ->postJson('/api/v1/invitation-redemptions', [
                'code' => $code,
                // Dikirim dengan sengaja. Kalau ia dibaca, ketiga assertion di bawah jatuh.
                'name' => 'Nama Rampasan',
                'email' => 'perampas@contoh.test',
                'password' => 'kata-sandi-penyerang',
                'password_confirmation' => 'kata-sandi-penyerang',
            ])
            ->assertCreated();

        $segar = $this->ownerSatu->fresh();

        $this->assertSame('Owner Satu', $segar->name);
        $this->assertSame('owner-satu@contoh.test', $segar->email);
        $this->assertTrue(
            Hash::check('password', $segar->password),
            'Kata sandi akun berubah lewat penukaran kode undangan. Kode undangan dibagikan, jadi '
            .'ia tidak pernah boleh menjadi cara mengganti kredensial.'
        );
    }

    /**
     * Penjaga yang tidak boleh ikut tercabut: tamu dengan email yang sudah terdaftar tetap ditolak.
     *
     * Inilah yang menutup pengambilalihan akun. Pesannya sekarang menyebut jalan keluarnya — yang
     * lama berhenti di "sudah punya akun", dan orang yang membacanya menyimpulkan undangannya
     * tidak berlaku untuk dirinya.
     */
    public function test_a_guest_using_an_already_registered_email_is_still_refused(): void
    {
        $code = $this->invitationFrom($this->ownerDua);

        // Benar-benar keluar dulu. `actingAs` di dalam `invitationFrom()` menempel pada permintaan
        // berikutnya, dan tanpa baris ini "jalur tamu" diam-diam diuji sebagai jalur orang yang
        // sudah masuk — test hijau yang membuktikan hal yang berbeda dari judulnya.
        auth()->logout();

        $response = $this->postJson('/api/v1/invitation-redemptions', [
            'code' => $code,
            'name' => 'Perampas',
            'email' => 'owner-satu@contoh.test',
            'password' => 'kata-sandi-penyerang',
            'password_confirmation' => 'kata-sandi-penyerang',
        ])->assertUnprocessable();

        $this->assertStringContainsString('Masuk', (string) $response->json('errors.email.0'));

        $this->assertTrue(
            Hash::check('password', $this->ownerSatu->fresh()->password),
            'Kata sandi akun yang sudah ada tersentuh oleh percobaan penukaran dari tamu.'
        );
    }

    public function test_joining_a_tenant_you_already_belong_to_is_refused_without_duplicating_anything(): void
    {
        $code = $this->invitationFrom($this->ownerDua);

        $this->actingAs($this->ownerDua)
            ->postJson('/api/v1/invitation-redemptions', ['code' => $code])
            ->assertUnprocessable();

        $this->assertSame(
            1,
            TenantMembership::query()->where('user_id', $this->ownerDua->id)->count(),
        );
    }

    /**
     * Orang yang pernah dicabut aksesnya lalu diundang lagi masuk kembali.
     *
     * Kunci unik (tenant, user) menolak baris kedua, jadi tanpa jalur ini undangan ulang akan
     * gagal dengan galat database — dan mengundang ulang orang yang pernah keluar adalah alasan
     * undangan itu ada.
     */
    public function test_a_revoked_membership_is_brought_back_rather_than_duplicated(): void
    {
        $code = $this->invitationFrom($this->ownerDua);

        $this->actingAs($this->ownerSatu)
            ->postJson('/api/v1/invitation-redemptions', ['code' => $code])
            ->assertCreated();

        $keanggotaan = TenantMembership::query()
            ->where('user_id', $this->ownerSatu->id)
            ->where('tenant_id', '!=', $this->ownerSatu->memberships()->first()?->tenant_id)
            ->firstOrFail();
        $keanggotaan->update(['status' => 'revoked']);

        $this->actingAs($this->ownerSatu)
            ->postJson('/api/v1/invitation-redemptions', ['code' => $code])
            ->assertCreated();

        $this->assertSame('active', $keanggotaan->fresh()->status);
        $this->assertSame(2, TenantMembership::query()->where('user_id', $this->ownerSatu->id)->count());
    }

    private function invitationFrom(User $owner): string
    {
        return $this->actingAs($owner)
            ->postJson('/api/v1/invitation-codes', ['system_role' => 'user', 'assignments' => []])
            ->assertCreated()
            ->json('data.code');
    }
}
