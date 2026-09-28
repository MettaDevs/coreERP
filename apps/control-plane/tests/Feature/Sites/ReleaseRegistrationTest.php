<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\Site;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Sites\ClientServerSetup;
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

    /**
     * Manifest v2 dari perakit (`deploy/perakit/rakit.sh`): satu image untuk semua klien, di registry sendiri,
     * tanpa edisi dan tanpa host. Rilisnya tersimpan di bawah edisi tunggal, jalur yang dipakai panel "Server
     * klien" untuk memilih rilis pemasangan.
     */
    public function test_a_v2_manifest_is_registered_under_the_single_image_edition(): void
    {
        $digest = 'sha256:'.str_repeat('c', 64);

        $this->register($this->releaseFiles(manifest: $this->manifestV2(['digest' => $digest])))
            ->assertCreated()
            ->assertJsonPath('edition', Site::SINGLE_IMAGE_EDITION)
            ->assertJsonPath('release', '0.2.0');

        $release = SiteRelease::query()->sole();
        $this->assertSame('coreerp/core@'.$digest, $release->image);
        $this->assertSame($digest, $release->digest);
        $this->assertSame('0.2.0', ClientServerSetup::newestRelease(Site::SINGLE_IMAGE_EDITION));
    }

    /**
     * Manifest v2 yang menunjuk tempat lain tidak dapat ditarik dengan kredensial yang diterbitkan konsol ini.
     * Lebih baik ditolak di sini daripada gagal di server klien, di tengah pemasangan.
     */
    public function test_v2_manifests_that_point_outside_the_registry_project_or_skip_a_digest_are_refused(): void
    {
        $postgres = ['nama' => 'postgres', 'image' => 'coreerp/pendamping/postgres', 'digest' => 'sha256:'.str_repeat('e', 64)];

        $refused = [
            'host di image' => ['image' => 'registry.erp.grenery.xyz/coreerp/core'],
            'project lain' => ['image' => 'lain/core'],
            'image bertag' => ['image' => 'coreerp/core:0.2.0'],
            'tanpa config_digest' => ['config_digest' => null],
            'digest bukan sha256' => ['digest' => 'sha256:xyz'],
            'tanpa commit' => ['commit' => null],
            'pendamping di luar project' => ['pendamping' => [['nama' => 'postgres', 'image' => 'library/postgres', 'digest' => $postgres['digest']]]],
            'pendamping bertag' => ['pendamping' => [['nama' => 'postgres', 'image' => 'coreerp/pendamping/postgres', 'digest' => '16-alpine']]],
            'pendamping kembar' => ['pendamping' => [$postgres, $postgres]],
            'pendamping bukan daftar' => ['pendamping' => ['postgres' => $postgres]],
        ];

        foreach ($refused as $label => $override) {
            $this->register($this->releaseFiles(manifest: $this->manifestV2($override)))
                ->assertStatus(422)
                ->assertJsonPath('error', 'manifest_invalid');
            $this->assertSame(0, SiteRelease::query()->count(), $label);
        }
    }

    // ------------------------------------------------------------------ pembantu

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function manifestV2(array $override = []): array
    {
        return array_filter([
            'versi' => 2,
            'rilis' => '0.2.0',
            'commit' => str_repeat('d', 40),
            'image' => 'coreerp/core',
            'digest' => 'sha256:'.str_repeat('a', 64),
            'config_digest' => 'sha256:'.str_repeat('b', 64),
            'pendamping' => [
                ['nama' => 'gotenberg', 'image' => 'coreerp/pendamping/gotenberg', 'digest' => 'sha256:'.str_repeat('f', 64)],
                ['nama' => 'postgres', 'image' => 'coreerp/pendamping/postgres', 'digest' => 'sha256:'.str_repeat('e', 64)],
            ],
            'dibangun_pada' => '2026-09-15T04:43:37Z',
            ...$override,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array{manifest: string, compose: string, update_script: string, checksums: string, signature: string}
     */
    private function releaseFiles(
        string $compose = "name: coreerp\n",
        int $signingSlot = 5,
        ?string $extraChecksumLine = null,
        string $image = '',
        ?array $manifest = null,
    ): array {
        $manifest = (string) json_encode($manifest ?? [
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
