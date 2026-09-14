<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Tenant;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\ControlPlane\EnvironmentAddress;
use App\Support\ControlPlane\EnvironmentConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Alamat menentukan lingkungan, dan alamat yang bukan alamat lingkungan tidak mengubah apa pun.
 *
 * Yang paling penting dijaga di sini bukan jalur yang berhasil melainkan **jalur yang tidak
 * menyala**: seluruh penempatan on-prem, seluruh lingkungan pengembangan, dan seluruh test suite
 * yang sudah ada berjalan tanpa `COREERP_BASE_DOMAIN`. Kalau middleware ini ikut campur di sana,
 * yang rusak bukan fitur baru melainkan segalanya yang sudah jalan.
 */
class EnvironmentAddressTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Alamat', 'slug' => 'uji-alamat', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Alamat',
            'slug' => 'ujialamat',
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------ membangun dan mengurai

    public function test_production_carries_no_kind_label(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertSame(
            'ivs.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'production'),
        );
    }

    public function test_other_than_production_carries_its_kind_label(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertSame(
            'ivs.sandbox.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'sandbox'),
        );
    }

    public function test_a_hyphenated_tenant_slug_stays_whole(): void
    {
        // `uniqueSlug()` meng-slugify nama badan hukum, jadi "PT Sinar Abadi" menjadi
        // `pt-sinar-abadi`. Tenant dan jenis kini dipisah titik, jadi tanda hubung di dalam slug
        // tidak pernah dapat dibaca sebagai pemisah.
        config(['coreerp.base_domain' => 'erp.contoh.co.id']);

        $address = EnvironmentAddress::fromHost('pt-sinar-abadi.demo.erp.contoh.co.id');

        $this->assertNotNull($address);
        $this->assertSame('pt-sinar-abadi', $address->tenant);
        $this->assertSame('demo', $address->kind);
    }

    public function test_what_is_built_can_be_read_back(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        foreach (['production', 'demo', 'sandbox'] as $kind) {
            $host = EnvironmentAddress::forEnvironment('pt-sinar-abadi', $kind);
            $this->assertNotNull($host);

            $parsedBack = EnvironmentAddress::fromHost($host);

            $this->assertNotNull($parsedBack, $host);
            $this->assertSame('pt-sinar-abadi', $parsedBack->tenant);
            $this->assertSame($kind, $parsedBack->kind);
        }
    }

    public function test_the_port_does_not_get_in_the_way(): void
    {
        // `pelanggan.demo.erp.localhost:8000` adalah bentuk yang dipakai selama pengembangan.
        config(['coreerp.base_domain' => 'erp.localhost']);

        $address = EnvironmentAddress::fromHost('ivs.demo.erp.localhost:8000');

        $this->assertNotNull($address);
        $this->assertSame('ivs', $address->tenant);
    }

    // ------------------------------------------------------------------ jalur yang tidak menyala

    public function test_without_a_base_domain_nothing_is_parsed(): void
    {
        config(['coreerp.base_domain' => null]);

        $this->assertNull(EnvironmentAddress::fromHost('apa.pun.contoh.co.id'));
        $this->assertNull(EnvironmentAddress::forEnvironment('ivs', 'production'));
    }

    public function test_the_root_address_is_not_an_environment_address(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertNull(EnvironmentAddress::fromHost('contoh.co.id'));
    }

    public function test_another_domain_is_never_recognised(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertNull(EnvironmentAddress::fromHost('ivs.penyerang.co.id'));
        // Akhiran yang mirip tetapi bukan subdomain. Tanpa titik pemisah, `jahatcontoh.co.id`
        // akan lolos pemeriksaan yang hanya memakai `str_ends_with` atas domainnya saja.
        $this->assertNull(EnvironmentAddress::fromHost('jahatcontoh.co.id'));
    }

    public function test_three_labels_is_not_a_shape_we_ever_printed(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertNull(EnvironmentAddress::fromHost('a.b.c.contoh.co.id'));
        // Bentuk yang ditimbang dan ditolak: slug lingkungan sebagai label sendiri. Setiap nama
        // lingkungan akan menuntut sertifikat wildcard baru.
        $this->assertNull(EnvironmentAddress::fromHost('ivs.peragaan.demo.contoh.co.id'));
    }

    /** Label kedua hanya boleh jenis yang memang berlabel — bukan karangan, bukan produksi. */
    public function test_only_labelled_kinds_are_read_as_a_kind(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->assertNull(EnvironmentAddress::fromHost('ivs.uat.contoh.co.id'));
        $this->assertNull(EnvironmentAddress::fromHost('ivs.production.contoh.co.id'));
        $this->assertNull(EnvironmentAddress::fromHost('.demo.contoh.co.id'));
    }

    // ------------------------------------------------------------------ middleware

    public function test_an_ordinary_request_is_not_affected_at_all(): void
    {
        config(['coreerp.base_domain' => null]);

        $this->get('/login')->assertOk();
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    public function test_an_active_environment_address_binds_its_identity(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $environment = $this->environment('demo', 'peragaan', 'active');

        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertOk();

        $this->assertTrue(app()->bound(ActiveEnvironment::KEY));
        $this->assertSame($environment->id, app(ActiveEnvironment::KEY));
    }

    public function test_an_environment_that_is_not_active_yet_answers_404(): void
    {
        // Hanya `active` yang boleh dirutekan. Melayaninya dengan database bawaan adalah cara
        // termurah memperlihatkan data tenant lain.
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->environment('demo', 'belumjadi', 'provisioning');

        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertNotFound();
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    public function test_an_address_that_points_at_no_environment_answers_404(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        // 404 dan bukan 403: keberadaan sebuah lingkungan adalah informasi, dan 403 memberi tahu
        // penanya bahwa pelanggan itu memang punya demo.
        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertNotFound();
    }

    public function test_the_wrong_kind_in_the_address_finds_nothing(): void
    {
        // Lingkungannya ada, tetapi berjenis demo. Alamat sandbox tidak boleh menemukannya —
        // kalau bisa, label jenis pada domain berhenti berarti apa pun.
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->environment('demo', 'peragaan', 'active');

        $this->get('http://ujialamat.sandbox.contoh.co.id/login')->assertNotFound();
    }

    /**
     * Alamat bentuk lama, `<tenant>--<lingkungan>.<jenis>.…`, tidak lagi membuka apa pun.
     *
     * Ia terurai sebagai tenant bernama `ujialamat--peragaan` — slug yang tidak pernah dapat lahir,
     * karena `Str::slug()` tidak menghasilkan dua tanda hubung berurutan — dan dijawab 404.
     */
    public function test_the_old_double_hyphen_address_no_longer_opens_anything(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->environment('demo', 'peragaan', 'active');

        $this->get('http://ujialamat--peragaan.demo.contoh.co.id/login')->assertNotFound();
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    /** Demo yang dihapus lunak tidak memegang alamatnya; demo hidup yang menggantikannya yang dilayani. */
    public function test_a_soft_deleted_demo_does_not_hold_the_address(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $old = $this->environment('demo', 'lama', 'active');
        $old->forceFill(['status' => 'soft_deleted', 'deleted_at' => now(), 'purge_after' => now()->addWeek()])->save();
        $living = $this->environment('demo', 'baru', 'active');

        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertOk();

        $this->assertSame($living->id, app(ActiveEnvironment::KEY));
    }

    /**
     * Dua demo hidup untuk satu tenant adalah keadaan yang tidak dapat diwakili.
     *
     * Alamatnya hanya memuat tenant dan jenis, jadi keduanya akan menunjuk satu alamat dan yang
     * dilayani ditentukan urutan baris. Yang menolaknya database, bukan kode yang harus diingat.
     */
    public function test_the_database_refuses_a_second_living_demo_or_sandbox(): void
    {
        foreach (['demo', 'sandbox'] as $kind) {
            $this->environment($kind, $kind.'-pertama', 'active');

            try {
                // Savepoint: PostgreSQL membatalkan seluruh transaksi test begitu satu pernyataan
                // ditolak, dan pernyataan berikutnya di test ini masih harus berjalan.
                DB::transaction(fn () => $this->environment($kind, $kind.'-kedua', 'active'));
                $this->fail(sprintf('%s kedua untuk tenant yang sama tidak boleh tersimpan.', $kind));
            } catch (UniqueConstraintViolationException $e) {
                $this->assertStringContainsString('environments_satu_per_jenis', $e->getMessage());
            }
        }

        // Tenant lain tidak terhalang.
        $other = Tenant::create([
            'client_id' => $this->tenant->client_id,
            'name' => 'PT Tetangga',
            'slug' => 'tetangga',
            'status' => 'active',
        ]);
        Environment::create([
            'tenant_id' => $other->id, 'kind' => 'demo', 'name' => 'Demo', 'slug' => 'demo-pertama',
            'database_name' => null, 'status' => 'active', 'outbound_allowed' => false, 'expires_at' => now()->addMonth(),
        ]);

        $this->assertSame(3, Environment::query()->count());
    }

    public function test_an_environment_shaped_address_that_does_not_parse_answers_404(): void
    {
        // Dengan DNS wildcard, setiap label yang pernah diketik siapa pun sampai ke sini.
        // Menyajikan aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari alamat
        // mana saja yang dikarang orang.
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->get('http://karangan.orang.contoh.co.id/login')->assertNotFound();
        $this->get('http://karangan-orang.demo.contoh.co.id/login')->assertNotFound();
    }

    public function test_the_console_label_is_not_required_to_point_at_an_environment(): void
    {
        // Konsol operator dan alamat pemasaran hidup di bawah domain yang sama dan berbentuk satu
        // label — persis bentuk alamat produksi. Tanpa pengecualian ini, konsolnya mati dengan 404
        // yang tidak menyebut sebabnya sama sekali.
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->get('http://admin.contoh.co.id/login')->assertOk();
        $this->get('http://www.contoh.co.id/login')->assertOk();
    }

    // ------------------------------------------------------------------ pemilihan database

    /**
     * Lingkungan yang memang tinggal di database pusat tidak menggeser apa pun.
     *
     * Ini jalur yang dilalui seluruh perilaku hari ini — produksi lahir dengan `database_name`
     * kosong, dan kosong berarti database bawaan. Test ini yang menjaga bahwa menyambungkan
     * pemilih koneksi tidak mengubah satu pun permintaan yang sudah bekerja.
     */
    public function test_an_environment_on_the_control_plane_database_switches_nothing(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->environment('production', 'ujialamat', 'active');

        $before = config('database.default');

        $this->get('http://ujialamat.contoh.co.id/login')->assertOk();

        $this->assertSame($before, config('database.default'));
        $this->assertNull(config('session.connection'), 'Tidak ada yang digeser, jadi tidak ada yang perlu dipaku.');
        $this->assertSame('', (string) config('coreerp.control_connection'));
    }

    /**
     * Pemilihnya sendiri: apa yang digeser, dan apa yang justru dipaku.
     *
     * Diuji tanpa permintaan HTTP dan tanpa menyentuh database mana pun, dan itu bukan pemalasan
     * melainkan satu-satunya bentuk yang bisa berjalan. Percobaan pertama membuat permintaan
     * sungguhan ke lingkungan yang `database_name`-nya menunjuk database test ini sendiri — dan
     * **menggantung**: koneksi kedua berada di luar transaksi milik `RefreshDatabase`, sehingga
     * setiap query darinya menunggu kunci tabel yang dipegang transaksi itu, selamanya.
     *
     * Yang dijaga di sini pakuannya, bukan pergeserannya. Pergeseran tanpa pakuan adalah kegagalan
     * yang paling sulit dibaca: sesi tidak pernah bertahan, pembatas laju login menghitung per
     * lingkungan, dan job menunggu di tabel yang tidak dibaca siapa pun. Keempatnya dibaca Laravel
     * lewat kunci config, bukan lewat Eloquent — jadi trait `OwnedByControlPlane` tidak menjangkau
     * satu pun dari mereka.
     */
    public function test_choosing_a_database_pins_every_control_plane_store(): void
    {
        $controlPlane = (string) config('database.default');

        $environment = $this->environment('demo', 'punyasendiri', 'active');
        $environment->forceFill(['database_name' => 'env_punya_sendiri_0000000000'])->save();

        app(EnvironmentConnection::class)->useForRequest($environment);

        $this->assertSame('environment_'.$environment->id, config('database.default'));

        foreach ([
            'coreerp.control_connection',
            'session.connection',
            'cache.stores.database.connection',
            'cache.stores.database.lock_connection',
            'queue.connections.database.connection',
            'auth.passwords.users.connection',
        ] as $pin) {
            $this->assertSame(
                $controlPlane,
                config($pin),
                sprintf('`%s` harus tetap menunjuk database pusat.', $pin),
            );
        }

        // Dipulihkan sendiri supaya test berikutnya tidak mewarisi koneksi yang digeser.
        // `useForRequest` sengaja tidak punya blok penutup — yang menutupnya berakhirnya permintaan.
        config(['database.default' => $controlPlane, 'coreerp.control_connection' => null]);
    }

    /**
     * Dan middleware-nya benar-benar memanggilnya.
     *
     * Dua test di atas membuktikan pemilihnya benar dan permintaan biasa tidak tersentuh; tidak
     * satu pun dari keduanya membuktikan bahwa keduanya tersambung. Tanpa test ini, memutus
     * panggilannya dari middleware akan meninggalkan suite yang seluruhnya hijau dan permintaan
     * yang seluruhnya dilayani database pusat.
     */
    public function test_the_middleware_hands_the_environment_to_the_chooser(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $environment = $this->environment('demo', 'tersambung', 'active');

        $recorded = [];
        $this->app->instance(EnvironmentConnection::class, new class($recorded) extends EnvironmentConnection
        {
            /** @param  array<int, string>  $recorded */
            public function __construct(private array &$recorded) {}

            public function useForRequest(Environment $environment): void
            {
                $this->recorded[] = $environment->id;
            }
        });

        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertOk();

        $this->assertSame([$environment->id], $recorded);
    }

    /**
     * Lingkungan yang pernah hidup dijawab 503, bukan 404.
     *
     * Pasangan merahnya ada di atas — lingkungan yang belum pernah punya skema tetap 404, karena
     * keberadaan sebuah demo adalah informasi. Begitu ia pernah berdiri, pemiliknya sudah tahu ia
     * ada; menyembunyikannya tidak melindungi apa pun dan membuat gangguan terbaca seperti salah
     * ketik alamat.
     */
    public function test_an_environment_that_once_lived_answers_503(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $environment = $this->environment('demo', 'pernahhidup', 'active');
        $environment->forceFill([
            'status' => 'maintenance',
            'database_name' => 'env_pernah_hidup_0000000000',
            'schema_migrated_at' => now()->subDay(),
        ])->save();

        $this->get('http://ujialamat.demo.contoh.co.id/login')
            ->assertStatus(503);

        // Tidak ada yang digeser: permintaannya ditolak sebelum pemilih koneksi dipanggil, dan
        // itu memang urutan yang benar — lingkungan yang tidak boleh dimasuki tidak boleh
        // meninggalkan jejak koneksi pada permintaan yang menolaknya.
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    /** Pasangan merah dari yang di atas: belum pernah punya skema berarti tetap 404. */
    public function test_an_environment_that_never_lived_answers_404_even_when_degraded(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $environment = $this->environment('demo', 'gagalsiap', 'active');
        $environment->forceFill(['status' => 'degraded', 'schema_migrated_at' => null])->save();

        $this->get('http://ujialamat.demo.contoh.co.id/login')->assertNotFound();
    }

    private function environment(string $kind, string $slug, string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Uji '.$slug,
            'slug' => $slug,
            'database_name' => null,
            'status' => $status,
            'outbound_allowed' => $kind === 'production',
            'expires_at' => $kind === 'demo' ? now()->addMonth() : null,
        ]);
    }
}
