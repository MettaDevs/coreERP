<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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
