<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Suite ini tidak boleh menyentuh schema kerja.
     *
     * Trait penyiap database milik Laravel mengosongkan schema yang sedang aktif sebelum tiap test
     * — `RefreshDatabase` lewat `migrate:fresh`, `DatabaseTruncation` lewat TRUNCATE. Selama
     * koneksinya `pgsql_test` dengan `search_path` tersendiri, itu tidak berbahaya. Begitu sebuah
     * test berpindah ke koneksi bawaan, mekanisme yang sama mengosongkan database kerja pengembang
     * tanpa satu pun peringatan: yang terlihat hanyalah suite yang hijau, lalu stack lokal yang
     * tiba-tiba kosong.
     *
     * Sudah terjadi **dua kali**, 11 dan 12 September 2026, dan yang hilang adalah tenant, akun
     * operator, beserta seluruh katalog app di database dev. Jejaknya TRUNCATE bukan drop:
     * `pg_stat_user_tables` mencatat `n_tup_del = 0` dengan `n_live_tup = 0`, seluruh 116 tabel
     * masih berdiri, dan tabel `migrations` justru selamat — persis yang dikecualikan
     * `DatabaseTruncation`.
     *
     * Penyebabnya ditelusuri dan terbukti, bukan ditebak: dengan `LARAVEL_PARALLEL_TESTING`
     * menyala tetapi `TEST_TOKEN` kosong, koneksi test resolve ke database `core_erp` dengan
     * `search_path` `public` — yaitu tempat kerja pengembang sendiri. Sakelar yang tertinggal di
     * lingkungan shell sudah cukup. `config/database.php` kini menutupnya di sumbernya; pemeriksaan
     * di sini lapis keduanya, dan ia yang memberi pesan terbaca.
     *
     * Karena itu yang diperiksa alamatnya, bukan mekanismenya. Ia berdiri sebelum satu baris test
     * pun berjalan, karena kerusakannya tidak dapat dibatalkan — mencetaknya sesudah tidak
     * menolong siapa pun.
     */
    protected function setUp(): void
    {
        // Dibaca dari env, bukan dari `config()`. Aplikasinya belum berdiri di titik ini — dan ia
        // memang tidak boleh berdiri lebih dulu, karena `RefreshDatabase` menumpang
        // `parent::setUp()` di bawah dan sudah mengosongkan schema-nya sebelum baris pertama test
        // manapun sempat memeriksa apa pun.
        $baca = static fn (string $kunci): string => (string) (getenv($kunci) ?: ($_ENV[$kunci] ?? ''));

        $koneksi = $baca('DB_CONNECTION');
        $jalur = $baca('DB_TEST_SCHEMA') ?: 'coreerp_test';
        $paralel = $baca('LARAVEL_PARALLEL_TESTING');
        $token = $baca('TEST_TOKEN');

        if (! str_starts_with($koneksi, 'pgsql_test') || $jalur === 'public') {
            $this->fail(
                'Suite ini menunjuk koneksi "'.$koneksi.'" dengan search_path "'.$jalur.'". '
                .'Test hanya boleh berjalan di schema test — trait penyiap database mengosongkan '
                .'schema yang ditunjuknya, dan tidak ada yang mengembalikan isinya.'
            );
        }

        // Mode paralel tanpa token adalah keadaan yang tidak pernah dimaksudkan siapa pun, dan ia
        // persis yang mengosongkan database dev dua kali. Ditolak terpisah supaya pesannya menyebut
        // sebabnya, bukan sekadar nama schema yang kebetulan sudah benar.
        if ($paralel !== '' && $token === '') {
            $this->fail(
                'LARAVEL_PARALLEL_TESTING menyala tetapi TEST_TOKEN kosong. Di keadaan itu koneksi '
                .'test jatuh ke database kerja pengembang, dan trait penyiap database akan '
                .'mengosongkannya. Cabut sakelar itu dari lingkungan shell, atau jalankan lewat '
                .'`php artisan test --parallel` yang mengisi tokennya sendiri.'
            );
        }

        parent::setUp();

        // Fixture katalog untuk test. Bentuknya sengaja memakai empat lapis
        // Dynamics 365 yang berbeda — entry point, permission, privilege, duty —
        // supaya test membuktikan rantai yang sebenarnya, bukan satu lapis
        // bersalin tiga. Katalog produksi datang dari manifest app, bukan config.
        //
        // **Kenapa idnya `app-uji` dan bukan nama produk.** Sampai 9 September 2026
        // fixture ini memakai id `management-aset`, dan itu berhenti benar pada hari
        // module aset selesai dipindah. Sejak saat itu id yang sama menunjuk dua hal:
        // katalog kecil buatan tangan di sini, dan manifest module yang sungguhan di
        // `modules/apperp/management-aset/app.yaml` dengan 65 entry point, 122
        // permission, dan 29 referensi nomor. Pendaftaran usaha memasang apa pun yang
        // ada sebagai folder module, jadi setiap test yang mendaftarkan usaha mulai
        // memasang module sungguhan di atas katalog palsu — 84 test gagal dengan
        // "Sequence aktif tidak ditemukan", dan tidak satu pun pesannya menyebut
        // katalog.
        //
        // Id yang tidak akan pernah menjadi module memulihkan pembagiannya: yang di
        // sini bahan uji rantai izin, yang di manifest katalog produk. Test yang
        // memang menguji module aset mendaftarkan manifestnya lewat
        // `app:register-manifest`, jalur yang sama dengan yang dijalankan admin
        // on-prem.
        //
        // Fixture ini juga menyimpan satu sifat yang tidak dimiliki manifest
        // sungguhan: sebuah entry point yang tidak masuk privilege maupun duty mana
        // pun. Owner menerima seluruh duty app yang di-entitle, jadi permission yang
        // sudah terpakai di rantai katalog tidak dapat membuktikan apa pun tentang
        // rantai custom yang disusun admin tenant. Pada manifest aset setiap
        // permission ada di sebuah privilege dan setiap privilege ada di sebuah duty,
        // sehingga sifat itu memang tidak bisa diambil dari sana.
        config()->set('coreerp.app_catalog', [[
            'id' => 'app-uji',
            'name' => 'App Uji',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'app_uji',
            'has_ui' => true,
            'navigation' => [
                'rail' => [
                    ['id' => 'master', 'label' => 'Master data'],
                ],
                'sidebar' => [
                    'master' => [
                        ['id' => 'entitas', 'label' => 'Entitas aset', 'permission' => 'app-uji.entitas.read'],
                        ['id' => 'group', 'label' => 'Group aset', 'permission' => 'app-uji.group.read'],
                    ],
                ],
            ],
            'contract_url' => 'https://contracts.example.test/app-uji/openapi.yaml',
            'description' => 'Test catalog app.',
            'entry_points' => [
                ['code' => 'app-uji.entitas.form', 'name' => 'Layar entitas aset', 'type' => 'form'],
                ['code' => 'app-uji.entitas.api', 'name' => 'API entitas aset', 'type' => 'api'],
                ['code' => 'app-uji.group.form', 'name' => 'Layar group aset', 'type' => 'form'],
                ['code' => 'app-uji.group.api', 'name' => 'API group aset', 'type' => 'api'],
                // Sengaja tidak masuk privilege maupun duty mana pun. Owner menerima
                // seluruh duty app yang di-entitle, jadi permission yang sudah terpakai
                // di rantai katalog tidak dapat membuktikan apa pun tentang rantai
                // custom yang disusun admin tenant. Yang ini hanya bisa diperoleh lewat
                // privilege dan duty buatan sendiri.
                ['code' => 'app-uji.perencanaan.form', 'name' => 'Layar perencanaan aset', 'type' => 'form'],
            ],
            'permissions' => [
                ['code' => 'app-uji.entitas.read', 'name' => 'Lihat entitas aset', 'entry_point' => 'app-uji.entitas.form', 'access' => 'read'],
                ['code' => 'app-uji.entitas.create', 'name' => 'Tambah entitas aset', 'entry_point' => 'app-uji.entitas.api', 'access' => 'create'],
                ['code' => 'app-uji.entitas.update', 'name' => 'Ubah entitas aset', 'entry_point' => 'app-uji.entitas.api', 'access' => 'update'],
                ['code' => 'app-uji.entitas.archive', 'name' => 'Arsipkan entitas aset', 'entry_point' => 'app-uji.entitas.api', 'access' => 'delete'],
                ['code' => 'app-uji.group.read', 'name' => 'Lihat group aset', 'entry_point' => 'app-uji.group.form', 'access' => 'read'],
                ['code' => 'app-uji.perencanaan.read', 'name' => 'Lihat perencanaan aset', 'entry_point' => 'app-uji.perencanaan.form', 'access' => 'read'],
                ['code' => 'app-uji.group.create', 'name' => 'Tambah group aset', 'entry_point' => 'app-uji.group.api', 'access' => 'create'],
                ['code' => 'app-uji.group.update', 'name' => 'Ubah group aset', 'entry_point' => 'app-uji.group.api', 'access' => 'update'],
                ['code' => 'app-uji.group.archive', 'name' => 'Arsipkan group aset', 'entry_point' => 'app-uji.group.api', 'access' => 'delete'],
            ],
            'privileges' => [
                [
                    'code' => 'app-uji.entitas.maintain',
                    'name' => 'Pelihara entitas aset',
                    'permissions' => [
                        'app-uji.entitas.read',
                        'app-uji.entitas.create',
                        'app-uji.entitas.update',
                    ],
                ],
                [
                    'code' => 'app-uji.entitas.retire',
                    'name' => 'Arsipkan entitas aset',
                    'permissions' => ['app-uji.entitas.archive'],
                ],
                [
                    'code' => 'app-uji.group.maintain',
                    'name' => 'Pelihara group aset',
                    'permissions' => [
                        'app-uji.group.read',
                        'app-uji.group.create',
                        'app-uji.group.update',
                    ],
                ],
                [
                    'code' => 'app-uji.group.retire',
                    'name' => 'Arsipkan group aset',
                    'permissions' => ['app-uji.group.archive'],
                ],
            ],
            'duties' => [
                [
                    'code' => 'app-uji.entitas.manage',
                    'name' => 'Kelola entitas aset',
                    'privileges' => [
                        'app-uji.entitas.maintain',
                        'app-uji.entitas.retire',
                    ],
                ],
                [
                    'code' => 'app-uji.group.manage',
                    'name' => 'Kelola group aset',
                    'privileges' => [
                        'app-uji.group.maintain',
                        'app-uji.group.retire',
                    ],
                ],
            ],
        ]]);
    }

    /**
     * Akarnya, dan ia tidak bergantung pada schema sama sekali.
     *
     * Selama database test dan database kerja adalah database yang sama, tiap jalur baru yang
     * kebetulan menunjuk `public` akan mengosongkan data kerja. Dua penjagaan di `setUp()` menambal
     * jalur; yang ini menutup kelasnya.
     *
     * **Kenapa di sini dan bukan di `setUp()`.** Percobaan pertama menaruhnya di sana dan membaca
     * `getenv()`. Itu tidak pernah bisa menyala: `.env` baru dimuat ketika aplikasinya berdiri, dan
     * aplikasinya berdiri **di dalam** `parent::setUp()` — jadi kedua nilainya selalu kosong dan
     * pemeriksaannya selalu dilewati. Penjaga yang tidak dapat merah lebih buruk daripada tidak
     * ada, karena ia mengakhiri pencarian.
     *
     * `setUpTraits()` adalah titik yang benar: ia dipanggil **sesudah** aplikasinya berdiri,
     * sehingga `config()` sudah menjawab yang sebenarnya, dan **sebelum** `RefreshDatabase` maupun
     * `DatabaseTruncation` menyentuh satu baris pun — keduanya justru dipasang oleh method ini.
     */
    protected function setUpTraits(): array
    {
        $bawaan = (string) config('database.default');
        $databaseUji = (string) config('database.connections.'.$bawaan.'.database');
        $databaseKerja = (string) config('database.connections.pgsql.database');

        if ($databaseKerja !== '' && $databaseUji === $databaseKerja) {
            $this->fail(
                'Koneksi test menunjuk database "'.$databaseUji.'", yang sama dengan database kerja. '
                .'Test akan mengosongkan database yang sedang dipakai bekerja — sudah terjadi tiga '
                .'kali. Buat database terpisah (`createdb '.$databaseKerja.'_test`) lalu setel '
                .'DB_TEST_DATABASE ke sana.'
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Tiap permintaan test dimulai dengan ikatan `scoped` yang bersih, sama seperti produksi.
     *
     * Di produksi tiap permintaan HTTP mendapat container baru, jadi apa pun yang diingat
     * sebuah kelas selama permintaan hilang di permintaan berikutnya. Di dalam test, satu
     * container dipakai untuk seluruh permintaan pada satu test — dan itu membuat ingatan
     * bocor melewati batas yang di produksi tidak pernah dilewati.
     *
     * Akibatnya bukan test yang gagal palsu, melainkan yang lebih buruk: test yang **lulus**
     * karena membaca ingatan basi, lalu produksi berperilaku lain. Karena itu batasnya ditiru
     * di sini, bukan diakali di tempat pemakaian.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
