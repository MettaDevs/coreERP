<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Layar Pembaruan: keadaan seluruh armada dalam satu halaman, dan dua tombol yang mengubahnya.
 *
 * Sisi jaringannya dipalsukan seperti seluruh test konsol lainnya. Bahwa Core sungguhan menjawab
 * dengan bentuk yang dipalsukan di sini adalah janji kontrak, dan `CoreCommandContractTest` yang
 * menjaganya — pemeriksaan itu ada justru karena tiruan selalu setuju dengan yang menirukannya.
 *
 * `Http::preventStrayRequests()` berdiri di setiap test supaya satu panggilan yang lolos dari
 * palsuan menjadi kegagalan yang berisik, bukan permintaan sungguhan yang keluar dari mesin ini.
 */
class FleetScreenTest extends TestCase
{
    use CoreSchema;

    private const BASE_URL = 'http://core.uji:8000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.base_url' => self::BASE_URL, 'core.token' => 'kunci-uji']);

        Http::preventStrayRequests();
    }

    public function test_the_screen_shows_what_core_reports_about_the_fleet(): void
    {
        Http::fake([self::BASE_URL.'/api/internal/v1/fleet' => Http::response($this->fleet())]);

        $response = $this->actingAs($this->operator())->get('/pembaruan');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('updates/index')
            ->where('platformFingerprint', '2026_09_12_100000_tambah_kolom')
            ->where('counts.current', 1)
            ->where('counts.behind', 1)
            ->where('counts.failed', 1)
            ->where('unreachable', null)
            ->has('environments', 3)
            ->where('environments.1.state', 'behind')
            // Dinamai ulang menjadi camelCase di sisi konsol supaya layar tidak mencampur dua gaya
            // kunci dalam satu objek — sisanya di seluruh konsol ini sudah camelCase.
            ->where('environments.2.lastOperation.status', 'failed')
            ->where('environments.2.lastOperation.reason', 'relation "users" already exists')
        );

        Http::assertSent(fn (OutboundRequest $request): bool => $request->method() === 'GET'
            // Bearer, bukan header kustom. Cacat itu pernah nyata: kedua sisi hijau, panggilan
            // sungguhannya 401, dan masing-masing suite memalsukan lawan bicaranya.
            && $request->hasHeader('Authorization', 'Bearer kunci-uji'));
    }

    /**
     * Core mati adalah jawaban, bukan halaman 500.
     *
     * Yang paling mungkin membawa operator ke layar ini adalah kecurigaan bahwa ada yang tidak
     * beres. Kalau jawabannya "ada, dan yang tidak beres adalah Core sendiri", itu justru jawaban
     * yang ia cari — dan alamat yang dicoba harus ikut terbaca, karena sebab yang paling sering
     * adalah `COREERP_URL` salah setel.
     */
    public function test_core_being_down_is_an_answer_on_the_page_not_a_500(): void
    {
        Http::fake([self::BASE_URL.'/api/internal/v1/fleet' => Http::response(null, 500)]);

        $response = $this->actingAs($this->operator())->get('/pembaruan');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('updates/index')
            ->has('environments', 0)
            ->where('unreachable', fn (mixed $message): bool => is_string($message)
                && str_contains($message, self::BASE_URL.'/api/internal/v1/fleet'))
        );
    }

    public function test_a_rejected_key_names_the_setting_that_has_to_match(): void
    {
        Http::fake([self::BASE_URL.'/api/internal/v1/fleet' => Http::response(null, 401)]);

        $response = $this->actingAs($this->operator())->get('/pembaruan');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('unreachable', fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'CONTROL_PLANE_TOKEN'))
        );
    }

    public function test_the_button_queues_every_environment_that_is_behind(): void
    {
        $operator = $this->operator();

        Http::fake([
            self::BASE_URL.'/api/internal/v1/environments/upgrade' => Http::response([
                'queued' => ['env-satu', 'env-dua'],
                'queued_count' => 2,
            ], 202),
        ]);

        $response = $this->actingAs($operator)->post('/pembaruan');

        $response->assertRedirect('/pembaruan');
        $response->assertSessionHas('message', fn (string $message): bool => str_contains($message, '2 lingkungan diantrekan'));

        Http::assertSent(fn (OutboundRequest $request): bool => $request->url() === self::BASE_URL.'/api/internal/v1/environments/upgrade'
            && $request->method() === 'POST'
            // Operatornya ikut dikirim. Tanpa ini, kolom "Oleh" pada riwayat berbunyi "Sistem"
            // untuk tombol yang baru saja ditekan manusia.
            && $request['requested_by'] === $operator->id);
    }

    public function test_one_row_queues_only_that_row(): void
    {
        Http::fake([
            self::BASE_URL.'/api/internal/v1/environments/env-satu/upgrade' => Http::response([
                'queued' => ['env-satu'],
                'queued_count' => 1,
            ], 202),
        ]);

        $response = $this->actingAs($this->operator())->post('/pembaruan/env-satu');

        $response->assertRedirect('/pembaruan');
        $response->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'Satu lingkungan diantrekan'));
    }

    /**
     * Nol disebut apa adanya.
     *
     * Tombol yang ditekan lalu diam terbaca seperti tombol yang rusak, dan operator akan menekannya
     * lagi — beberapa kali, sampai ia menyimpulkan layarnya yang bermasalah.
     */
    public function test_nothing_to_do_is_said_out_loud(): void
    {
        Http::fake([
            self::BASE_URL.'/api/internal/v1/environments/upgrade' => Http::response([
                'queued' => [],
                'queued_count' => 0,
            ], 202),
        ]);

        $response = $this->actingAs($this->operator())->post('/pembaruan');

        $response->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'Tidak ada lingkungan yang perlu diperbarui'));
    }

    public function test_a_refusal_to_queue_becomes_a_readable_error_not_a_500(): void
    {
        Http::fake([
            self::BASE_URL.'/api/internal/v1/environments/upgrade' => Http::response(null, 500),
        ]);

        $response = $this->actingAs($this->operator())->post('/pembaruan');

        $response->assertRedirect('/pembaruan');
        $response->assertSessionHasErrors('upgrade');
    }

    /**
     * Baris yang bentuknya rusak tidak boleh menjatuhkan seluruh layar — tetapi hitungannya harus
     * ikut menyusut.
     *
     * Kepala halaman yang menjumlahkan tiga di atas tabel berisi dua membuat operator mencari baris
     * yang tidak akan pernah ia temukan, dan pencarian itu berakhir dengan ia menyimpulkan layarnya
     * berbohong — yang benar, dan itulah masalahnya.
     */
    public function test_a_malformed_row_is_dropped_and_the_header_count_shrinks_with_it(): void
    {
        Http::fake([self::BASE_URL.'/api/internal/v1/fleet' => Http::response([
            'platform_fingerprint' => '2026_09_12_100000_tambah_kolom',
            'counts' => ['current' => 2, 'behind' => 0, 'failed' => 0, 'unknown' => 0],
            'environments' => [
                ['id' => 'env-satu', 'name' => 'Produksi', 'state' => 'current'],
                ['name' => 'Tanpa id sama sekali', 'state' => 'current'],
            ],
        ])]);

        $response = $this->actingAs($this->operator())->get('/pembaruan');

        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('environments', 1)
            ->where('counts.current', 1)
        );
    }

    /**
     * Keadaan yang tidak dikenali jatuh ke `unknown`, bukan ke `current`.
     *
     * Memanggilnya mutakhir berarti layar ini menenangkan operator tentang sesuatu yang tidak ia
     * ketahui — dan lencana hijau adalah cara paling efektif untuk membuat orang berhenti bertanya.
     */
    public function test_an_unrecognised_state_never_reads_as_up_to_date(): void
    {
        Http::fake([self::BASE_URL.'/api/internal/v1/fleet' => Http::response([
            'platform_fingerprint' => '2026_09_12_100000_tambah_kolom',
            'environments' => [
                ['id' => 'env-satu', 'name' => 'Produksi', 'state' => 'sesuatu-yang-baru'],
            ],
        ])]);

        $response = $this->actingAs($this->operator())->get('/pembaruan');

        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('environments.0.state', 'unknown')
            ->where('counts.unknown', 1)
            ->where('counts.current', 0)
        );
    }

    /**
     * Tanpa `Http::fake`: kalau penjaganya bocor, panggilan ke Core menjadi permintaan nyasar dan
     * `preventStrayRequests` menggagalkan test dengan berisik. Diamnya jaringan di sini bagian dari
     * assertion-nya.
     */
    public function test_the_screen_is_closed_to_anyone_who_is_not_an_operator(): void
    {
        $this->get('/pembaruan')->assertRedirect('/login');

        $this->actingAs($this->ordinaryUser())->get('/pembaruan')->assertNotFound();
        $this->actingAs($this->ordinaryUser())->post('/pembaruan')->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function fleet(): array
    {
        return [
            'platform_fingerprint' => '2026_09_12_100000_tambah_kolom',
            'counts' => ['current' => 1, 'behind' => 1, 'failed' => 1, 'unknown' => 0],
            'environments' => [
                [
                    'id' => 'env-satu',
                    'tenant' => 'PT Contoh',
                    'name' => 'Produksi',
                    'slug' => 'produksi',
                    'kind' => 'production',
                    'status' => 'active',
                    'database' => null,
                    'fingerprint' => '2026_09_12_100000_tambah_kolom',
                    'state' => 'current',
                    'last_operation' => null,
                ],
                [
                    'id' => 'env-dua',
                    'tenant' => 'PT Contoh',
                    'name' => 'Peragaan',
                    'slug' => 'peragaan',
                    'kind' => 'demo',
                    'status' => 'active',
                    'database' => 'env_contoh_peragaan_abc1234567',
                    'fingerprint' => '2026_09_01_090000_awal',
                    'state' => 'behind',
                    'last_operation' => [
                        'kind' => 'provision',
                        'status' => 'succeeded',
                        'step' => 'aktifkan',
                        'reason' => null,
                        'started_at' => '2026-09-11 08:00:00',
                        'finished_at' => '2026-09-11 08:00:12',
                        'requested_by' => 'Operator Satu',
                    ],
                ],
                [
                    'id' => 'env-tiga',
                    'tenant' => 'PT Lain',
                    'name' => 'Sandbox',
                    'slug' => 'sandbox',
                    'kind' => 'sandbox',
                    'status' => 'maintenance',
                    'database' => 'env_lain_sandbox_def7654321',
                    'fingerprint' => '2026_09_01_090000_awal',
                    'state' => 'failed',
                    'last_operation' => [
                        'kind' => 'migrate',
                        'status' => 'failed',
                        'step' => 'migration-core',
                        'reason' => 'relation "users" already exists',
                        'started_at' => '2026-09-12 09:00:00',
                        'finished_at' => '2026-09-12 09:00:04',
                        'requested_by' => null,
                    ],
                ],
            ],
        ];
    }

    private function operator(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator Uji',
            'email' => Str::lower(Str::random(8)).'@contoh.test',
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

    private function ordinaryUser(): User
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
}
