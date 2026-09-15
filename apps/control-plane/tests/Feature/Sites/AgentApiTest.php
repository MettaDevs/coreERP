<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Models\SiteReport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * API agen situs, dari pendaftaran sampai langkah operasi — dan setiap cara ia harus menolak.
 *
 * Kontraknya `contracts/openapi-agent.yaml`. Yang dibuktikan di sini sisi admin.erp; agen bash
 * membuktikan sisinya sendiri terhadap server tiruan yang memeriksa tanda tangan dengan aturan yang
 * sama.
 */
class AgentApiTest extends SiteTestCase
{
    // ------------------------------------------------------------------ pendaftaran

    public function test_a_valid_token_enrolls_the_site_once(): void
    {
        $site = $this->site();
        $token = $this->enrollmentToken($site);
        $body = ['token' => $token, 'public_key' => $this->rsaKey()['public'], 'agent_version' => '0.1.0'];

        $this->postJson('/api/agent/v1/enroll', $body)
            ->assertCreated()
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('tenant_id', $site->tenant_id)
            ->assertJsonPath('tenant_name', 'PT Klinik Uji')
            ->assertJsonPath('interval_seconds', 60);

        $this->assertSame(trim($this->rsaKey()['public']), trim((string) $site->refresh()->public_key));
        $this->assertNotNull($site->enrolled_at);

        // Sekali pakai.
        $this->postJson('/api/agent/v1/enroll', $body)->assertUnauthorized()->assertJsonPath('error', 'enrollment_rejected');
    }

    public function test_expired_and_revoked_tokens_are_refused_with_the_same_answer(): void
    {
        $key = $this->rsaKey()['public'];

        $expired = $this->site(['name' => 'Kedaluwarsa']);
        $revoked = $this->site(['name' => 'Dicabut', 'revoked_at' => now()]);

        foreach ([
            $this->enrollmentToken($expired, now()->subMinute()),
            $this->enrollmentToken($revoked),
            str_repeat('x', 48),
        ] as $token) {
            $this->postJson('/api/agent/v1/enroll', ['token' => $token, 'public_key' => $key, 'agent_version' => '0.1.0'])
                ->assertUnauthorized()
                ->assertExactJson(['error' => 'enrollment_rejected']);
        }

        $this->assertSame(0, SiteEnrollmentToken::query()->whereNotNull('used_at')->count());
    }

    public function test_a_weak_or_malformed_public_key_is_refused_without_spending_the_token(): void
    {
        $site = $this->site();
        $token = $this->enrollmentToken($site);

        foreach ([$this->rsaKey(0, 1024)['public'], 'bukan kunci'] as $key) {
            $this->postJson('/api/agent/v1/enroll', ['token' => $token, 'public_key' => $key, 'agent_version' => '0.1.0'])
                ->assertStatus(422);
        }

        $this->assertNull(SiteEnrollmentToken::query()->sole()->used_at);
        $this->assertNull($site->refresh()->public_key);
    }

    // ------------------------------------------------------------------ tanda tangan

