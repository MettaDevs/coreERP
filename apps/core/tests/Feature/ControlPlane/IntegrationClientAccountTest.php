<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\IntegrationClient;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\ProviderAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Akun aplikasi klien integrasi (README analisa gap BC, Gap 1 dan 6, opsi A): lahir bersama kliennya,
 * bernama sama, bertahan setelah klien dicabut, dan tidak pernah dapat masuk lewat jalur mana pun.
 */
final class IntegrationClientAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);

        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner PT Metta', 'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'], 'email' => 'owner@metta.test', 'password' => 'password',
        ]);
    }

    public function test_klien_baru_mendapat_akun_aplikasi_yang_ikut_berganti_nama_dan_bertahan_setelah_dicabut(): void
    {
        $id = (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', $this->bentuk('Old-finance'))
            ->assertCreated()->json('data.id');
        $account = User::query()->findOrFail(IntegrationClient::query()->findOrFail($id)->user_id);

        $this->assertSame([User::APPLICATION, 'Old-finance'], [$account->account_type, $account->name]);
        $this->assertStringEndsWith('@application.invalid', $account->email);
        $this->assertNull($account->activeMembership());

        $this->actingAs($this->owner)->patchJson("/api/v1/integration-clients/{$id}", [...$this->bentuk('Finance baru'), 'version' => IntegrationClient::query()->findOrFail($id)->version])->assertOk();
        $this->assertSame('Finance baru', $account->fresh()?->name);

        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$id}/revoke", ['version' => IntegrationClient::query()->findOrFail($id)->version])->assertOk();
        $this->assertSame($account->id, IntegrationClient::query()->findOrFail($id)->user_id);
        $this->assertNotNull($account->fresh());
    }

    public function test_akun_aplikasi_tidak_dapat_masuk_memulihkan_sesi_atau_meminta_reset_sandi(): void
    {
        $account = $this->akunAplikasi();
        $account->forceFill(['password' => 'password'])->save();

        $this->post(route('login.store'), ['email' => $account->email, 'password' => 'password']);
        $this->assertGuest();

        // Sesi yang memegang id akun aplikasi tidak dipulihkan menjadi pengguna.
        $this->withSession([Auth::guard('web')->getName() => $account->id])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        Notification::fake();
        $this->post(route('password.email'), ['email' => $account->email]);
        Notification::assertNothingSent();

        // Kata sandi yang sama pada akun orang dapat masuk: yang ditolak jenis akunnya, bukan kata sandinya.
        $account->forceFill(['account_type' => User::PERSON])->save();
        $this->post(route('login.store'), ['email' => $account->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($account);
    }

    public function test_pemantau_identitas_tidak_menampilkan_akun_aplikasi(): void
    {
        config()->set('coreerp.provider.password', 'LocalProviderPassword!123');
        $this->seed(ProviderAdminSeeder::class);
        $provider = User::query()->where('email', 'provider@coreerp.local')->firstOrFail();
        $account = $this->akunAplikasi();

        $emails = collect($this->actingAs($provider)->getJson('/api/v1/control/identities')->assertOk()->json('data'))->pluck('email');

        $this->assertContains($this->owner->email, $emails);
        $this->assertNotContains($account->email, $emails);
    }

    private function akunAplikasi(): User
    {
        $id = (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', $this->bentuk('Old-finance'))
            ->assertCreated()->json('data.id');
        // Permintaan berikutnya dimulai sebagai tamu.
        Auth::forgetGuards();

        return User::query()->findOrFail(IntegrationClient::query()->findOrFail($id)->user_id);
    }

    /** @return array<string, mixed> */
    private function bentuk(string $name): array
    {
        return [
            'name' => $name, 'delivery_mode' => 'pull', 'push_url' => null,
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => ['asset.'], 'allowed_ips' => [],
        ];
    }
}
