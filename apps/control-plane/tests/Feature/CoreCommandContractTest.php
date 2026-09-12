<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Tests\TestCase;

/**
 * Konsol dan Core sepakat soal bentuk setiap perintahnya — dibaca dari kontrak, bukan dari ingatan.
 *
 * ## Kenapa test ini ada
 *
 * Seluruh test lain di konsol memakai `Http::fake()`. Itu benar — ia membuat suite ini tidak
 * menuntut Core hidup — tetapi ia punya satu lubang yang mahal: **tiruan selalu setuju dengan yang
 * menirukannya.** Kalau konsol salah menebak bentuk panggilannya, tiruannya ikut salah dengan cara
 * yang sama, dan seluruh suite tetap hijau sementara panggilan sungguhan dijawab 401.
 *
 * Itu bukan kekhawatiran teoretis. Pada 12 September 2026 hal itu benar-benar terjadi: sisi Core
 * memeriksa `Authorization: Bearer`, sisi konsol mengirim `X-Control-Plane-Token`, dan **kedua
 * suite hijau**. Keduanya dikerjakan terpisah, dan masing-masing memalsukan lawan bicaranya.
 *
 * ## Yang membuat test ini berbeda
 *
 * Ia tidak memalsukan apa pun. Ia membaca `apps/core/contracts/openapi-internal.yaml` — berkas yang
 * sama yang ditagih langkah CI `check-contract-coverage.py` terhadap rute Core yang sebenarnya —
 * lalu menuntut panggilan konsol cocok dengannya.
 *
 * Jadi rantainya lengkap: rute Core dijaga cocok dengan kontrak oleh CI, dan panggilan konsol
 * dijaga cocok dengan kontrak oleh test ini. Tidak ada sisi yang boleh berubah sendirian.
 *
 * Yang **masih** tidak dibuktikan siapa pun, dan ditulis di sini supaya tidak dikira sudah aman:
 * bahwa Core benar-benar berjalan di alamat yang disetel konsol. Itu hanya dapat dibuktikan satu
 * panggilan sungguhan di lingkungan yang kedua aplikasinya hidup.
 */
class CoreCommandContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $file = realpath(__DIR__.'/../../../core/contracts/openapi-internal.yaml');

        if ($file === false) {
            $this->fail(
                'Kontrak internal Core tidak ditemukan. Test ini tidak dapat membuktikan apa pun '
                .'tanpa subjek — kalau berkasnya memang dipindah, perbarui jalurnya di sini.'
            );
        }

        if (! function_exists('yaml_parse_file')) {
            // Ekstensi `yaml` tidak ada di mesin mana pun yang dipakai repo ini, jadi kontraknya
            // dibaca dengan pembaca sederhana di bawah alih-alih menambah dependensi demi satu test.
            $this->contract = $this->readNaively((string) file_get_contents($file));

            return;
        }

        /** @var array<string, mixed> $parsed */
        $parsed = yaml_parse_file($file);
        $this->contract = $parsed;
    }

    public function test_the_address_the_console_calls_is_in_the_contract(): void
    {
        $this->assertContains(
            '/tenants',
            $this->contract['paths'],
            'Kontrak Core tidak memuat rute pembuatan tenant. Konsol memanggil alamat yang tidak '
            .'dijanjikan siapa pun.'
        );
    }

    public function test_the_environment_provisioning_address_is_in_the_contract(): void
    {
        $this->assertContains(
            '/environments/{lingkungan}/siapkan',
            $this->contract['paths'],
            'Kontrak Core tidak memuat rute penyiapan lingkungan. Tombol "Siapkan" memanggil alamat '
            .'yang tidak dijanjikan siapa pun.'
        );
    }

    public function test_the_console_sends_the_token_the_way_the_contract_asks(): void
    {
        // `http` + `bearer` berarti `Authorization: Bearer <token>`. Konsol memakai
        // `Http::withToken()`, yang menghasilkan header persis itu.
        $this->assertContains('scheme: bearer', $this->contract['lines']);
        $this->assertContains('controlPlaneToken:', $this->contract['lines']);

        $source = (string) file_get_contents(__DIR__.'/../../app/Customers/CreateCustomer.php');

        $this->assertStringContainsString(
            'Http::withToken(',
            $source,
            'Kontrak menuntut token dikirim sebagai `Authorization: Bearer`, tetapi konsol tidak '
            .'memakai `Http::withToken()`. Header kustom apa pun akan dijawab 401 oleh Core, dan '
            .'test bertiru tidak akan menangkapnya.'
        );

        $this->assertStringNotContainsString(
            'X-Control-Plane-Token',
            $source,
            'Konsol masih mengirim header kustom. Core membacanya lewat `bearerToken()`.'
        );
    }

    /**
     * Aturan yang sama berlaku untuk pemanggil kedua, dan pemeriksaannya diulang dengan sengaja.
     *
     * Cacat yang melahirkan berkas ini adalah cacat **per pemanggil**, bukan per aplikasi: satu
     * berkas yang benar tidak membuat berkas berikutnya ikut benar. Pemanggil ketiga kelak harus
     * menambah blok seperti ini juga, dan itu memang ongkos yang diinginkan.
     */
    public function test_the_provisioning_caller_also_uses_bearer(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../app/Environments/ProvisionViaCore.php');

        $this->assertStringContainsString(
            'Http::withToken(',
            $source,
            'Kontrak menuntut token dikirim sebagai `Authorization: Bearer`, tetapi pemanggil '
            .'penyiapan tidak memakai `Http::withToken()`.'
        );

        // Tenggatnya sendiri, bukan `core.timeout`. Penyiapan menjalankan migration tiap module;
        // tiga puluh detik yang cukup untuk melahirkan tenant akan memutusnya di tengah jalan.
        $this->assertStringContainsString(
            "config('core.provision_timeout')",
            $source,
            'Penyiapan memakai tenggat pembuatan tenant. Keduanya mengukur pekerjaan yang berbeda.'
        );
    }

    /**
     * @return array{paths: list<string>, lines: list<string>}
     */
    private function readNaively(string $content): array
    {
        $lines = array_map(trim(...), explode("\n", $content));

        $paths = [];
        foreach ($lines as $line) {
            if (preg_match('#^(/[A-Za-z0-9_\-{}/]*):$#', $line, $matches) === 1) {
                $paths[] = $matches[1];
            }
        }

        $this->assertNotSame([], $paths, 'Tidak satu pun alamat terbaca dari kontrak; pembacanya salah.');

        return ['paths' => $paths, 'lines' => $lines];
    }
}
