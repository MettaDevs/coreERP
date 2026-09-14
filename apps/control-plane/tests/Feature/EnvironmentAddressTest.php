<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Environments\EnvironmentAddress;
use ControlPlane\Tests\TestCase;

/**
 * Alamat yang dicetak konsol harus sama persis dengan alamat yang dirutekan Core.
 *
 * ## Kenapa berkas ini ada
 *
 * Aturan bentuk alamat hidup di DUA tempat: `App\Support\ControlPlane\EnvironmentAddress` milik
 * Core, yang **mengurai** alamat masuk dan karena itu menentukan alamat mana yang sungguhan
 * bekerja; dan salinannya di konsol, yang hanya **membangun** untuk ditampilkan.
 *
 * Salinan dipilih dengan sadar — alternatifnya satu panggilan HTTP tiap kali sebuah daftar
 * digambar — dan ongkosnya kemungkinan menyimpang. Yang menyimpang di sini berbentuk paling jahat:
 * konsol mencetak alamat yang terlihat benar, operator mengirimkannya ke pelanggan, dan pelanggan
 * menerima 404 dari alamat yang dicetak sistem itu sendiri.
 *
 * Penjaganya string yang dipaku. Nilai di bawah **identik** dengan yang dipaku
 * `EnvironmentAddressTest` milik Core, jadi begitu salah satu sisi berubah bentuk, salah satu suite
 * merah. Kalau kamu mengubah salah satunya, ubah keduanya — dan kalau kamu datang ke sini karena
 * test ini merah, periksa Core lebih dulu: ia yang berwenang.
 */
class EnvironmentAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skema dan porta dipaku juga, bukan dibiarkan ikut env. Pengembang yang menyetel
        // `COREERP_ADDRESS_SCHEME=http` di laptopnya tidak boleh membuat test di bawah ini merah.
        config([
            'core.base_domain' => 'contoh.co.id',
            'core.address_scheme' => 'https',
            'core.address_port' => null,
        ]);
    }

    /** Dipaku sama dengan Core: `ivs.contoh.co.id`. */
    public function test_production_carries_no_kind_label(): void
    {
        $this->assertSame(
            'https://ivs.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'production'),
        );
    }

    /** Dipaku sama dengan Core: `ivs.sandbox.contoh.co.id`. */
    public function test_other_than_production_carries_its_kind_label(): void
    {
        $this->assertSame(
            'https://ivs.sandbox.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'sandbox'),
        );
    }

    /**
     * Bentuk yang dipilih pemilik produk 14 September 2026: `<tenant>.<jenis>.<domain>`, tanpa
     * `--` dan tanpa slug lingkungan. Dipaku sama dengan Core.
     */
    public function test_a_hyphenated_tenant_is_followed_by_its_kind_label_only(): void
    {
        config(['core.base_domain' => 'erp.grenery.xyz']);

        $this->assertSame(
            'https://pt-nusantara-sehat.demo.erp.grenery.xyz',
            EnvironmentAddress::forEnvironment('pt-nusantara-sehat', 'demo'),
        );
        $this->assertSame(
            'https://pt-nusantara-sehat.erp.grenery.xyz',
            EnvironmentAddress::forEnvironment('pt-nusantara-sehat', 'production'),
        );
    }

    /**
     * Tanpa domain dasar, tidak ada alamat untuk ditampilkan — dan itu keadaan yang sah.
     *
     * On-prem melayani satu pelanggan dari satu alamat, dan pengembangan lokal sebelum DNS
     * disiapkan juga begitu. Layar harus menyembunyikan kolomnya, bukan mencetak alamat karangan.
     */
    public function test_without_a_base_domain_there_is_no_address(): void
    {
        config(['core.base_domain' => null]);

        $this->assertNull(EnvironmentAddress::forEnvironment('ivs', 'production'));
    }

    /**
     * Di laptop pengembang, alamat yang dicetak harus dapat diklik dari mesin yang mencetaknya.
     *
     * Dev server Core melayani `http` di porta 8000 tanpa proxy di depannya. Tanpa skema dan porta
     * yang dapat disetel, layar mencetak `https://...erp.localhost` — terlihat benar, tidak terbuka.
     */
    public function test_local_development_prints_http_with_its_port(): void
    {
        config([
            'core.base_domain' => 'erp.localhost',
            'core.address_scheme' => 'http',
            'core.address_port' => '8000',
        ]);

        $this->assertSame(
            'http://pt-sinar-abadi.demo.erp.localhost:8000',
            EnvironmentAddress::forEnvironment('pt-sinar-abadi', 'demo'),
        );
        $this->assertSame(
            'http://pt-sinar-abadi.erp.localhost:8000',
            EnvironmentAddress::forEnvironment('pt-sinar-abadi', 'production'),
        );
    }

    /** Porta yang sama dengan bawaan skemanya tidak ikut tercetak. */
    public function test_the_default_port_of_a_scheme_is_not_printed(): void
    {
        config(['core.address_scheme' => 'https', 'core.address_port' => '443']);
        $this->assertSame('https://ivs.contoh.co.id', EnvironmentAddress::forEnvironment('ivs', 'production'));

        config(['core.address_scheme' => 'http', 'core.address_port' => '80']);
        $this->assertSame('http://ivs.contoh.co.id', EnvironmentAddress::forEnvironment('ivs', 'production'));
    }

    /**
     * Setelan kosong — keadaan setiap server — tetap `https` tanpa porta.
     *
     * Env yang dibiarkan kosong memulangkan string kosong, bukan null. Keduanya harus berarti sama.
     */
    public function test_empty_settings_mean_https_without_a_port(): void
    {
        config(['core.address_scheme' => '', 'core.address_port' => '']);

        $this->assertSame('https://ivs.contoh.co.id', EnvironmentAddress::forEnvironment('ivs', 'production'));
    }

    /** Skema salah ketik tidak menghasilkan tautan berskema karangan. */
    public function test_an_unknown_scheme_falls_back_to_https(): void
    {
        config(['core.address_scheme' => 'htps']);

        $this->assertSame('https://ivs.contoh.co.id', EnvironmentAddress::forEnvironment('ivs', 'production'));
    }

    /** Tenant tanpa slug tidak menghasilkan alamat setengah jadi. */
    public function test_a_missing_slug_never_produces_a_half_built_address(): void
    {
        $this->assertNull(EnvironmentAddress::forEnvironment('', 'production'));
        $this->assertNull(EnvironmentAddress::forEnvironment('', 'demo'));
    }
}