    public function test_a_signed_report_is_recorded(): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        $site->refresh();
        $this->assertNotNull($site->last_seen_at);
        $this->assertSame('0.1.0', $site->reported_release);
        $this->assertSame(1, SiteReport::query()->count());
    }

    /**
     * Setiap cara tanda tangan dapat salah, masing-masing ditolak dengan jawaban yang sama.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function tamperings(): iterable
    {
        yield 'kunci lain' => [['key_slot' => 1]];
        yield 'isi diubah sesudah ditandatangani' => [['body' => '{"site_id":"lain"}']];
        yield 'jam terlalu lampau' => [['created_offset' => -301]];
        yield 'jam terlalu depan' => [['created_offset' => 301]];
        yield 'keyid situs lain' => [['keyid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']];
        yield 'urutan komponen lain' => [['components' => '("@path" "@method" "content-digest")']];
        yield 'komponen berkurang' => [['components' => '("@method" "@path")']];
        yield 'parameter tambahan' => [['params_suffix' => ';nonce="x"']];
        yield 'path lain yang ditandatangani' => [['signed_path' => '/api/agent/v1/operations/claim']];
    }

    /** @param  array<string, mixed>  $tamper */
    #[DataProvider('tamperings')]
    public function test_a_tampered_signature_is_refused(array $tamper): void
    {
        $this->rsaKey(1);
        $site = $this->enrolledSite();

        if (isset($tamper['created_offset'])) {
            $tamper['created'] = now()->getTimestamp() + $tamper['created_offset'];
            unset($tamper['created_offset']);
        }

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site), $tamper)
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'signature_invalid']);

        $this->assertNull($site->refresh()->last_seen_at);
    }

    public function test_a_request_without_signature_headers_is_refused(): void
    {
        $site = $this->enrolledSite();

        $this->postJson('/api/agent/v1/report', $this->report($site))->assertUnauthorized();
    }

    public function test_a_revoked_site_is_refused_even_with_a_valid_signature(): void
    {
        $site = $this->enrolledSite(0, ['revoked_at' => now()]);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site))->assertUnauthorized();
    }

    // ------------------------------------------------------------------ data yang boleh keluar

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forbiddenReports(): iterable
    {
        yield 'kunci atas tambahan' => [['logs' => 'isi log aplikasi']];
        yield 'kunci di dalam container' => [['containers' => [['service' => 'core-app', 'state' => 'running', 'env' => 'DB_PASSWORD=x']]]];
        yield 'kunci di dalam disk' => [['disk' => ['data_free_bytes' => 1, 'mounts' => ['/data']]]];
        yield 'kunci di dalam cadangan' => [['last_backup' => ['at' => '2026-09-14T00:00:00Z', 'result' => 'succeeded', 'path' => '/opt/coreerp/cadangan/x.dump']]];
    }

    /**
     * Daftar tertutup "data yang boleh keluar dari server klien" ditegakkan di admin.erp, bukan
     * diserahkan pada kebiasaan agen.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('forbiddenReports')]
    public function test_a_report_carrying_anything_outside_the_contract_is_refused_whole(array $extra): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, $extra))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'report_invalid']);

        $this->assertNull($site->refresh()->last_report);
        $this->assertSame(0, SiteReport::query()->count());
    }

    public function test_a_report_naming_another_site_is_refused(): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['site_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA']))
            ->assertStatus(422);
    }

    public function test_only_reports_whose_content_changed_become_history(): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site))->assertOk();
        $this->travel(1)->minutes();
        // Jam laporan dan sisa disk berubah setiap menit tanpa ada yang terjadi.
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['disk' => ['data_free_bytes' => 999, 'backup_free_bytes' => 1]]))->assertOk();
        $this->assertSame(1, SiteReport::query()->count());

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['release' => '0.2.0']))->assertOk();
        $this->assertSame(2, SiteReport::query()->count());
        $this->assertSame('0.2.0', $site->refresh()->reported_release);
    }

    // ------------------------------------------------------------------ antrean operasi

    public function test_claiming_hands_out_one_operation_at_a_time(): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        $backup = $this->operation($site, 'backup');
        $this->operation($site, 'send_diagnostics');

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $backup->id)
            ->assertJsonPath('operation', 'backup');

        $this->assertSame('running', $backup->refresh()->status);
        $this->assertNotNull($backup->lease_until);

        // Satu berjalan per situs.
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();
    }

    public function test_an_upgrade_is_only_handed_out_inside_the_update_window(): void
    {
        $site = $this->enrolledSite(0, ['update_window_start' => '22:00', 'update_window_end' => '04:00']);
        $upgrade = $this->operation($site, 'upgrade', ['edition' => 'apotek-sejahtera', 'release' => '0.2.0']);

        $this->travelTo(CarbonImmutable::parse('2026-09-14 14:00', 'Asia/Jakarta'));
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        // Jendela yang melewati tengah malam: 01.30 masih di dalamnya.
        $this->travelTo(CarbonImmutable::parse('2026-09-15 01:30', 'Asia/Jakarta'));
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $upgrade->id)
            ->assertJsonPath('parameters.release', '0.2.0');
    }

    public function test_other_operations_are_handed_out_outside_the_window(): void
    {
        $site = $this->enrolledSite(0, ['update_window_start' => '22:00', 'update_window_end' => '04:00']);
        $this->operation($site, 'upgrade', ['edition' => 'apotek-sejahtera', 'release' => '0.2.0']);
        $backup = $this->operation($site, 'backup');

        $this->travelTo(CarbonImmutable::parse('2026-09-14 14:00', 'Asia/Jakarta'));

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $backup->id);
    }

    public function test_steps_extend_the_lease_and_the_final_step_closes_the_operation(): void
    {
        $site = $this->enrolledSite();
        $operation = $this->claimed($site, 'backup');

        $this->travel(10)->minutes();

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'running', 'step' => 'Mencadangkan database'])
            ->assertOk();

        $this->assertTrue($operation->refresh()->lease_until->gt(now()->addMinutes(14)));

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'succeeded', 'step' => 'Selesai'])
            ->assertOk()
            ->assertJsonPath('lease_until', null);

        $this->assertSame('succeeded', $operation->refresh()->status);
        $this->assertNotNull($operation->finished_at);

        // Sesudah ditutup, agen tidak lagi memegangnya.
        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'running', 'step' => 'Lagi'])
            ->assertStatus(409);
    }

    public function test_a_failed_step_without_a_reason_still_records_one(): void
    {
        $site = $this->enrolledSite();
        $operation = $this->claimed($site, 'backup');

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'failed', 'step' => 'Mencadangkan database'])
            ->assertOk();

        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertNotEmpty($operation->failure_message);
    }

    /** Agen yang mati di tengah jalan tidak memegang operasi selamanya. */
    public function test_an_expired_lease_fails_the_operation_and_frees_the_queue(): void
    {
        $site = $this->enrolledSite();
        $stuck = $this->claimed($site, 'backup');
        $next = $this->operation($site, 'send_diagnostics');

        $this->travel(16)->minutes();

        $this->agent('POST', "/api/agent/v1/operations/{$stuck->id}/steps", $site, ['status' => 'running', 'step' => 'Terlambat'])
            ->assertStatus(409);

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])
            ->assertOk()
            ->assertJsonPath('id', $next->id);

        $this->assertSame('failed', $stuck->refresh()->status);
        $this->assertStringContainsString('Tenggat habis', (string) $stuck->failure_message);
    }

    public function test_a_site_cannot_report_steps_for_another_sites_operation(): void
    {
        $this->rsaKey(1);
        $mine = $this->enrolledSite(0, ['name' => 'Milikku']);
        $theirs = $this->enrolledSite(1, ['name' => 'Milik lain', 'tenant_id' => $mine->tenant_id]);
        $operation = $this->claimed($theirs, 'backup', 1);

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $mine, ['status' => 'succeeded', 'step' => 'Selesai'])
            ->assertStatus(409);

        $this->assertSame('running', $operation->refresh()->status);
    }

    // ------------------------------------------------------------------ berkas rilis dan kunci

    public function test_release_files_are_served_verbatim_and_only_for_the_sites_edition(): void
    {
        $site = $this->enrolledSite();
        $signature = random_bytes(384);

        SiteRelease::query()->create([
            'edition' => 'apotek-sejahtera', 'release' => '0.2.0',
            'image' => 'ghcr.io/x@sha256:'.str_repeat('a', 64), 'digest' => 'sha256:'.str_repeat('b', 64),
            'manifest' => '{"edisi":"apotek-sejahtera"}', 'compose' => "name: coreerp\n", 'update_script' => "#!/usr/bin/env bash\n",
            'checksums' => 'x', 'signature' => base64_encode($signature),
        ]);
        SiteRelease::query()->create([
            'edition' => 'praktek-dr-budi', 'release' => '0.2.0',
            'image' => 'ghcr.io/x@sha256:'.str_repeat('c', 64), 'digest' => 'sha256:'.str_repeat('d', 64),
            'manifest' => '{}', 'compose' => '', 'update_script' => '', 'checksums' => '', 'signature' => base64_encode('x'),
        ]);

        $response = $this->agent('GET', '/api/agent/v1/releases/apotek-sejahtera/0.2.0/files/SHA256SUMS.sig', $site)->assertOk();
        $this->assertSame($signature, $response->getContent());

        $this->agent('GET', '/api/agent/v1/releases/apotek-sejahtera/0.2.0/files/compose.yaml', $site)
            ->assertOk()
            ->assertContent("name: coreerp\n");

        // Rilis edisi lain memuat modul yang tidak dibeli klien ini.
        $this->agent('GET', '/api/agent/v1/releases/praktek-dr-budi/0.2.0/files/manifest.json', $site)->assertNotFound();
        $this->agent('GET', '/api/agent/v1/releases/apotek-sejahtera/0.2.0/files/.env', $site)->assertNotFound();
        $this->agent('GET', '/api/agent/v1/releases/apotek-sejahtera/9.9.9/files/manifest.json', $site)->assertNotFound();
    }

    public function test_rotating_the_key_retires_the_old_one(): void
    {
        $site = $this->enrolledSite();
        $new = $this->rsaKey(1);

        $this->agent('POST', '/api/agent/v1/key', $site, ['public_key' => $new['public']])->assertOk();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site))->assertUnauthorized();
        $this->agent('POST', '/api/agent/v1/report', $site->refresh(), $this->report($site), ['key_slot' => 1])->assertOk();
    }

    // ------------------------------------------------------------------ pembantu

    /** @param  array<string, mixed>  $parameters */
    private function operation(Site $site, string $operation, array $parameters = []): SiteOperation
    {
        $created = SiteOperation::query()->create([
            'site_id' => $site->id,
            'operation' => $operation,
            'parameters' => $parameters,
            'status' => 'requested',
            'requested_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // Urutan pengambilan dibaca dari waktu permintaan; dua baris dalam detik yang sama harus
        // tetap punya urutan yang pasti.
        $this->travel(1)->seconds();

        return $created;
    }

    private function claimed(Site $site, string $operation, int $keySlot = 0): SiteOperation
    {
        $this->operation($site, $operation);
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [], ['key_slot' => $keySlot])->assertOk();

        return SiteOperation::query()->where('site_id', $site->id)->where('status', 'running')->sole();
    }
}
