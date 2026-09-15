<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use Illuminate\Support\Str;

/**
 * Aturan klaim dan penutupan operasi `install` (PS-06).
 *
 * - `install` diserahkan kapan saja, tidak menunggu jendela pembaruan, tetapi hanya bila rilisnya sudah
 *   ditentukan; tanpa rilis ia tetap menunggu dan tidak menghalangi operasi lain.
 * - Hash kata sandi sementara hilang dari parameter di **setiap** jalur yang menutup operasinya.
 */
final class InstallOperationRulesTest extends SiteTestCase
{
    // ------------------------------------------------------------------ klaim

    public function test_an_install_with_a_release_is_handed_out_outside_the_update_window(): void
    {
        $site = $this->enrolledSite(0, ['update_window_start' => '22:00', 'update_window_end' => '04:00']);
        $install = $this->install($site, '1.0.0');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 14:00', 'Asia/Jakarta'));

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $install->id)
            ->assertJsonPath('operation', 'install')
            ->assertJsonPath('parameters.release', '1.0.0')
            ->assertJsonPath('parameters.admin_email', 'dewi@klinik.test');

        $this->assertSame('running', $install->refresh()->status);
    }

    public function test_an_install_without_a_release_waits_and_does_not_hold_up_other_operations(): void
    {
        $site = $this->enrolledSite();
        $install = $this->install($site, null);
        $this->travel(1)->seconds();
        $backup = $this->operation($site, 'backup');

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $backup->id);

        $this->agent('POST', "/api/agent/v1/operations/{$backup->id}/steps", $site, ['status' => 'succeeded', 'step' => 'Selesai'])->assertOk();

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();
        $this->assertSame('requested', $install->refresh()->status);
        $this->assertArrayHasKey('admin_password_hash', $install->parameters);
    }

    /** Rilis berbentuk string kosong sama dengan tanpa rilis — agen tidak punya apa pun untuk diambil. */
    public function test_an_empty_release_is_not_a_release(): void
    {
        $site = $this->enrolledSite();
        $this->install($site, '');

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();
    }

    // ------------------------------------------------------------------ penutupan

    public function test_a_running_step_keeps_the_hash_and_a_succeeded_step_removes_it(): void
    {
        $site = $this->enrolledSite();
        $install = $this->claimedInstall($site);

        $this->agent('POST', "/api/agent/v1/operations/{$install->id}/steps", $site, ['status' => 'running', 'step' => 'Menarik rilis'])->assertOk();
        $this->assertArrayHasKey('admin_password_hash', $install->refresh()->parameters);

        $this->agent('POST', "/api/agent/v1/operations/{$install->id}/steps", $site, ['status' => 'succeeded', 'step' => 'Selesai'])->assertOk();

        $this->assertClosedWithoutHash($install, 'succeeded');
    }

    public function test_a_failed_step_removes_the_hash(): void
    {
        $site = $this->enrolledSite();
        $install = $this->claimedInstall($site);

        $this->agent('POST', "/api/agent/v1/operations/{$install->id}/steps", $site, ['status' => 'failed', 'step' => 'bootstrap-site', 'failure_message' => 'Owner lain.'])->assertOk();

        $this->assertClosedWithoutHash($install, 'failed');
    }

    public function test_an_expired_lease_removes_the_hash(): void
    {
        $site = $this->enrolledSite();
        $install = $this->claimedInstall($site);

        $this->travel(16)->minutes();
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        $this->assertClosedWithoutHash($install, 'failed');
    }

    public function test_a_request_nobody_claimed_expires_without_the_hash(): void
    {
        $site = $this->enrolledSite();
        $install = $this->install($site, null);

        $this->travel(8)->days();
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        $this->assertClosedWithoutHash($install, 'expired');
    }

    public function test_an_operator_cancellation_removes_the_hash(): void
    {
        $site = $this->enrolledSite();
        $install = $this->install($site, '1.0.0');

        $this->actingAs($this->operator())
            ->post("/situs/{$site->id}/operasi/{$install->id}/batal")
            ->assertSessionHasNoErrors();

        $this->assertClosedWithoutHash($install, 'cancelled');
    }

    public function test_revoking_the_site_removes_the_hash_from_its_pending_install(): void
    {
        $site = $this->enrolledSite();
        $install = $this->install($site, '1.0.0');

        $this->actingAs($this->operator())
            ->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name])
            ->assertSessionHasNoErrors();

        $this->assertClosedWithoutHash($install, 'cancelled');
    }

    /** Menutup operasi yang tidak membawa hash tidak mengubah parameternya. */
    public function test_closing_an_upgrade_keeps_its_parameters(): void
    {
        $site = $this->enrolledSite();
        $upgrade = $this->operation($site, 'upgrade', ['edition' => Site::SINGLE_IMAGE_EDITION, 'release' => '1.1.0']);

        $this->actingAs($this->operator())->post("/situs/{$site->id}/operasi/{$upgrade->id}/batal")->assertSessionHasNoErrors();

        $upgrade->refresh();
        $this->assertSame('cancelled', $upgrade->status);
        $this->assertSame(['edition' => Site::SINGLE_IMAGE_EDITION, 'release' => '1.1.0'], $upgrade->parameters);
    }

    // ------------------------------------------------------------------ perkakas

    private function install(Site $site, ?string $release): SiteOperation
    {
        return $this->operation($site, 'install', [
            'edition' => Site::SINGLE_IMAGE_EDITION,
            'release' => $release,
            'tenant_id' => $site->tenant_id,
            'tenant_name' => 'PT Klinik Uji',
            'app_ids' => ['human-resources'],
            'admin_name' => 'Dewi Pemilik',
            'admin_email' => 'dewi@klinik.test',
            'admin_password_hash' => '$2y$04$'.Str::random(53),
        ]);
    }

    private function claimedInstall(Site $site): SiteOperation
    {
        $install = $this->install($site, '1.0.0');
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertOk()->assertJsonPath('id', $install->id);

        return $install->refresh();
    }

    /** @param  array<string, mixed>  $parameters */
    private function operation(Site $site, string $operation, array $parameters = []): SiteOperation
    {
        return SiteOperation::query()->create([
            'site_id' => $site->id,
            'operation' => $operation,
            'parameters' => $parameters,
            'status' => 'requested',
            'requested_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function assertClosedWithoutHash(SiteOperation $operation, string $status): void
    {
        $operation->refresh();

        $this->assertSame($status, $operation->status);
        $this->assertNotNull($operation->finished_at);
        $this->assertArrayNotHasKey('admin_password_hash', $operation->parameters);
        // Sisanya tetap: riwayat masih menyebut rilis dan owner yang dipasang.
        $this->assertSame('dewi@klinik.test', $operation->parameters['admin_email']);
    }
}
