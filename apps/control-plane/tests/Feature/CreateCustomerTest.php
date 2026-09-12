<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as OutboundRequest;
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
class CreateCustomerTest extends TestCase
{
    use CoreSchema;

    private const BASE_URL = 'http://core.uji:8000';

    private const ENDPOINT = self::BASE_URL.'/api/internal/v1/tenants';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.base_url' => self::BASE_URL, 'core.token' => 'kunci-uji']);

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

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'nama_badan_hukum' => 'PT Sumber Sehat Nusantara',
            'nama_admin' => 'Siti Rahmawati',
            'email_admin' => 'siti@sumbersehat.test',
            'app_ids' => ['hr'],
        ];
    }

    private function error(string $key): string
    {
        $bag = session('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $bag);

        return $bag->first($key);
    }

    private function tenant(string $name): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    public function test_the_temporary_password_really_reaches_the_screen(): void
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

        $this->post('/pelanggan', $this->input())->assertRedirect('/pelanggan');

        Http::assertSent(fn (OutboundRequest $request): bool => $request->url() === self::ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer kunci-uji')
            && $request['nama_badan_hukum'] === 'PT Sumber Sehat Nusantara'
            && $request['nama_admin'] === 'Siti Rahmawati'
            && $request['email_admin'] === 'siti@sumbersehat.test'
            && $request['app_ids'] === ['hr']);

        $this->get('/pelanggan')
            ->assertOk()
            ->assertSee('Gerbang-Sore-4417')
            ->assertSee('siti@sumbersehat.test');
    }

    public function test_the_temporary_password_does_not_appear_on_the_next_load(): void
    {
        Http::fake(['*' => Http::response([
            'tenant_id' => '01JTENANTUJI0000000000000',
            'email' => 'siti@sumbersehat.test',
            'kata_sandi_sementara' => 'Gerbang-Sore-4417',
        ], 201)]);

        $this->actingAs($this->operator());
        $this->post('/pelanggan', $this->input())->assertRedirect('/pelanggan');

        $this->get('/pelanggan')->assertSee('Gerbang-Sore-4417');

        // Sekali, dan benar-benar sekali. Kata sandi yang masih terbaca besok adalah kata sandi
        // yang tersimpan di suatu tempat — dan tempat itu akan ditemukan orang lain.
        $this->get('/pelanggan')->assertOk()->assertDontSee('Gerbang-Sore-4417');
    }

    public function test_a_core_rejection_becomes_an_error_on_the_field_it_points_to(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Data yang diberikan tidak sah.',
            'errors' => ['email_admin' => ['Email ini sudah terdaftar.']],
        ], 422)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->input())
            // Galat formulir, bukan halaman 500. Keduanya berarti "ditolak", tetapi hanya yang
            // pertama yang menyebutkan apa yang harus diperbaiki — dan di isian mana.
            ->assertSessionHasErrors(['email_admin' => 'Email ini sudah terdaftar.']);
    }

    public function test_an_unavailable_app_is_rejected_by_core_not_by_the_console(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Data yang diberikan tidak sah.',
            'errors' => ['app_ids' => ['App "hr" tidak tersedia di edisi ini.']],
        ], 422)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->input())
            ->assertSessionHasErrors(['app_ids' => 'App "hr" tidak tersedia di edisi ini.']);

        // Konsol tetap mengirimkannya. Menyaring lebih dulu di sini berarti dua daftar app yang
        // harus tetap sama, dan yang kedua akan basi lebih dulu.
        Http::assertSentCount(1);
    }

    public function test_a_wrong_key_names_the_setting_that_must_be_fixed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->input())
            ->assertSessionHasErrors('core');

        // "Unauthenticated." adalah jawaban bawaan Laravel, dan ia tidak memberi tahu siapa pun
        // bahwa yang harus disunting adalah setelan di konsol ini.
        $message = $this->error('core');
        $this->assertStringContainsString('CONTROL_PLANE_TOKEN', $message);
        $this->assertStringContainsString(self::ENDPOINT, $message);
    }

    public function test_an_unreachable_core_names_the_address_that_was_tried(): void
    {
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 6: Could not resolve host: core.uji',
        ));

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->input())
            ->assertSessionHasErrors('core');

        $message = $this->error('core');

        // Sebab paling sering adalah COREERP_URL salah setel, dan ia hanya dapat terlihat kalau
        // alamat yang dicoba ikut tertulis. Galat aslinya juga tidak ditelan.
        $this->assertStringContainsString(self::ENDPOINT, $message);
        $this->assertStringContainsString('COREERP_URL', $message);
        $this->assertStringContainsString('Could not resolve host', $message);
    }

    public function test_a_response_without_a_password_does_not_pass_silently(): void
    {
        // 2xx yang bentuknya tidak sesuai kontrak adalah keadaan paling berbahaya di alur ini:
        // pelanggannya mungkin sudah lahir, dan satu-satunya salinan kata sandinya baru hilang.
        Http::fake(['*' => Http::response(['tenant_id' => '01JTENANTUJI0000000000000'], 201)]);

        $this->actingAs($this->operator())
            ->post('/pelanggan', $this->input())
            ->assertSessionHasErrors('core');

        $message = $this->error('core');
        $this->assertStringContainsString(self::ENDPOINT, $message);
        $this->assertStringContainsString('periksa daftar pelanggan', $message);
    }

    public function test_empty_input_is_rejected_before_core_is_called(): void
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

    public function test_a_non_operator_sees_nothing(): void
    {
        Http::fake();

        // 404, bukan 403. Pengguna biasa tidak perlu tahu alamat ini ada.
        $this->actingAs($this->ordinaryUser())->get('/pelanggan')->assertNotFound();

        $this->actingAs($this->ordinaryUser())
            ->post('/pelanggan', $this->input())
            ->assertNotFound();

        // Penjaga yang menolak halamannya tetapi membiarkan panggilannya keluar adalah penjaga
        // yang tidak menjaga apa pun.
        Http::assertNothingSent();
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/pelanggan')->assertRedirect('/login');
    }

    public function test_the_index_counts_environments_that_are_still_alive(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant('PT Punya Dua');

        foreach (['Uji A', 'Uji B', 'Sudah Dibuang'] as $name) {
            Environment::query()->create([
                'tenant_id' => $tenant,
                'kind' => 'sandbox',
                'name' => $name,
                'slug' => Str::slug($name),
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
            ->assertSee('"environments":2', false);
    }

    public function test_only_available_apps_are_offered(): void
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
