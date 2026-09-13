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

        config(['core.base_domain' => 'contoh.co.id']);
    }

    /** Dipaku sama dengan Core: `ivs.contoh.co.id`. */
    public function test_production_carries_no_kind_label(): void
    {
        $this->assertSame(
            'https://ivs.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'ivs', 'production'),
        );
    }

    /** Dipaku sama dengan Core: `ivs--uat.sandbox.contoh.co.id`. */
    public function test_other_than_production_carries_its_kind_label(): void
    {
        $this->assertSame(
            'https://ivs--uat.sandbox.contoh.co.id',
            EnvironmentAddress::forEnvironment('ivs', 'uat', 'sandbox'),
        );
    }

    /**
     * Pemisahnya dua tanda hubung, dan slug yang memuat tanda hubung tunggal tetap utuh.
     *
     * Percobaan pertama Core memakai satu, dengan alasan "slug tenant tidak pernah memuat tanda
     * hubung" — dan alasan itu salah pada pelanggan sungguhan, karena `Str::slug()` mengubah
     * "PT Sinar Abadi" menjadi `pt-sinar-abadi`.
     */
    public function test_hyphenated_slugs_survive_the_double_hyphen_separator(): void
    {
        $this->assertSame(
            'https://pt-sinar-abadi--peragaan-penjualan.demo.contoh.co.id',
            EnvironmentAddress::forEnvironment('pt-sinar-abadi', 'peragaan-penjualan', 'demo'),
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

        $this->assertNull(EnvironmentAddress::forEnvironment('ivs', 'ivs', 'production'));
    }

    /** Tenant atau lingkungan tanpa slug tidak menghasilkan alamat setengah jadi. */
    public function test_a_missing_slug_never_produces_a_half_built_address(): void
    {
        $this->assertNull(EnvironmentAddress::forEnvironment('', 'ivs', 'production'));
        $this->assertNull(EnvironmentAddress::forEnvironment('ivs', '', 'demo'));
    }
}
