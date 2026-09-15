<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Halaman Pengaturan (PS-08): sidik jari kunci rilis, keadaan kunci lisensi, dan tempat bagian Harbor.
 */
final class SettingsScreenTest extends SiteTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_valid_keys_show_their_der_fingerprints_and_the_license_pair(): void
    {
        $release = $this->rsaKey(3);
        $license = $this->rsaKey(4);

        config([
            // Baris akhir Windows dan spasi di sekitarnya tidak mengubah kunci, jadi tidak boleh mengubah
            // sidik jarinya.
            'sites.release_public_key_path' => $this->file("\n".str_replace("\n", "\r\n", $release['public'])."\n\n"),
            'sites.license_private_key_path' => $this->file($license['private']),
            'sites.license_public_key_path' => $this->file($license['public']),
        ]);

        $this->actingAs($this->operator())->get('/pengaturan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings')
                ->where('releaseKey', ['ok' => true, 'fingerprint' => $this->derFingerprint($release['public'])])
                ->where('licenseKey.private', ['ok' => true])
                ->where('licenseKey.public', ['ok' => true, 'fingerprint' => $this->derFingerprint($license['public'])])
                ->where('licenseKey.pairMatches', true));
    }

    public function test_missing_and_wrong_keys_are_clear_errors(): void
    {
        $release = $this->rsaKey(3);
        $license = $this->rsaKey(4);
        $operator = $this->operator();

        config([
            'sites.release_public_key_path' => null,
            'sites.license_private_key_path' => storage_path('framework/testing/tidak-ada.pem'),
            'sites.license_public_key_path' => $this->file('bukan kunci'),
        ]);

        $this->actingAs($operator)->get('/pengaturan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('releaseKey.ok', false)
                ->where('releaseKey.error', fn (string $error): bool => str_contains($error, 'CONSOLE_RELEASE_PUBLIC_KEY_PATH'))
                ->where('licenseKey.private.ok', false)
                ->where('licenseKey.private.error', fn (string $error): bool => str_contains($error, 'CONSOLE_LICENSE_PRIVATE_KEY_PATH'))
                ->where('licenseKey.public.ok', false)
                ->where('licenseKey.public.error', fn (string $error): bool => str_contains($error, 'bukan kunci publik'))
                ->where('licenseKey.pairMatches', null));

        // Kunci privat yang tertukar ke jalur kunci publik tidak pernah dinyatakan sah, dan pasangan yang
        // bukan pasangan disebut.
        config([
            'sites.release_public_key_path' => $this->file($release['private']),
            'sites.license_private_key_path' => $this->file($license['private']),
            'sites.license_public_key_path' => $this->file($release['public']),
        ]);

        $this->actingAs($operator)->get('/pengaturan')
            ->assertInertia(fn ($page) => $page
                ->where('releaseKey.ok', false)
                ->where('releaseKey.error', fn (string $error): bool => str_contains($error, 'kunci privat'))
                ->missing('releaseKey.fingerprint')
                ->where('licenseKey.pairMatches', false));
    }

    public function test_the_page_is_for_operators_only_and_is_in_the_navigation(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator', 'email' => 'biasa@contoh.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/pengaturan')->assertRedirect('/login');
        $this->actingAs(User::query()->findOrFail($id))->get('/pengaturan')->assertNotFound();

        $navigation = (string) file_get_contents(base_path('resources/js/lib/navigation.ts'));
        $this->assertStringContainsString("{ href: '/pengaturan', title: 'Pengaturan' }", $navigation);

        $page = (string) file_get_contents(base_path('resources/js/pages/settings.tsx'));
        $this->assertStringContainsString('Registry (Harbor)', $page);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kunci-pengaturan-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /** Pembanding yang tidak memakai kode yang diuji: DER diambil langsung dari badan PEM. */
    private function derFingerprint(string $publicPem): string
    {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $publicPem);

        return hash('sha256', (string) base64_decode((string) $body, true));
    }
}
