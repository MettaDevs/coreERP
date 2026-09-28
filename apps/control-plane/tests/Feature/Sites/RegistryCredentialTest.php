<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Registry\RegistrySettings;
use Illuminate\Http\Client\Request as HarborRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Kredensial registry untuk agen (CP-02, CP-03): robot pull-only per operasi, lahir saat agen yang memegang
 * operasinya meminta, dan dihapus begitu operasinya tidak lagi dipegang.
 *
 * Harbor di sini tiruan. Bentuk permintaan dan jawabannya diambil dari Harbor v2.15.2 yang sungguhan di
 * server pertama — termasuk izin robot sistem yang cukup, dan bahwa robot/update tidak ada — lalu dijalankan
 * sekali lagi terhadap Harbor itu sebelum rilis (lihat `deploy/registry/SPIKE.md`).
 */
final class RegistryCredentialTest extends SiteTestCase
{
    private const HARBOR = 'http://harbor.uji';

    private const ROBOT_SECRET = 'rahasia-robot-situs-uji-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sites.registry_api_url' => self::HARBOR, 'sites.registry_host' => 'registry.uji.test']);
        app(RegistrySettings::class)->storeRobot('robot$konsol', 'rahasia-robot-sistem', null);
    }

    public function test_the_agent_holding_an_upgrade_receives_a_pull_only_robot_for_that_operation(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $this->fakeHarbor(created: 41);

        $response = $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])
            ->assertOk()
            ->assertJsonPath('registry', 'registry.uji.test')
            ->assertJsonPath('username', 'robot$coreerp+situs-41')
            ->assertJsonPath('password', self::ROBOT_SECRET);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $response->json('expires_at'));
        // Rahasia tidak boleh tinggal di cache mana pun di antara konsol dan agen.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        Http::assertSent(function (HarborRequest $request) use ($operation): bool {
            return $request->method() === 'POST'
                && $request->url() === self::HARBOR.'/api/v2.0/robots'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('robot$konsol:rahasia-robot-sistem'))
                && $request['level'] === 'project'
                && $request['duration'] === 1
                && str_starts_with((string) $request['name'], 'situs-'.strtolower($operation->id).'-')
                // Hanya pull, hanya di project registry. Izin lain di sini berarti server klien dapat
                // mendorong image ke registry yang ditarik semua klien.
                && $request['permissions'] === [[
                    'kind' => 'project',
                    'namespace' => 'coreerp',
                    'access' => [['resource' => 'repository', 'action' => 'pull']],
                ]];
        });

        $operation->refresh();
        $this->assertSame(41, $operation->registry_robot_id);
        $this->assertSame('robot$coreerp+situs-41', $operation->registry_robot_name);

        $audit = OperatorAuditEvent::query()->where('action', 'site.registry_robot.issued')->sole();
        $this->assertSame($operation->id, $audit->detail['operation_id']);
        $this->assertStringNotContainsString(self::ROBOT_SECRET, (string) json_encode($audit->detail));
    }

    public function test_an_install_is_served_the_same_way(): void
    {
        [$site, $operation] = $this->heldOperation('install');
        $this->fakeHarbor(created: 42);

        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])->assertOk();

        $this->assertSame(42, $operation->refresh()->registry_robot_id);
    }

    /**
     * Kredensial hanya untuk operasi yang benar-benar sedang dipegang agen ini. Tanpa penjaga ini, situs yang
     * datanya dicuri dapat meminta robot kapan saja, dan operasi `backup` dapat menjadi pintu menarik image.
     */
    public function test_operations_not_held_by_this_agent_get_no_robot(): void
    {
        Http::fake();
        // Satu situs per kasus: database hanya mengizinkan satu operasi berjalan per situs.
        $other = $this->enrolledSite(9, ['name' => 'Situs Lain']);
        $cases = [
            'masih diminta' => [0, 'upgrade', ['status' => 'requested', 'started_at' => null, 'lease_until' => null]],
            'tenggat habis' => [1, 'upgrade', ['lease_until' => now()->subMinute()]],
            'bukan penarik' => [2, 'backup', []],
            'sudah selesai' => [3, 'install', ['status' => 'succeeded', 'lease_until' => null, 'finished_at' => now()]],
        ];

        foreach ($cases as $label => [$slot, $kind, $attributes]) {
            $site = $this->enrolledSite($slot, ['name' => 'Situs '.$label]);
            $operation = $this->operation($site, $kind, $attributes);

            $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id], ['key_slot' => $slot])
                ->assertStatus(409)
                ->assertJsonPath('error', 'operation_not_held');
        }

        $site = $this->enrolledSite(4, ['name' => 'Situs Peminta']);
        $foreign = $this->operation($other, 'upgrade');
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $foreign->id], ['key_slot' => 4])->assertStatus(409);
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], ['key_slot' => 4])->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(0, SiteOperation::query()->whereNotNull('registry_robot_id')->count());
    }

    public function test_a_malformed_request_is_refused(): void
    {
        Http::fake();
        [$site, $operation] = $this->heldOperation('upgrade');

        $this->agent('POST', '/api/agent/v1/registry-credential', $site, [])->assertStatus(422);
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id, 'lagi' => 1])->assertStatus(422);
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => str_repeat('A', 27)])->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Agen meminta ulang saat pull dijawab 401 di tengah jalan. Satu operasi tetap memegang satu robot. */
    public function test_asking_again_replaces_the_robot_instead_of_piling_them_up(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $operation->forceFill(['registry_robot_id' => 41, 'registry_robot_name' => 'robot$coreerp+situs-41'])->save();
        $this->fakeHarbor(created: 43);

        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])->assertOk();

        Http::assertSentInOrder([
            fn (HarborRequest $request): bool => $request->method() === 'DELETE' && $request->url() === self::HARBOR.'/api/v2.0/robots/41',
            fn (HarborRequest $request): bool => $request->method() === 'POST' && $request->url() === self::HARBOR.'/api/v2.0/robots',
        ]);
        $this->assertSame(43, $operation->refresh()->registry_robot_id);
        $this->assertSame('diganti', OperatorAuditEvent::query()->where('action', 'site.registry_robot.deleted')->sole()->detail['reason']);
    }

    public function test_an_unconfigured_or_failing_registry_answers_503_and_keeps_the_operation(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');

        Http::fake([self::HARBOR.'/*' => Http::response(['errors' => [['code' => 'INTERNAL']]], 500)]);
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])
            ->assertStatus(503)
            ->assertJsonPath('error', 'registry_unavailable');

        DB::table('console_settings')->delete();
        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])
            ->assertStatus(503)
            ->assertJsonPath('error', 'registry_unavailable');

        $operation->refresh();
        $this->assertSame('running', $operation->status);
        $this->assertNull($operation->registry_robot_id);
    }

    /**
     * Operasi dapat ditutup — tenggat habis, dibatalkan — selama Harbor membuat robotnya. Robot itu tidak
     * boleh diberikan kepada siapa pun, dan tidak boleh tertinggal di Harbor.
     */
    public function test_a_robot_born_after_its_operation_closed_is_deleted_and_never_handed_out(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');

        Http::fake(function (HarborRequest $request) use ($operation) {
            if ($request->method() === 'POST') {
                SiteOperation::query()->whereKey($operation->id)->update([
                    'status' => 'failed',
                    'failure_message' => 'Tenggat habis.',
                    'finished_at' => now(),
                ]);

                return Http::response(['id' => 44, 'name' => 'robot$coreerp+situs-44', 'secret' => self::ROBOT_SECRET, 'expires_at' => now()->addDay()->getTimestamp()], 201);
            }

            return Http::response([], 200);
        });

        $this->agent('POST', '/api/agent/v1/registry-credential', $site, ['operation_id' => $operation->id])
            ->assertStatus(409)
            ->assertJsonMissingPath('password');

        Http::assertSent(fn (HarborRequest $request): bool => $request->method() === 'DELETE' && $request->url() === self::HARBOR.'/api/v2.0/robots/44');
        $this->assertNull($operation->refresh()->registry_robot_id);
    }

    public function test_the_final_step_deletes_the_robot_of_that_operation(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $operation->forceFill(['registry_robot_id' => 41, 'registry_robot_name' => 'robot$coreerp+situs-41'])->save();
        $this->fakeHarbor();

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'succeeded', 'step' => 'Selesai'])->assertOk();

        Http::assertSent(fn (HarborRequest $request): bool => $request->method() === 'DELETE' && $request->url() === self::HARBOR.'/api/v2.0/robots/41');
        $this->assertNull($operation->refresh()->registry_robot_id);
        $this->assertSame('operasi_ditutup', OperatorAuditEvent::query()->where('action', 'site.registry_robot.deleted')->sole()->detail['reason']);
    }

    /** Harbor yang sedang tidak menjawab tidak menggagalkan laporan agen; robotnya disapu pada kunjungan berikutnya. */
    public function test_a_failed_deletion_is_retried_on_the_next_claim_without_failing_the_agent(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $operation->forceFill(['registry_robot_id' => 41, 'registry_robot_name' => 'robot$coreerp+situs-41'])->save();

        // Satu palsuan dengan keadaan: palsuan Http yang didaftarkan belakangan tidak menggantikan yang pertama.
        $harbor = new class
        {
            public bool $menjawab = false;
        };
        Http::fake(fn () => Http::response([], $harbor->menjawab ? 200 : 502));

        $this->agent('POST', "/api/agent/v1/operations/{$operation->id}/steps", $site, ['status' => 'failed', 'step' => 'Menarik image', 'failure_message' => 'pull gagal'])->assertOk();
        $this->assertSame(41, $operation->refresh()->registry_robot_id);

        $harbor->menjawab = true;
        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        $this->assertNull($operation->refresh()->registry_robot_id);
    }

    public function test_a_robot_still_in_use_is_not_swept(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $operation->forceFill(['registry_robot_id' => 41, 'registry_robot_name' => 'robot$coreerp+situs-41'])->save();
        Http::fake();

        $this->agent('POST', '/api/agent/v1/operations/claim', $site, (object) [])->assertNoContent();

        Http::assertNothingSent();
        $this->assertSame(41, $operation->refresh()->registry_robot_id);
    }

    /** Situs yang dicabut tidak dapat menarik apa pun lagi, termasuk dengan robot operasi yang masih berjalan. */
    public function test_revoking_a_site_deletes_the_robot_of_its_running_operation(): void
    {
        [$site, $operation] = $this->heldOperation('upgrade');
        $operation->forceFill(['registry_robot_id' => 41, 'registry_robot_name' => 'robot$coreerp+situs-41'])->save();
        $this->fakeHarbor();

        $this->actingAs($this->operator())
            ->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name])
            ->assertRedirect();

        Http::assertSent(fn (HarborRequest $request): bool => $request->method() === 'DELETE' && $request->url() === self::HARBOR.'/api/v2.0/robots/41');
        $this->assertNull($operation->refresh()->registry_robot_id);
        $this->assertSame('situs_dicabut', OperatorAuditEvent::query()->where('action', 'site.registry_robot.deleted')->sole()->detail['reason']);
    }

    // ------------------------------------------------------------------ pembantu

    /** @return array{0: Site, 1: SiteOperation} */
    private function heldOperation(string $kind): array
    {
        $site = $this->enrolledSite();

        return [$site, $this->operation($site, $kind)];
    }

    /** @param  array<string, mixed>  $attributes */
    private function operation(Site $site, string $kind, array $attributes = []): SiteOperation
    {
        return SiteOperation::query()->create([
            'site_id' => $site->id,
            'operation' => $kind,
            'parameters' => ['edition' => Site::SINGLE_IMAGE_EDITION, 'release' => '0.2.0'],
            'status' => 'running',
            'requested_at' => now()->subMinutes(2),
            'expires_at' => now()->addDay(),
            'started_at' => now()->subMinute(),
            'lease_until' => now()->addMinutes(10),
            ...$attributes,
        ]);
    }

    private function fakeHarbor(int $created = 41): void
    {
        Http::fake([
            self::HARBOR.'/api/v2.0/robots' => Http::response([
                'id' => $created,
                'name' => 'robot$coreerp+situs-'.$created,
                'secret' => self::ROBOT_SECRET,
                'creation_time' => now()->toIso8601String(),
                'expires_at' => now()->addDay()->getTimestamp(),
            ], 201),
            self::HARBOR.'/api/v2.0/robots/*' => Http::response([], 200),
        ]);
    }
}
