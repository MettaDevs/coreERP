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
 * migration sungguhan, module sungguhan — sudah dibuktikan {@see SiapkanLingkunganTest} pada
 * perintahnya langsung. Mengulanginya lewat HTTP hanya menambah satu menit pada suite tanpa
 * menambah satu pun keterangan.
 *
 * Yang **tidak** boleh diulang di sana dan karena itu ada di sini: bahwa penjaganya benar-benar
 * menutup, bahwa keadaan yang salah dijawab kode yang benar, dan bahwa tidak satu pun dari
 * penolakan itu meninggalkan operasi menggantung. Sebuah rute yang menolak tetapi sempat membuka
 * kunci operasi akan memblokir percobaan berikutnya selama tiga puluh menit.
 */
final class PenyiapanLingkunganLewatApiTest extends TestCase
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

    public function test_tanpa_token_ditolak_dan_tidak_membuka_operasi(): void
    {
        $lingkungan = $this->lingkungan('provisioning');

        $this->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan')
            ->assertUnauthorized();

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $lingkungan->refresh()->status);
    }

    /**
     * Pemasangan yang memang tidak punya pusat admin menolak semua orang.
     *
     * Jalur merah yang paling mudah ditulis terbalik: string kosong sama dengan string kosong, jadi
     * penjaga yang hanya membandingkan keduanya membuka rute ini pada setiap pemasangan on-prem.
     */
    public function test_pemasangan_tanpa_token_menolak_bahkan_token_kosong(): void
    {
        config()->set('coreerp.control_plane_token', null);
        $lingkungan = $this->lingkungan('provisioning');

        $this->withToken('')->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan')
            ->assertUnauthorized();
        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan')
            ->assertUnauthorized();

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_lingkungan_yang_tidak_ada_dijawab_404(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/01jbukanlingkunganapapun00/siapkan')
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
    public function test_lingkungan_yang_sudah_aktif_ditolak_409(): void
    {
        $lingkungan = $this->lingkungan('active');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan')
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $pesan): bool => str_contains($pesan, 'active'));

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame('active', $lingkungan->refresh()->status);
    }

    public function test_lingkungan_yang_sudah_dihapus_lunak_dijawab_404(): void
    {
        $lingkungan = $this->lingkungan('provisioning');
        DB::table('environments')->where('id', $lingkungan->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now(),
            'purge_after' => now()->addMonth(),
        ]);

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan')
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
    public function test_peminta_yang_tidak_ada_tidak_menggagalkan_permintaannya(): void
    {
        $lingkungan = $this->lingkungan('active');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$lingkungan->id.'/siapkan', ['diminta_oleh' => 999999])
            ->assertStatus(409);
    }

    /** Pasangan hijaunya: operator yang sungguhan memang sampai ke kolom riwayat. */
    public function test_peminta_yang_ada_tercatat_pada_operasinya(): void
    {
        $operator = User::create([
            'name' => 'Operator Uji',
            'email' => 'operator.api@contoh.test',
            'password' => bcrypt('rahasia'),
        ]);

        // Bukan lewat HTTP: yang diuji di sini penyambungannya sampai ke baris operasi, dan
        // menjalankan seluruh penyiapan sungguhan hanya untuk membaca satu kolom berarti membayar
        // satu database baru per assertion.
        $lingkungan = $this->lingkungan('provisioning');

        // `int`, bukan `(string)`. Itu bentuk yang benar-benar dikirim `Artisan::call()` dari
        // controller internal, dan versi pertama perintah ini hanya menerima string — sehingga
        // penyiapan lewat tombol berhasil sepenuhnya sambil mencatat "Sistem". Test yang memanggil
        // dengan `(string)` ikut setuju dengan cacatnya.
        $this->artisan('environment:siapkan', [
            'environment' => $lingkungan->id,
            '--diminta-oleh' => $operator->id,
        ])->run();

        $operasi = EnvironmentOperation::query()->where('environment_id', $lingkungan->id)->firstOrFail();

        $this->assertSame($operator->id, $operasi->requested_by);

        $this->buangDatabase($lingkungan);
    }

    private function lingkungan(string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'demo',
            'name' => 'Peragaan',
            'slug' => 'peragaan-'.substr(md5(uniqid('', true)), 0, 8),
            'database_name' => null,
            'status' => $status,
            'expires_at' => now()->addDays(30),
            'outbound_allowed' => false,
        ]);
    }

    /**
     * Membuang database yang benar-benar dibuat test di atas.
     *
     * PDO terpisah karena `DROP DATABASE` dilarang di dalam transaksi, dan `RefreshDatabase`
     * memegang satu. `WITH (FORCE)` memutus sesi yang masih menempel; tanpa itu satu koneksi yang
     * lupa ditutup cukup untuk meninggalkan database yatim di mesin siapa pun yang menjalankannya.
     */
    private function buangDatabase(Environment $lingkungan): void
    {
        $nama = $lingkungan->refresh()->database_name;

        if (! is_string($nama) || $nama === '') {
            return;
        }

        DB::purge('lingkungan_disiapkan');
        DB::purge('lingkungan_'.$lingkungan->id);

        config(['database.connections.uji_pembuang' => config('database.connections.'.config('database.default'))]);
        DB::purge('uji_pembuang');
        DB::connection('uji_pembuang')->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $nama));
        DB::purge('uji_pembuang');
    }
}
