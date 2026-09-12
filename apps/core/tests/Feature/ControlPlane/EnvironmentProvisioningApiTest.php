<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pintu kedua pusat admin: menyalakan sebuah lingkungan tanpa membuka terminal.
 *
 * Yang dibuktikan di sini terutama jalur merahnya, karena jalur hijaunya — database sungguhan,
 * migration sungguhan, module sungguhan — sudah dibuktikan {@see ProvisionEnvironmentTest} pada
 * perintahnya langsung. Mengulanginya lewat HTTP hanya menambah satu menit pada suite tanpa
 * menambah satu pun keterangan.
 *
 * Yang **tidak** boleh diulang di sana dan karena itu ada di sini: bahwa penjaganya benar-benar
 * menutup, bahwa keadaan yang salah dijawab kode yang benar, dan bahwa tidak satu pun dari
 * penolakan itu meninggalkan operasi menggantung. Sebuah rute yang menolak tetapi sempat membuka
 * kunci operasi akan memblokir percobaan berikutnya selama tiga puluh menit.
 */
final class EnvironmentProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-pusat-admin-uji';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coreerp.control_plane_token', self::TOKEN);

        $client = Client::create(['legal_name' => 'PT Uji Api', 'slug' => 'uji-api', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Api',
            'slug' => 'uji-api',
            'status' => 'active',
        ]);
    }

    public function test_without_a_token_it_is_rejected_and_opens_no_operation(): void
    {
        $environment = $this->environment('provisioning');

        $this->postJson('/api/internal/v1/environments/'.$environment->id.'/provision')
            ->assertUnauthorized();

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $environment->refresh()->status);
    }

    /**
     * Pemasangan yang memang tidak punya pusat admin menolak semua orang.
     *
     * Jalur merah yang paling mudah ditulis terbalik: string kosong sama dengan string kosong, jadi
     * penjaga yang hanya membandingkan keduanya membuka rute ini pada setiap pemasangan on-prem.
     */
    public function test_an_installation_without_a_token_refuses_even_an_empty_token(): void
    {
        config()->set('coreerp.control_plane_token', null);
        $environment = $this->environment('provisioning');

        $this->withToken('')->postJson('/api/internal/v1/environments/'.$environment->id.'/provision')
            ->assertUnauthorized();
        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/environments/'.$environment->id.'/provision')
            ->assertUnauthorized();

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_an_environment_that_does_not_exist_is_answered_404(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/01jbukanlingkunganapapun00/provision')
            ->assertNotFound();

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    /**
     * 409, bukan 422, dan bukan pula penyiapan yang diam-diam berjalan.
     *
     * Menyiapkan ulang lingkungan yang sudah hidup adalah cara termurah menimpa data pelanggan, dan
     * satu huruf salah ketik pada id sudah cukup untuk sampai ke sana. Yang dijaga assertion di
     * bawah bukan kodenya melainkan akibatnya: tidak ada operasi yang dibuka sama sekali.
     */
    public function test_an_environment_that_is_already_active_is_rejected_409(): void
    {
        $environment = $this->environment('active');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$environment->id.'/provision')
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'active'));

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame('active', $environment->refresh()->status);
    }

    public function test_an_environment_that_is_already_soft_deleted_is_answered_404(): void
    {
        $environment = $this->environment('provisioning');
        DB::table('environments')->where('id', $environment->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now(),
            'purge_after' => now()->addMonth(),
        ]);

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$environment->id.'/provision')
            ->assertNotFound();
    }

    /**
     * Id peminta yang tidak ada tidak boleh menjatuhkan penyiapannya.
     *
     * `requested_by` berkunci asing ke `users`. Meneruskan id apa adanya berarti sebuah nama yang
     * salah di kolom riwayat menggagalkan pembuatan database — menukar pekerjaan yang berhasil
     * dengan kegagalan demi keterangan tambahan.
     *
     * Diuji lewat penolakan 409 supaya ia tidak ikut membuat database sungguhan: yang dibuktikan
     * adalah bahwa muatan itu diterima tanpa meledak, bukan bahwa penyiapannya berjalan.
     */
    public function test_a_requester_that_does_not_exist_does_not_fail_the_request(): void
    {
        $environment = $this->environment('active');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$environment->id.'/provision', ['requested_by' => 999999])
            ->assertStatus(409);
    }

    /**
     * Pasangan hijaunya: operator yang sungguhan memang sampai ke kolom riwayat.
     *
     * Dijalankan lewat `environment:upgrade`, bukan `environment:provision`, dan bedanya bukan
     * selera. Keduanya menempuh protokol kunci yang sama — `HoldsEnvironmentOperation`, tempat
     * `requested_by` diisi — tetapi yang kedua **membuat database sungguhan**, dan versi pertama
     * test ini membayarnya hanya untuk membaca satu kolom.
     *
     * Ongkosnya bukan cuma waktu. Pembersihannya (`DROP DATABASE ... WITH (FORCE)`) gagal dengan
     * *"permission denied to terminate process"* ketika beberapa kelas test berjalan dalam satu
     * proses: sebuah backend masih menempel pada database itu, dan peran aplikasi tidak boleh
     * membunuh backend milik peran lain. Test ini karena itu **hijau sendirian dan merah
     * bersama-sama** — bentuk kegagalan yang paling mahal dicari.
     *
     * `environment:upgrade` atas lingkungan yang tinggal di database pusat tidak membuat apa pun,
     * jadi tidak ada yang perlu dibersihkan sama sekali.
     *
     * `int`, bukan `(string)`: itu bentuk yang benar-benar dikirim `Artisan::call()` dari controller
     * internal, dan versi pertama pembacanya hanya menerima string — sehingga pekerjaannya berhasil
     * sepenuhnya sambil mencatat "Sistem". Test yang memanggil dengan `(string)` ikut setuju.
     */
    public function test_a_requester_that_exists_is_recorded_on_its_operation(): void
    {
        $operator = User::create([
            'name' => 'Operator Uji',
            'email' => 'operator.api@contoh.test',
            'password' => bcrypt('rahasia'),
        ]);

        // `production`: satu-satunya jenis yang memang wajar tinggal di database pusat, dan
        // karena itu satu-satunya yang dapat diperbarui tanpa membuat database apa pun.
        $environment = $this->environment('active', 'production');

        $this->artisan('environment:upgrade', [
            'environment' => $environment->id,
            '--requested-by' => $operator->id,
        ])->assertExitCode(0);

        $operation = EnvironmentOperation::query()->where('environment_id', $environment->id)->sole();

        $this->assertSame($operator->id, $operation->requested_by);
    }

    private function environment(string $status, string $kind = 'demo'): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Peragaan',
            'slug' => 'peragaan-'.substr(md5(uniqid('', true)), 0, 8),
            'database_name' => null,
            'status' => $status,
            'expires_at' => $kind === 'demo' ? now()->addDays(30) : null,
            'outbound_allowed' => $kind === 'production',
        ]);
    }
}
