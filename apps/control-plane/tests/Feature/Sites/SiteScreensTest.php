<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layar Situs dan tindakan operator: konfirmasi tertulis, jejak audit, dan jalur offline.
 */
class SiteScreensTest extends SiteTestCase
{
    private string $licenseKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->licenseKeyPath = tempnam(sys_get_temp_dir(), 'kunci-lisensi-');
        file_put_contents($this->licenseKeyPath, $this->rsaKey(7)['private']);
        config(['sites.license_private_key_path' => $this->licenseKeyPath]);
    }

    protected function tearDown(): void
    {
        try {
            @unlink($this->licenseKeyPath);
        } finally {
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------ pembuatan dan akses

    public function test_an_operator_creates_a_site_and_the_action_is_audited(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator)->post('/situs', [
            'tenant_id' => $tenant,
            'name' => 'Server Klinik Pusat',
            'edition' => 'apotek-sejahtera',
            'connectivity' => 'online',
            'update_window_start' => '22:00',
            'update_window_end' => '04:00',
        ])->assertRedirect();

        $site = Site::query()->sole();
        $this->assertSame('managed_on_prem', $site->profile);
        $this->assertSame(['start' => '22:00', 'end' => '04:00', 'timezone' => 'Asia/Jakarta'], $site->updateWindow());

        $event = OperatorAuditEvent::query()->sole();
        $this->assertSame('site.created', $event->action);
        $this->assertSame((int) $operator->id, (int) $event->user_id);
        $this->assertSame($site->id, $event->subject_id);
    }

    public function test_a_half_update_window_is_refused(): void
    {
        $this->actingAs($this->operator())->post('/situs', [
            'tenant_id' => $this->tenant(),
            'name' => 'Setengah Jendela',
            'edition' => 'apotek-sejahtera',
            'connectivity' => 'online',
            'update_window_start' => '22:00',
        ])->assertSessionHasErrors('update_window_end');

        $this->assertSame(0, Site::query()->count());
    }

    public function test_non_operators_see_nothing(): void
    {
        $site = $this->site();
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator', 'email' => 'biasa@contoh.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($id);

        $this->actingAs($user)->get('/situs')->assertNotFound();
        $this->actingAs($user)->post("/situs/{$site->id}/operasi", ['operation' => 'backup', 'confirm_name' => $site->name])->assertNotFound();
    }

    public function test_the_screens_render_for_an_operator(): void
    {
        $site = $this->enrolledSite();
        $operator = $this->operator();

        $this->actingAs($operator)->get('/situs')->assertOk()->assertInertia(fn ($page) => $page->component('sites/index')->has('sites', 1));
        $this->actingAs($operator)->get("/situs/{$site->id}")->assertOk()->assertInertia(fn ($page) => $page->component('sites/show')->where('site.id', $site->id));
    }

    // ------------------------------------------------------------------ operasi

    public function test_every_action_requires_the_site_name_typed_again(): void
    {
        $site = $this->enrolledSite();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post("/situs/{$site->id}/operasi", ['operation' => 'backup', 'confirm_name' => 'situs uji'])
            ->assertSessionHasErrors('confirm_name');

        $this->actingAs($operator)
            ->post("/situs/{$site->id}/cabut", ['confirm_name' => ''])
            ->assertSessionHasErrors('confirm_name');

        $this->assertSame(0, SiteOperation::query()->count());
        $this->assertNull($site->refresh()->revoked_at);
        $this->assertSame(0, OperatorAuditEvent::query()->count());
    }

    public function test_a_requested_operation_is_audited_and_cannot_be_requested_twice_while_pending(): void
    {
        $site = $this->enrolledSite();
        $operator = $this->operator();
        $input = ['operation' => 'backup', 'confirm_name' => $site->name];

        $this->actingAs($operator)->post("/situs/{$site->id}/operasi", $input)->assertRedirect("/situs/{$site->id}");
        $this->actingAs($operator)->post("/situs/{$site->id}/operasi", $input)->assertSessionHasErrors('operation');

        $this->assertSame(1, SiteOperation::query()->count());
        $this->assertSame(['site.operation.requested'], OperatorAuditEvent::query()->pluck('action')->all());
    }

    public function test_an_upgrade_must_name_a_registered_newer_release(): void
    {
        $site = $this->enrolledSite(0, ['reported_release' => '0.2.0']);
        $operator = $this->operator();

        foreach (['0.1.0', '0.2.0', '0.9.0'] as $release) {
            SiteRelease::query()->create([
                'edition' => 'apotek-sejahtera', 'release' => $release,
                'image' => 'ghcr.io/x@sha256:'.str_repeat('a', 64), 'digest' => 'sha256:'.str_repeat('b', 64),
                'manifest' => '{}', 'compose' => '', 'update_script' => '', 'checksums' => '', 'signature' => base64_encode('x'),
            ]);
        }

        foreach (['0.1.0', '0.2.0', '1.0.0'] as $refused) {
            $this->actingAs($operator)
                ->post("/situs/{$site->id}/operasi", ['operation' => 'upgrade', 'release' => $refused, 'confirm_name' => $site->name])
                ->assertSessionHasErrors('operation');
        }

        // `0.10.0` lebih besar dari `0.9.0`: perbandingan bertitik, bukan perbandingan string.
        SiteRelease::query()->create([
            'edition' => 'apotek-sejahtera', 'release' => '0.10.0',
            'image' => 'ghcr.io/x@sha256:'.str_repeat('c', 64), 'digest' => 'sha256:'.str_repeat('d', 64),
            'manifest' => '{}', 'compose' => '', 'update_script' => '', 'checksums' => '', 'signature' => base64_encode('x'),
        ]);

        $this->actingAs($operator)
            ->post("/situs/{$site->id}/operasi", ['operation' => 'upgrade', 'release' => '0.10.0', 'confirm_name' => $site->name])
            ->assertRedirect();

        $this->assertSame(['edition' => 'apotek-sejahtera', 'release' => '0.10.0'], SiteOperation::query()->sole()->parameters);

        $this->actingAs($operator)->get("/situs/{$site->id}")
            ->assertInertia(fn ($page) => $page->where('releases', ['0.10.0', '0.9.0']));
    }

    public function test_an_offline_or_unenrolled_site_does_not_accept_operations(): void
    {
        $operator = $this->operator();
        $offline = $this->enrolledSite(0, ['name' => 'Luring', 'connectivity' => 'offline']);
        $unenrolled = $this->site(['name' => 'Belum']);

        foreach ([$offline, $unenrolled] as $site) {
            $this->actingAs($operator)
                ->post("/situs/{$site->id}/operasi", ['operation' => 'backup', 'confirm_name' => $site->name])
                ->assertSessionHasErrors('operation');
        }

        $this->assertSame(0, SiteOperation::query()->count());
    }

    public function test_a_license_operation_carries_a_license_signed_for_this_site(): void
    {
        $site = $this->enrolledSite();

        $this->actingAs($this->operator())
            ->post("/situs/{$site->id}/operasi", ['operation' => 'install_license', 'valid_until' => '2027-09-14', 'confirm_name' => $site->name])
            ->assertRedirect();

        $parameters = SiteOperation::query()->sole()->parameters;
        $license = json_decode($parameters['license'], true);

        $this->assertSame($site->id, $license['site_id']);
        $this->assertSame($site->tenant_id, $license['tenant_id']);
        $this->assertSame('2027-09-14', $license['valid_until']);
        $signature = base64_decode($parameters['signature'], true);
        $this->assertIsString($signature);
        $this->assertSame(1, openssl_verify($parameters['license'], $signature, $this->rsaKey(7)['public'], OPENSSL_ALGO_SHA256));
    }

    public function test_cancelling_and_revoking_are_audited_and_revoking_cancels_pending_operations(): void
    {
        $site = $this->enrolledSite();
        $operator = $this->operator();

        $this->actingAs($operator)->post("/situs/{$site->id}/operasi", ['operation' => 'backup', 'confirm_name' => $site->name]);
        $backup = SiteOperation::query()->sole();
        $this->actingAs($operator)->post("/situs/{$site->id}/operasi/{$backup->id}/batal")->assertRedirect();
        $this->assertSame('cancelled', $backup->refresh()->status);

        $this->actingAs($operator)->post("/situs/{$site->id}/operasi", ['operation' => 'send_diagnostics', 'confirm_name' => $site->name]);
        $this->actingAs($operator)->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name])->assertRedirect();

        $this->assertNotNull($site->refresh()->revoked_at);
        $this->assertSame(0, SiteOperation::query()->where('status', 'requested')->count());
        $this->assertSame(
            ['site.operation.requested', 'site.operation.cancelled', 'site.operation.requested', 'site.revoked'],
            OperatorAuditEvent::query()->orderBy('occurred_at')->orderBy('id')->pluck('action')->all(),
        );
    }

    // ------------------------------------------------------------------ pendaftaran

    public function test_the_online_install_command_is_shown_once_and_its_token_is_not_audited(): void
    {
        $site = $this->site();
        config(['sites.agent_source_ref' => 'abc123', 'app.url' => 'https://admin.contoh.test']);

        $response = $this->actingAs($this->operator())
            ->post("/situs/{$site->id}/pendaftaran-online", ['confirm_name' => $site->name])
            ->assertRedirect("/situs/{$site->id}");

        $command = (string) session('enrollment')['command'];
        $this->assertStringStartsWith('curl -fsSL https://raw.githubusercontent.com/MettaDevs/coreERP/abc123/deploy/agent/pasang.sh', $command);
        $this->assertStringContainsString('--admin-url https://admin.contoh.test', $command);

        preg_match('/--token (\S+)/', $command, $match);
        $token = $match[1] ?? '';
        $this->assertNotSame('', $token);
        $this->assertSame(hash('sha256', $token), SiteEnrollmentToken::query()->sole()->token_hash);

        $this->assertStringNotContainsString($token, (string) json_encode(OperatorAuditEvent::query()->sole()->detail));
        $response->assertSessionHas('enrollment');
    }

    public function test_the_offline_package_carries_a_token_only_for_an_unenrolled_offline_site(): void
    {
        $operator = $this->operator();
        $offline = $this->site(['name' => 'Luring', 'connectivity' => 'offline']);

        $response = $this->actingAs($operator)->post("/situs/{$offline->id}/paket-pendaftaran", ['confirm_name' => 'Luring'])->assertOk();
        $package = json_decode((string) $response->streamedContent(), true);

        $this->assertSame($offline->id, $package['site_id']);
        $this->assertSame($offline->tenant_id, $package['tenant_id']);
        $this->assertSame('offline', $package['channel']);
        $this->assertSame(hash('sha256', $package['enrollment_token']), SiteEnrollmentToken::query()->sole()->token_hash);

        $enrolled = $this->enrolledSite(0, ['name' => 'Sudah', 'connectivity' => 'offline']);
        $this->actingAs($operator)->post("/situs/{$enrolled->id}/paket-pendaftaran", ['confirm_name' => 'Sudah'])->assertSessionHasErrors('confirm_name');
    }

    // ------------------------------------------------------------------ file laporan offline

    public function test_the_first_report_file_binds_the_site_key_with_its_offline_token(): void
    {
        $site = $this->site(['name' => 'Luring', 'connectivity' => 'offline']);
        $token = $this->enrollmentToken($site, 'offline', now()->addDays(30));
        $key = $this->rsaKey(2);

        $this->upload($site, $this->reportFile($site, $key, ['token' => $token, 'public_key' => $key['public']]))
            ->assertRedirect("/situs/{$site->id}")
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertSame(trim($key['public']), trim((string) $site->public_key));
        $this->assertSame('file', $site->last_seen_via);
        $this->assertNotNull(SiteEnrollmentToken::query()->sole()->used_at);

        // Sesudah terikat, file berikutnya diperiksa dengan kunci yang tersimpan.
        $this->upload($site, $this->reportFile($site, $key, null, ['release' => '0.2.0']))->assertSessionHasNoErrors();
        $this->assertSame('0.2.0', $site->refresh()->reported_release);

        $this->upload($site, $this->reportFile($site, $this->rsaKey(3), null, ['release' => '9.9.9']))->assertSessionHasErrors('report');
        $this->assertSame('0.2.0', $site->refresh()->reported_release);
    }

    /**
     * Tanpa token, siapa pun dapat membuat pasangan kunci sendiri dan mengikatnya ke situs mana saja.
     */
    public function test_a_report_file_with_a_wrong_token_or_forged_signature_binds_nothing(): void
    {
        $site = $this->site(['name' => 'Luring', 'connectivity' => 'offline']);
        $token = $this->enrollmentToken($site, 'offline', now()->addDays(30));
        $key = $this->rsaKey(2);

        $this->upload($site, $this->reportFile($site, $key, ['token' => str_repeat('z', 48), 'public_key' => $key['public']]))
            ->assertSessionHasErrors('report');

        // Kunci yang dibawa file bukan kunci yang menandatanganinya.
        $this->upload($site, $this->reportFile($site, $this->rsaKey(3), ['token' => $token, 'public_key' => $key['public']]))
            ->assertSessionHasErrors('report');

        $this->assertNull($site->refresh()->public_key);
        $this->assertNull(SiteEnrollmentToken::query()->sole()->used_at);
    }

    public function test_a_token_for_another_site_does_not_bind_this_one(): void
    {
        $tenant = $this->tenant();
        $site = $this->site(['name' => 'Luring A', 'connectivity' => 'offline', 'tenant_id' => $tenant]);
        $other = $this->site(['name' => 'Luring B', 'connectivity' => 'offline', 'tenant_id' => $tenant]);
        $otherToken = $this->enrollmentToken($other, 'offline', now()->addDays(30));
        $key = $this->rsaKey(2);

        $this->upload($site, $this->reportFile($site, $key, ['token' => $otherToken, 'public_key' => $key['public']]))
            ->assertSessionHasErrors('report');

        $this->assertNull($site->refresh()->public_key);
        $this->assertNull($other->refresh()->public_key);
    }

    // ------------------------------------------------------------------ jejak audit

    public function test_the_audit_trail_cannot_be_edited_or_deleted(): void
    {
        $site = $this->site();
        $this->actingAs($this->operator())->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name]);

        $event = OperatorAuditEvent::query()->sole();

        foreach ([
            fn () => DB::table('operator_audit_events')->where('id', $event->id)->update(['action' => 'disembunyikan']),
            fn () => DB::table('operator_audit_events')->where('id', $event->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Jejak audit tidak boleh dapat diubah atau dihapus.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('hanya boleh ditambah', $e->getMessage());
            }
        }

        $this->assertSame('site.revoked', $event->refresh()->action);
    }

    // ------------------------------------------------------------------ pembantu

    /**
     * @param  array{private: string, public: string}  $key
     * @param  array{token: string, public_key: string}|null  $enrollment
     * @param  array<string, mixed>  $overrides
     */
    private function reportFile(Site $site, array $key, ?array $enrollment, array $overrides = []): string
    {
        $content = (string) json_encode(['report' => $this->report($site, $overrides), 'enrollment' => $enrollment], JSON_UNESCAPED_SLASHES);
        openssl_sign($content, $signature, $key['private'], OPENSSL_ALGO_SHA256);

        return (string) json_encode([
            'format' => 'coreerp-site-report-v1',
            'content' => base64_encode($content),
            'signature' => base64_encode((string) $signature),
        ]);
    }

    /** @return TestResponse<Response> */
    private function upload(Site $site, string $contents): TestResponse
    {
        return $this->actingAs($this->operatorOnce())
            ->post("/situs/{$site->id}/laporan", ['report' => UploadedFile::fake()->createWithContent('laporan.json', $contents)]);
    }

    private ?User $cachedOperator = null;

    private function operatorOnce(): User
    {
        return $this->cachedOperator ??= $this->operator('pengunggah@contoh.test');
    }
}
