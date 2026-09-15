<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\Environment;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Panel "Server klien" di halaman lingkungan produksi: menyiapkan situs (PS-02) dan membuat perintah
 * pasang (PS-03).
 */
final class ClientServerPanelTest extends SiteTestCase
{
    private const INSTALL_COMMAND = '#^curl -fsSL https://admin\.contoh\.test/pasang\.sh \| sudo bash -s -- --token ([A-Za-z0-9]{48})$#';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://admin.contoh.test']);

        // Perintah pasang memastikan record DNS alamat aplikasi sebelum apa pun dibuat; aturan DNS-nya sendiri
        // diuji di `SiteDnsTest`.
        $this->useCloudflare();
    }

    // ------------------------------------------------------------------ PS-02 panel dan penyiapan

    public function test_the_panel_is_offered_only_for_a_production_on_the_client_server(): void
    {
        $operator = $this->operator();
        $clientServer = $this->clientServerEnvironment($this->tenant('PT Klinik Satu'));
        $ours = $this->providerEnvironment($this->tenant('PT Klinik Dua'));

        $this->actingAs($operator)->get("/lingkungan/{$clientServer->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('environments/show')
                ->where('environment.hosting', 'client_server')
                ->where('serverClient.site', null)
                ->where('serverClient.progress.state', 'not_prepared')
                ->where('serverClient.progress.final', true)
                // Lingkungan di server klien lahir `provisioning`, dan Core menolak menyiapkannya.
                ->where('canProvision', false)
                ->where('modules', [])
                ->where('installCommand', null));

        $this->actingAs($operator)->get("/lingkungan/{$ours->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('environment.hosting', 'provider')
                ->where('serverClient', null));
    }

    public function test_preparing_creates_one_site_named_after_the_tenant_and_audits_it(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant('PT Klinik Sehat');
        $environment = $this->clientServerEnvironment($tenant);

        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien")
            ->assertSessionHasNoErrors()
            ->assertRedirect("/lingkungan/{$environment->id}");

        $site = Site::query()->sole();
        $this->assertSame($tenant, $site->tenant_id);
        $this->assertSame($environment->id, $site->environment_id);
        $this->assertSame('PT Klinik Sehat — Produksi', $site->name);
        $this->assertSame('managed_on_prem', $site->profile);
        $this->assertSame(Site::SINGLE_IMAGE_EDITION, $site->edition);
        $this->assertSame('Asia/Jakarta', $site->timezone);
        $this->assertNull($site->address);
        $this->assertNull($site->updateWindow());
        $this->assertSame((int) $operator->id, (int) $site->created_by);

        $event = OperatorAuditEvent::query()->sole();
        $this->assertSame('site.created', $event->action);
        $this->assertSame($site->id, $event->subject_id);
        $this->assertSame((int) $operator->id, (int) $event->user_id);
        $this->assertSame($environment->id, $event->detail['environment_id']);

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.site.id', $site->id)
                ->where('serverClient.progress.state', 'no_command'));
    }

    public function test_the_advanced_settings_are_optional_and_keep_the_existing_rules(): void
    {
        $operator = $this->operator();
        $environment = $this->clientServerEnvironment($this->tenant());

        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien", ['update_window_start' => '22:00'])
            ->assertSessionHasErrors('update_window_end');

        $this->assertSame(0, Site::query()->count());

        // Alamat aplikasi tidak lagi dapat diisi: ia diturunkan dari lingkungannya. Isian lama yang masih
        // mengirimnya diabaikan, bukan disimpan.
        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien", [
                'address' => 'https://erp.klinik-sendiri.test',
                'update_window_start' => '22:00',
                'update_window_end' => '04:00',
            ])
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();
        $this->assertNull($site->address);
        $this->assertSame('https://'.$environment->tenant?->slug.'.erp.contoh.test', $site->appUrl());
        $this->assertSame(['start' => '22:00', 'end' => '04:00', 'timezone' => 'Asia/Jakarta'], $site->updateWindow());

        // Diubah belakangan, dengan aturan yang sama, dan diaudit.
        $this->actingAs($operator)
            ->patch("/lingkungan/{$environment->id}/server-klien", ['update_window_end' => '05:00'])
            ->assertSessionHasErrors('update_window_start');

        $this->actingAs($operator)
            ->patch("/lingkungan/{$environment->id}/server-klien", ['update_window_start' => '', 'update_window_end' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/lingkungan/{$environment->id}");

        $this->assertNull($site->refresh()->updateWindow());

        $updated = OperatorAuditEvent::query()->where('action', 'site.settings.updated')->sole();
        // jsonb tidak menyimpan urutan kunci; yang dibandingkan isinya.
        $this->assertEquals(['start' => '22:00', 'end' => '04:00', 'timezone' => 'Asia/Jakarta'], $updated->detail['before']['update_window']);
        $this->assertNull($updated->detail['after']['update_window']);
        $this->assertArrayNotHasKey('address', $updated->detail['after']);
    }

    public function test_preparing_twice_is_refused_with_a_friendly_error(): void
    {
        $operator = $this->operator();
        $environment = $this->clientServerEnvironment($this->tenant());

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/server-klien")->assertSessionHasNoErrors();
        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien")
            ->assertSessionHasErrors(['server_client' => 'Lingkungan ini sudah punya server klien.']);

        $this->assertSame(1, Site::query()->count());
        $this->assertSame(1, OperatorAuditEvent::query()->count());
    }

    /**
     * Dua klik yang berlomba sama-sama lolos pemeriksaan awal; indeks `sites_satu_per_lingkungan` yang
     * memutuskan. Pesaingnya disisipkan tepat sebelum INSERT, sesudah pemeriksaan — dan transaksi test
     * tetap dapat dipakai sesudah penolakannya, yang hanya mungkin bila penolakan itu dibatasi savepoint.
     */
    public function test_a_racing_duplicate_is_refused_by_the_database_inside_a_savepoint(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();
        $environment = $this->clientServerEnvironment($tenant);

        Site::creating(function (Site $site) use ($tenant, $environment): void {
            if ($site->name === 'Pesaing') {
                return;
            }

            Site::withoutEvents(fn () => Site::query()->create([
                'tenant_id' => $tenant, 'environment_id' => $environment->id, 'name' => 'Pesaing',
                'profile' => 'managed_on_prem', 'edition' => Site::SINGLE_IMAGE_EDITION, 'timezone' => 'Asia/Jakarta',
            ]));
        });

        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien")
            ->assertSessionHasErrors(['server_client' => 'Lingkungan ini sudah punya server klien.']);

        // Query sesudah penolakan berjalan: transaksi pembungkus test tidak ikut batal.
        $this->assertSame(0, OperatorAuditEvent::query()->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_a_legacy_site_with_the_same_name_is_refused_with_its_name(): void
    {
        $tenant = $this->tenant('PT Klinik Lama');
        $environment = $this->clientServerEnvironment($tenant);
        $this->site(['tenant_id' => $tenant, 'name' => 'PT Klinik Lama — Produksi']);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/server-klien")
            ->assertSessionHasErrors('server_client');

        $this->assertSame(1, Site::query()->count());
        $this->assertSame(0, OperatorAuditEvent::query()->count());
    }

    public function test_only_a_production_on_the_client_server_can_be_prepared(): void
    {
        $operator = $this->operator();
        $ours = $this->providerEnvironment($this->tenant());

        $this->actingAs($operator)
            ->post("/lingkungan/{$ours->id}/server-klien")
            ->assertSessionHasErrors('server_client');

        $this->actingAs($operator)
            ->post("/lingkungan/{$ours->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertSame(0, Site::query()->count());
        $this->assertSame(0, OperatorAuditEvent::query()->count());
    }

    public function test_non_operators_cannot_touch_the_panel(): void
    {
        $environment = $this->clientServerEnvironment($this->tenant());
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator', 'email' => 'biasa@contoh.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($id);

        $this->actingAs($user)->post("/lingkungan/{$environment->id}/server-klien")->assertNotFound();
        $this->actingAs($user)->patch("/lingkungan/{$environment->id}/server-klien")->assertNotFound();
        $this->actingAs($user)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertNotFound();

        $this->assertSame(0, Site::query()->count());
    }

    // ------------------------------------------------------------------ PS-03 perintah pasang

    public function test_the_install_command_carries_a_fresh_token_and_an_install_operation_shown_once(): void
    {
        $operator = $this->operator();
        [$environment, $site] = $this->prepared('PT Klinik Sehat');
        $this->owner($site->tenant_id, 'Dewi Pemilik', 'dewi@klinik.test');
        $this->fakeEntitlements($site, ['management-aset', 'human-resources']);
        $this->release('0.9.0');
        $this->release('0.10.0');
        // Rilis edisi lain, dan lebih besar, tidak pernah dipilih.
        $this->release('9.0.0', 'apotek-sejahtera');

        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasNoErrors()
            ->assertRedirect("/lingkungan/{$environment->id}");

        $issued = session('install_command');
        $this->assertIsArray($issued);
        $token = SiteEnrollmentToken::query()->sole();
        $this->assertSame(hash('sha256', $this->tokenIn($issued['command'])), $token->token_hash);
        $this->assertNull($token->used_at);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame('dewi@klinik.test', $issued['email']);
        $this->assertSame('0.10.0', $issued['release']);

        $operation = SiteOperation::query()->sole();
        $this->assertSame('install', $operation->operation);
        $this->assertSame('requested', $operation->status);
        $this->assertSame((int) $operator->id, (int) $operation->requested_by);

        $parameters = $operation->parameters;
        $hash = $parameters['admin_password_hash'];
        unset($parameters['admin_password_hash']);
        // jsonb tidak menyimpan urutan kunci; yang dibandingkan isinya.
        ksort($parameters);
        $this->assertSame([
            'admin_email' => 'dewi@klinik.test',
            'admin_name' => 'Dewi Pemilik',
            'app_ids' => ['human-resources', 'management-aset'],
            'app_url' => 'https://'.$environment->tenant?->slug.'.erp.contoh.test',
            'edition' => Site::SINGLE_IMAGE_EDITION,
            'release' => '0.10.0',
            'tenant_id' => $site->tenant_id,
            'tenant_name' => 'PT Klinik Sehat',
        ], $parameters);

        // Bcrypt `$2y$`, satu-satunya bentuk yang diterima `tenant:bootstrap-site`.
        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash);
        $this->assertTrue(Hash::driver('bcrypt')->check($issued['password'], $hash));
        $this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9_]{20}$/', $issued['password']);

        $event = OperatorAuditEvent::query()->where('action', 'site.install_command.issued')->sole();
        $this->assertSame($operation->id, $event->detail['operation_id']);
        $this->assertSame('0.10.0', $event->detail['release']);

        // Ditampilkan sekali: halaman berikutnya tidak lagi memuatnya.
        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('installCommand.command', $issued['command'])
                ->where('installCommand.password', $issued['password'])
                ->where('installCommand.email', 'dewi@klinik.test')
                ->where('serverClient.progress.state', 'awaiting_command')
                ->where('serverClient.progress.final', false));

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page->where('installCommand', null));
    }

    public function test_the_password_the_hash_and_the_token_never_reach_the_audit_trail_or_the_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->release('1.0.0');
        $operator = $this->operator();

        // Dua kali: pembatalan pendahulunya juga menulis jejak.
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $issued = session('install_command');
        $secrets = [
            'kata sandi' => $issued['password'],
            'hash' => SiteOperation::query()->where('status', 'requested')->sole()->parameters['admin_password_hash'],
            'token' => $this->tokenIn($issued['command']),
        ];

        $audit = (string) json_encode(DB::table('operator_audit_events')->get()->all());
        $this->assertNotSame('[]', $audit);
        $everywhere = [
            'jejak audit' => $audit,
            'log' => implode("\n", $logged),
            // Kata sandi teks juga tidak ada di tabel mana pun yang ditulis tindakan ini.
            'database' => (string) json_encode([
                DB::table('site_operations')->get()->all(),
                DB::table('site_enrollment_tokens')->get()->all(),
                DB::table('sites')->get()->all(),
            ]),
        ];

        foreach ($everywhere as $where => $content) {
            foreach ($secrets as $what => $secret) {
                if ($where === 'database' && $what !== 'kata sandi') {
                    continue;
                }

                $this->assertStringNotContainsString($secret, $content, "{$what} tertulis di {$where}.");
                $this->assertStringNotContainsString(str_replace('/', '\\/', $secret), $content, "{$what} tertulis di {$where}.");
            }
        }
    }

    public function test_a_new_command_voids_unused_tokens_and_replaces_the_pending_install(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->release('1.0.0');
        $operator = $this->operator();

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $first = SiteOperation::query()->sole();
        $firstToken = SiteEnrollmentToken::query()->sole();

        $this->travel(1)->seconds();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $this->assertNotNull($firstToken->refresh()->used_at, 'Token perintah lama masih dapat dipakai.');
        $this->assertSame(1, SiteEnrollmentToken::query()->whereNull('used_at')->count());

        $first->refresh();
        $this->assertSame('cancelled', $first->status);
        $this->assertArrayNotHasKey('admin_password_hash', $first->parameters);
        $this->assertSame(1, SiteOperation::query()->where('status', 'requested')->where('operation', 'install')->count());

        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'site.install_command.issued')->latest('occurred_at')->orderByDesc('id')->first()?->detail['cancelled_operations']);
    }

    public function test_without_a_registered_release_the_install_waits_and_says_so(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $operator = $this->operator();

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $this->assertNull(session('install_command')['release']);
        $operation = SiteOperation::query()->sole();
        $this->assertArrayHasKey('release', $operation->parameters);
        $this->assertNull($operation->parameters['release']);

        // Server tersambung, dan pemasangannya menunggu rilis.
        $site->forceFill(['public_key' => $this->rsaKey()['public'], 'enrolled_at' => now(), 'last_seen_at' => now()])->save();

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.state', 'awaiting_release')
                ->where('serverClient.newestRelease', null));

        $this->release('1.0.0');

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.state', 'awaiting_release')
                ->where('serverClient.newestRelease', '1.0.0'));
    }

    public function test_nothing_is_created_when_core_cannot_list_the_apps(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->release('1.0.0');
        Http::fake([$this->entitlementsUrl($site) => Http::response(['message' => 'rusak'], 500)]);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client')
            ->assertSessionMissing('install_command');

        $this->assertStringContainsString('Daftar app tenant tidak terbaca dari Core', (string) session('errors')->first('server_client'));
        $this->assertSame(0, SiteEnrollmentToken::query()->count());
        $this->assertSame(0, SiteOperation::query()->count());
        $this->assertSame(0, OperatorAuditEvent::query()->where('action', 'site.install_command.issued')->count());
    }

    public function test_a_revoked_site_is_refused(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $site->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors(['server_client' => 'Situs ini sudah dicabut, jadi tidak dapat dipasang lagi.']);

        $this->assertSame(0, SiteEnrollmentToken::query()->count());
        $this->assertSame(0, SiteOperation::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_installed_site_is_refused_and_pointed_at_the_operations_screen(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->installOperation($site, 'succeeded');

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertStringContainsString('Minta operasi', (string) session('errors')->first('server_client'));
        $this->assertSame(0, SiteEnrollmentToken::query()->count());
        $this->assertSame(1, SiteOperation::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_running_install_is_not_interrupted_by_a_new_command(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->installOperation($site, 'running');

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertSame(0, SiteEnrollmentToken::query()->count());
        $this->assertSame(1, SiteOperation::query()->count());
    }

    public function test_a_tenant_without_an_active_owner_is_refused(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id, 'Mantan', 'mantan@klinik.test', 'suspended');
        $this->fakeEntitlements($site);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertSame(0, SiteOperation::query()->count());
    }

    public function test_an_unprepared_environment_has_no_command_to_issue(): void
    {
        $environment = $this->clientServerEnvironment($this->tenant());

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors(['server_client' => 'Siapkan server klien lebih dulu.']);

        $this->assertSame(0, SiteEnrollmentToken::query()->count());
    }

    // ------------------------------------------------------------------ PS-04 dan PS-05 di layar

    /**
     * Keadaan panel di setiap tahap, dari perintah pasang sampai terpasang, tertinggal, dan dicabut — dan
     * daftar lingkungan menyebut keadaan yang sama.
     */
    public function test_the_progress_follows_every_stage_and_the_list_says_the_same(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->release('1.0.0');
        $operator = $this->operator();

        $this->assertStates($operator, $environment, 'no_command', true);

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->assertStates($operator, $environment, 'awaiting_command', false);

        // Teknisi terlambat: tokennya habis sebelum dipakai.
        $this->travel(61)->minutes();
        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.state', 'no_command')
                ->where('serverClient.progress.commandExpired', true));

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $site->forceFill(['public_key' => $this->rsaKey()['public'], 'enrolled_at' => now(), 'last_seen_at' => now()])->save();
        SiteEnrollmentToken::query()->whereNull('used_at')->update(['used_at' => now()]);
        $this->assertStates($operator, $environment, 'connected', false);

        $install = SiteOperation::query()->where('status', 'requested')->sole();
        $install->forceFill(['status' => 'running', 'started_at' => now(), 'lease_until' => now()->addMinutes(15), 'step' => 'Menarik rilis'])->save();
        $this->assertStates($operator, $environment, 'installing', false);

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.step', 'Menarik rilis')
                ->where('serverClient.progress.release', '1.0.0')
                // Muat ulang parsial hanya membawa panelnya; perintah yang sedang tampil tidak tersentuh.
                ->reloadOnly('serverClient', fn ($reload) => $reload
                    ->missing('history')
                    ->missing('installCommand')
                    ->where('serverClient.progress.state', 'installing')));

        $install->forceFill(['status' => 'succeeded', 'finished_at' => now(), 'lease_until' => null, 'step' => 'Selesai', 'parameters' => array_diff_key($install->parameters, ['admin_password_hash' => true])])->save();
        $site->forceFill(['reported_release' => '1.0.0'])->save();
        $this->assertStates($operator, $environment, 'ready', true);

        $this->actingAs($operator)->get('/lingkungan')
            ->assertInertia(fn ($page) => $page->where('environments.0.serverClient.reportedRelease', '1.0.0'));

        $this->travel(10)->minutes();
        $this->assertStates($operator, $environment, 'stale', true);

        $site->forceFill(['revoked_at' => now()])->save();
        $this->assertStates($operator, $environment, 'revoked', true);
    }

    public function test_a_failed_install_shows_its_step_and_reason_and_can_be_retried(): void
    {
        [$environment, $site] = $this->prepared();
        $this->owner($site->tenant_id);
        $this->fakeEntitlements($site);
        $this->release('1.0.0');
        $operator = $this->operator();
        $site->forceFill(['public_key' => $this->rsaKey()['public'], 'enrolled_at' => now(), 'last_seen_at' => now()])->save();

        $this->installOperation($site, 'failed', ['step' => 'Menjalankan update.sh', 'failure_message' => 'Disk penuh.']);

        $this->assertStates($operator, $environment, 'failed', true);
        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.step', 'Menjalankan update.sh')
                ->where('serverClient.progress.failureMessage', 'Disk penuh.'));

        // "Coba lagi" menjalankan ulang perintah pasang.
        $this->travel(1)->seconds();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->assertStates($operator, $environment, 'connected', false);

        // Agen yang berhenti di tengah pemasangan: tenggatnya habis, panel menyebutnya gagal.
        $retry = SiteOperation::query()->where('status', 'requested')->sole();
        $retry->forceFill(['status' => 'running', 'started_at' => now(), 'lease_until' => now()->addMinutes(15)])->save();
        $this->travel(16)->minutes();
        $site->forceFill(['last_seen_at' => now()])->save();

        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.state', 'failed')
                ->where('serverClient.progress.failureMessage', fn (string $message): bool => str_contains($message, 'Tenggat habis')));

        $this->assertArrayNotHasKey('admin_password_hash', $retry->refresh()->parameters);
    }

    // ------------------------------------------------------------------ perkakas

    /** Token di dalam perintah pasang; perintahnya sendiri wajib berbentuk persis satu baris itu. */
    private function tokenIn(string $command): string
    {
        $this->assertSame(1, preg_match(self::INSTALL_COMMAND, $command, $match), 'Perintah pasang tidak berbentuk yang diharapkan: '.$command);

        return $match[1] ?? '';
    }

    /** @return array{0: Environment, 1: Site} */
    private function prepared(string $tenantName = 'PT Klinik Uji'): array
    {
        $tenant = $this->tenant($tenantName);
        $environment = $this->clientServerEnvironment($tenant);
        $site = $this->site([
            'tenant_id' => $tenant,
            'environment_id' => $environment->id,
            'name' => $tenantName.' — Produksi',
            'edition' => Site::SINGLE_IMAGE_EDITION,
            'server_address' => '103.122.2.72',
        ]);

        return [$environment, $site];
    }

    private function providerEnvironment(string $tenantId): Environment
    {
        return $this->clientServerEnvironment($tenantId, ['hosting' => 'provider']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function installOperation(Site $site, string $status, array $attributes = []): SiteOperation
    {
        $open = in_array($status, ['requested', 'running'], true);

        return SiteOperation::query()->create([
            'site_id' => $site->id,
            'operation' => 'install',
            'parameters' => ['release' => '1.0.0'] + ($open ? ['admin_password_hash' => '$2y$04$'.Str::random(53)] : []),
            'status' => $status,
            'requested_at' => now(),
            'expires_at' => now()->addDays(7),
            'started_at' => $status === 'requested' ? null : now(),
            'lease_until' => $status === 'running' ? now()->addMinutes(15) : null,
            'finished_at' => $open ? null : now(),
            'failure_message' => $status === 'failed' ? 'gagal' : null,
            ...$attributes,
        ]);
    }

    private function assertStates(User $operator, Environment $environment, string $state, bool $final): void
    {
        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('serverClient.progress.state', $state)
                ->where('serverClient.progress.final', $final));

        $this->actingAs($operator)->get('/lingkungan')
            ->assertInertia(fn ($page) => $page
                ->where('environments.0.id', $environment->id)
                ->where('environments.0.serverClient.state', $state));

        $this->actingAs($operator)->get('/situs')
            ->assertInertia(fn ($page) => $page->where('sites.0.progress.state', $state));
    }
}
