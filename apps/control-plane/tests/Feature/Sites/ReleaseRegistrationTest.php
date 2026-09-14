<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\SiteRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendaftaran berkas rilis oleh alur rilis: tidak ada yang tersimpan sebelum tanda tangan, checksum,
 * dan manifest-nya terbukti.
 */
class ReleaseRegistrationTest extends SiteTestCase
{
    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'kunci-rilis-');
        file_put_contents($this->publicKeyPath, $this->rsaKey(5)['public']);

        config([
            'sites.release_public_key_path' => $this->publicKeyPath,
            'sites.release_token' => 'token-rilis-uji',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            @unlink($this->publicKeyPath);
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_signed_release_is_registered_once_and_repeated_identically_answers_200(): void
    {
        $files = $this->releaseFiles();

        $this->register($files)->assertCreated()->assertJsonPath('release', '0.2.0');
        $this->register($files)->assertOk();

        $release = SiteRelease::query()->sole();
        $this->assertSame('apotek-sejahtera', $release->edition);
        $this->assertSame($files['signature'], $release->fileContents('SHA256SUMS.sig'));
    }

    public function test_the_same_release_with_different_content_is_a_conflict(): void
    {
        $this->register($this->releaseFiles())->assertCreated();

        $this->register($this->releaseFiles(compose: "name: coreerp\n# berubah\n"))
            ->assertStatus(409)
            ->assertJsonPath('error', 'release_conflict');

        $this->assertSame("name: coreerp\n", SiteRelease::query()->sole()->compose);
    }

    /**
     * Konsol ini tidak memegang kunci privat rilis: berkas yang ditandatangani kunci lain tidak pernah
     * menjadi pilihan operator.
     */
    public function test_a_release_signed_by_another_key_is_refused(): void
    {
        $this->register($this->releaseFiles(signingSlot: 6))
            ->assertStatus(422)
            ->assertJsonPath('error', 'signature_invalid');

        $this->assertSame(0, SiteRelease::query()->count());
    }

    public function test_a_file_that_does_not_match_its_checksum_is_refused(): void
    {
        $files = $this->releaseFiles();
        $files['update_script'] .= "rm -rf /\n";

        $this->register($files)->assertStatus(422)->assertJsonPath('error', 'checksums_invalid');
    }

    /** Agen menjalankan `sha256sum --check`; berkas yang disebut tetapi tidak dikirim akan menggagalkan pembaruan di server klien. */
    public function test_checksums_listing_the_image_archive_are_refused(): void
    {
        $this->register($this->releaseFiles(extraChecksumLine: str_repeat('0', 64).'  images.tar.gz'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'checksums_invalid');
    }

    public function test_a_manifest_naming_the_image_by_tag_is_refused(): void
    {
        $this->register($this->releaseFiles(image: 'ghcr.io/mettadevs/coreerp/edisi-apotek-sejahtera:0.2.0'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'manifest_invalid');
    }

    public function test_a_wrong_or_unconfigured_token_is_refused(): void
    {
        $this->register($this->releaseFiles(), 'token-salah')->assertUnauthorized();

        config(['sites.release_token' => '']);
        $this->register($this->releaseFiles(), '')->assertUnauthorized();

        $this->assertSame(0, SiteRelease::query()->count());
    }

    // ------------------------------------------------------------------ pembantu

    /** @return array{manifest: string, compose: string, update_script: string, checksums: string, signature: string} */
    private function releaseFiles(
        string $compose = "name: coreerp\n",
        int $signingSlot = 5,
        ?string $extraChecksumLine = null,
        string $image = '',
    ): array {
        $manifest = (string) json_encode([
            'edisi' => 'apotek-sejahtera',
            'rilis' => '0.2.0',
            'image' => $image !== '' ? $image : 'ghcr.io/mettadevs/coreerp/edisi-apotek-sejahtera@sha256:'.str_repeat('a', 64),
            'digest' => 'sha256:'.str_repeat('b', 64),
        ]);
        $update = "#!/usr/bin/env bash\necho pasang\n";

        $checksums = implode("\n", array_filter([
            hash('sha256', $compose).'  compose.yaml',
            hash('sha256', $manifest).'  manifest.json',
            hash('sha256', $update).'  update.sh',
            $extraChecksumLine,
        ]))."\n";

        openssl_sign($checksums, $signature, $this->rsaKey($signingSlot)['private'], OPENSSL_ALGO_SHA256);

        return [
            'manifest' => $manifest,
            'compose' => $compose,
            'update_script' => $update,
            'checksums' => $checksums,
            'signature' => (string) $signature,
        ];
    }

    /**
     * @param  array<string, string>  $files
     * @return TestResponse<Response>
     */
    private function register(array $files, string $token = 'token-rilis-uji'): TestResponse
    {
        $uploads = [];

        foreach ($files as $field => $contents) {
            $uploads[$field] = UploadedFile::fake()->createWithContent($field, $contents);
        }

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->post('/api/releases/v1', $uploads);
    }
}
