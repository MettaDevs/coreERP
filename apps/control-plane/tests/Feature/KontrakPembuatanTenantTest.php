<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Tests\TestCase;

/**
 * Konsol dan Core sepakat soal bentuk panggilannya — dibaca dari kontrak, bukan dari ingatan.
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
class KontrakPembuatanTenantTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $kontrak;

    protected function setUp(): void
    {
        parent::setUp();

        $berkas = realpath(__DIR__.'/../../../core/contracts/openapi-internal.yaml');

        if ($berkas === false) {
            $this->fail(
                'Kontrak internal Core tidak ditemukan. Test ini tidak dapat membuktikan apa pun '
                .'tanpa subjek — kalau berkasnya memang dipindah, perbarui jalurnya di sini.'
            );
        }

        if (! function_exists('yaml_parse_file')) {
            // Ekstensi `yaml` tidak ada di mesin mana pun yang dipakai repo ini, jadi kontraknya
            // dibaca dengan pembaca sederhana di bawah alih-alih menambah dependensi demi satu test.
            $this->kontrak = $this->bacaSeadanya((string) file_get_contents($berkas));

            return;
        }

        /** @var array<string, mixed> $isi */
        $isi = yaml_parse_file($berkas);
        $this->kontrak = $isi;
    }

    public function test_alamat_yang_dipanggil_konsol_ada_di_kontrak(): void
    {
        $this->assertContains(
            '/tenants',
            $this->kontrak['paths'],
            'Kontrak Core tidak memuat rute pembuatan tenant. Konsol memanggil alamat yang tidak '
            .'dijanjikan siapa pun.'
        );
    }

    public function test_konsol_mengirim_token_dengan_cara_yang_diminta_kontrak(): void
    {
        // `http` + `bearer` berarti `Authorization: Bearer <token>`. Konsol memakai
        // `Http::withToken()`, yang menghasilkan header persis itu.
        $this->assertContains('scheme: bearer', $this->kontrak['baris']);
        $this->assertContains('controlPlaneToken:', $this->kontrak['baris']);

        $sumber = (string) file_get_contents(__DIR__.'/../../app/Pelanggan/BuatPelanggan.php');

        $this->assertStringContainsString(
            'Http::withToken(',
            $sumber,
            'Kontrak menuntut token dikirim sebagai `Authorization: Bearer`, tetapi konsol tidak '
            .'memakai `Http::withToken()`. Header kustom apa pun akan dijawab 401 oleh Core, dan '
            .'test bertiru tidak akan menangkapnya.'
        );

        $this->assertStringNotContainsString(
            'X-Control-Plane-Token',
            $sumber,
            'Konsol masih mengirim header kustom. Core membacanya lewat `bearerToken()`.'
        );
    }

    /**
     * @return array{paths: list<string>, baris: list<string>}
     */
    private function bacaSeadanya(string $isi): array
    {
        $baris = array_map(trim(...), explode("\n", $isi));

        $jalur = [];
        foreach ($baris as $satu) {
            if (preg_match('#^(/[A-Za-z0-9_\-{}/]*):$#', $satu, $cocok) === 1) {
                $jalur[] = $cocok[1];
            }
        }

        $this->assertNotSame([], $jalur, 'Tidak satu pun alamat terbaca dari kontrak; pembacanya salah.');

        return ['paths' => $jalur, 'baris' => $baris];
    }
}
