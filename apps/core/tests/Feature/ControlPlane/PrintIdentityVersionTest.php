<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Versi baris identitas cetak (K-03, area 3). Teks kop dan daftar logo tinggal di satu baris, dan logo
 * diubah dengan membaca lalu menulis ulang seluruh daftarnya; tanpa versi, dua tab yang sama-sama
 * mengubah logo saling menimpa.
 */
class PrintIdentityVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reporting.disk' => 'reporting-test']);
        Storage::fake('reporting-test');
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant kop',
            'app_ids' => ['app-uji'], 'email' => 'owner@kop.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
    }

    public function test_identitas_membawa_versi_dan_etag_sejak_sebelum_disimpan(): void
    {
        $url = '/api/v1/organizations/'.$this->legalEntity('CV Kop').'/print-identity';

        $this->actingAs($this->owner)->getJson($url)->assertOk()
            ->assertJsonPath('data.version', 0)->assertHeader('ETag', 'W/"0"');

        $versi = $this->putJson($url, ['footer_text' => 'Pertama', 'version' => 0])->assertOk()->json('data.version');
        $this->assertGreaterThan(0, $versi);
        $this->getJson($url)->assertJsonPath('data.version', $versi)->assertHeader('ETag', 'W/"'.$versi.'"');

        // Tab lain yang juga membuka identitas kosong tidak boleh menimpa simpanan pertama.
        $this->putJson($url, ['footer_text' => 'Kedua', 'version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->putJson($url, ['footer_text' => 'Ketiga'])->assertStatus(428)->assertJsonPath('error.code', 'version_required');

        $this->getJson($url)->assertJsonPath('data.footer_text', 'Pertama');
    }

    public function test_dua_tab_yang_mengubah_logo_tidak_saling_menimpa(): void
    {
        $url = '/api/v1/organizations/'.$this->legalEntity('CV Logo').'/print-identity';
        $awal = $this->actingAs($this->owner)
            ->post("{$url}/logos", ['file' => UploadedFile::fake()->image('kiri.png', 60, 60), 'position' => 'kiri', 'version' => 0])
            ->assertCreated()->json('data');
        $logo = $awal['logos'][0]['id'];

        // Tab pertama menambah logo kedua; tab kedua masih memegang versi sebelum logo itu ada.
        $this->post("{$url}/logos", ['file' => UploadedFile::fake()->image('kanan.png', 60, 60), 'position' => 'kanan', 'version' => $awal['version']])
            ->assertCreated()->assertJsonCount(2, 'data.logos');
        $this->patchJson("{$url}/logos/{$logo}", ['width_mm' => 40, 'version' => $awal['version']])
            ->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->deleteJson("{$url}/logos/{$logo}", [], ['If-Match' => 'W/"'.$awal['version'].'"'])->assertStatus(409);
        $this->post("{$url}/logos", ['file' => UploadedFile::fake()->image('tengah.png', 60, 60), 'position' => 'tengah', 'version' => $awal['version']])
            ->assertStatus(409);
        $this->patchJson("{$url}/logos/{$logo}", ['width_mm' => 40])->assertStatus(428);

        $sekarang = $this->getJson($url)->assertJsonCount(2, 'data.logos')->json('data');
        $this->assertSame(['kiri', 'kanan'], array_column($sekarang['logos'], 'position'));

        // Dengan versi terbaru perubahan berhasil, dan jawabannya membawa versi berikutnya.
        $ubah = $this->patchJson("{$url}/logos/{$logo}", ['width_mm' => 40, 'version' => $sekarang['version']])->assertOk()->json('data');
        $this->assertSame(40, $ubah['logos'][0]['width_mm']);
        $this->deleteJson("{$url}/logos/{$logo}", [], ['If-Match' => 'W/"'.$ubah['version'].'"'])
            ->assertOk()->assertJsonCount(1, 'data.logos');
    }

    private function legalEntity(string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'name' => $name,
            'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'organization_id' => $id, 'company_code' => strtoupper(Str::random(4)), 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
