<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Http\Controllers\Installer\InstallerFiles;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Berkas pemasang disajikan apa adanya, tanpa login, dan menolak apa pun di luar daftarnya.
 *
 * Berkas dibaca dari salinan tiruan, bukan dari repo. Test ini menguji penyajiannya — isian alamat,
 * penolakan, dan kunci yang tertukar — dan isian alamat hanya dapat dibuktikan pada skrip yang memang
 * memuatnya.
 */
final class InstallerFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/pemasang-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->root.'/deploy/agent');
        File::ensureDirectoryExists($this->root.'/scripts');

        File::put($this->root.'/deploy/agent/pasang.sh', "#!/usr/bin/env bash\nALAMAT_ADMIN='".InstallerFiles::ADMIN_URL_PLACEHOLDER."'\n");

        foreach (InstallerFiles::FILES as $name => $pathInRepo) {
            File::put($this->root.'/'.$pathInRepo, 'isi '.$name."\n");
        }

        config([
            'sites.installer_source_root' => $this->root,
            'app.url' => 'https://admin.erp.contoh.test/',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_installer_carries_this_consoles_address_and_needs_no_login(): void
    {
        $response = $this->get('/pasang.sh')->assertOk();

        $this->assertSame("#!/usr/bin/env bash\nALAMAT_ADMIN='https://admin.erp.contoh.test'\n", $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** Alamat yang bukan https di produksi berarti setiap server klien mendaftar lewat jalur telanjang. */
    public function test_the_installer_refuses_a_non_https_address_outside_local_and_testing(): void
    {
        config(['app.url' => 'http://admin.erp.contoh.test']);
        $this->app['env'] = 'production';

        $this->get('/pasang.sh')->assertStatus(503)->assertSee('APP_URL', false);
    }

    public function test_every_listed_agent_file_is_served_byte_for_byte(): void
    {
        foreach (array_keys(InstallerFiles::FILES) as $name) {
            $this->assertSame('isi '.$name."\n", $this->get('/agen/'.$name)->assertOk()->getContent());
        }
    }

    public function test_anything_outside_the_list_is_not_found(): void
    {
        $this->get('/agen/pasang.sh')->assertNotFound();
        $this->get('/agen/..%2F..%2F.env')->assertNotFound();
        $this->get('/agen/site-key.pem')->assertNotFound();
    }

    public function test_a_missing_file_is_not_found_rather_than_an_empty_script(): void
    {
        File::delete($this->root.'/deploy/agent/coreerp-agent');

        $this->get('/agen/coreerp-agent')->assertNotFound();
    }

    public function test_the_release_public_key_is_served_when_it_is_a_public_key(): void
    {
        [$privatePem, $publicPem] = $this->rsaKeyPair();

        File::put($this->root.'/rilis-publik.pem', $publicPem);
        config(['sites.release_public_key_path' => $this->root.'/rilis-publik.pem']);

        $this->assertSame($publicPem, $this->get('/agen/kunci-rilis.pub')->assertOk()->getContent());
    }

    /** Kunci yang tertukar dan terlanjur dipaku agen hanya dapat dilepas dengan menyentuh server klien. */
    public function test_a_private_key_or_missing_key_is_never_served(): void
    {
        config(['sites.release_public_key_path' => $this->root.'/tidak-ada.pem']);
        $this->get('/agen/kunci-rilis.pub')->assertStatus(503);

        [$privatePem, $publicPem] = $this->rsaKeyPair();
        File::put($this->root.'/tertukar.pem', $privatePem);
        config(['sites.release_public_key_path' => $this->root.'/tertukar.pem']);

        $response = $this->get('/agen/kunci-rilis.pub')->assertStatus(503);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $response->getContent());

        // Berkas gabungan — kunci publik lalu kunci privat — terbaca OpenSSL sebagai kunci publik yang
        // sah dari blok pertamanya. Tanpa pemeriksaan isinya, kunci privat ikut tersaji ke internet.
        File::put($this->root.'/gabungan.pem', $publicPem.$privatePem);
        config(['sites.release_public_key_path' => $this->root.'/gabungan.pem']);

        $response = $this->get('/agen/kunci-rilis.pub')->assertStatus(503);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $response->getContent());
    }

    /**
     * Pasangan kunci RSA uji. PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri; berkasnya
     * ikut terpasang di samping binary PHP, dan di Linux cabang itu tidak pernah diambil.
     *
     * @return array{0: string, 1: string}
     */
    private function rsaKeyPair(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $extra = is_file($bundledConfig) ? ['config' => $bundledConfig] : [];

        $key = openssl_pkey_new($options) ?: openssl_pkey_new($options + $extra);
        $this->assertNotFalse($key, 'Kunci RSA uji tidak dapat dibuat.');
        $this->assertTrue(openssl_pkey_export($key, $privatePem, null, $extra));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [(string) $privatePem, (string) $details['key']];
    }
}
