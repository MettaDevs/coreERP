<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;

/**
 * Penjaga arah lapis di dalam Core: `App\Platform` tidak boleh memakai `App\Foundation`, dan
 * `App\Platform\ControlPlane` tertutup bagi kode lain.
 *
 * Polanya meniru Business Central: System Application tidak mengenal Business Foundation, dan
 * Business Foundation tidak mengenal Base App. Di sana penjaganya compiler AL lewat `app.json`;
 * di PHP satu aplikasi memuat semua kelas, jadi penjaganya pembacaan berkas seperti
 * `ModuleNamespaceBoundaryTest`. Nama kelas disebut lewat `use`, pemanggilan statis, maupun di
 * dalam string, dan ketiganya tertangkap karena yang dibaca teksnya, bukan tipenya.
 *
 * Rencana dan alasannya ada di `docs/todo/lapis-core/README.md`. Pelanggaran yang sudah ada
 * sebelum pemindahan dicatat di `ALLOWED`. Daftar itu hanya boleh memendek: entri yang tidak lagi
 * ditemukan di kode membuat test ini merah, supaya pengecualian yang sudah diperbaiki tidak
 * tertinggal sebagai izin terbuka.
 *
 * Kelas di luar kedua lapis hanya perekat Laravel (`App\Providers`, `App\Http`, `App\Console`;
 * daftarnya di `docs/onboarding/peta-kode.md`). Ia tidak punya lapis, jadi rujukan ke sana tidak
 * diperiksa.
 */
class LayerDirectionBoundaryTest extends TestCase
{
    /**
     * Pelanggaran yang sudah ada, dengan bentuk `Pemakai -> Yang dipakai`. Setiap entri harus
     * tercatat di tabel pelanggaran pada `docs/todo/lapis-core/README.md`.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // Hanya rujukan docblock, muncul saat PublishWorkflowEvents pindah ke Foundation/Workflow.
        // Perbaikan: hapus `use`, sebut nama perintah artisan-nya saja.
        'App\\Platform\\ControlPlane\\Console\\ConvertEnvironment -> App\\Foundation\\Workflow\\Console\\PublishWorkflowEvents',
        'App\\Platform\\ControlPlane\\Console\\CopyEnvironment -> App\\Foundation\\Workflow\\Console\\PublishWorkflowEvents',
        'App\\Platform\\Integration\\Http\\Controllers\\IntegrationClientController -> App\\Foundation\\FinancePosting\\Support\\IntegrationClientAccounts',
        'App\\Platform\\Integration\\Http\\Middleware\\AuthenticateIntegrationClient -> App\\Foundation\\FinancePosting\\Support\\IntegrationClientAccounts',
        // Pemasangan module langsung menyiapkan data Foundation. Perbaikan: Foundation mendengarkan
        // kejadian "module terpasang".
        'App\\Platform\\Modules\\Actions\\InstallModule -> App\\Foundation\\NumberSequence\\Actions\\EnsureNumberSequenceDrafts',
        'App\\Platform\\Modules\\Actions\\RegisterAppCatalog -> App\\Foundation\\NumberSequence\\Actions\\EnsureNumberSequenceDrafts',
        'App\\Platform\\Modules\\Actions\\RegisterAppCatalog -> App\\Foundation\\NumberSequence\\Models\\NumberSequenceReference',
        // CoreServices menyambungkan semua facade bisnis. Perbaikan (PR facade): tiap fitur Foundation
        // mendaftarkan implementasi facade-nya sendiri.
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\Currency\\ModuleServices\\CurrencyRoundingCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\FinancePosting\\ModuleServices\\AccountDirectoryCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\FinancePosting\\ModuleServices\\PostingFeedCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\FinancePosting\\ModuleServices\\FinancePostingSettingsCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\FinancePosting\\Support\\PostingAccountResolverRegistry',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\FiscalCalendar\\ModuleServices\\FiscalCalendarDirectoryCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\NumberSequence\\ModuleServices\\NumberSequenceIssuerCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\UnitOfMeasure\\ModuleServices\\UnitOfMeasureDirectoryCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\Vendor\\ModuleServices\\VendorDirectoryCore',
        'App\\Platform\\Modules\\Support\\CoreServices -> App\\Foundation\\Workflow\\ModuleServices\\WorkflowEngineCore',
        'App\\Platform\\Organization\\Models\\LegalEntity -> App\\Foundation\\FiscalCalendar\\Models\\FiscalCalendar',
        'App\\Platform\\Organization\\Models\\OrganizationParty -> App\\Foundation\\AddressBook\\Models\\Party',
        'App\\Platform\\Reporting\\Support\\PrintIdentityStore -> App\\Foundation\\AddressBook\\Support\\OrganizationAddressBook',
        'App\\Platform\\Reporting\\Support\\ValueFormat -> App\\Foundation\\Currency\\Support\\MoneyPrecision',
        'App\\Platform\\Reporting\\Support\\ValueFormats -> App\\Foundation\\Currency\\Support\\MoneyPrecision',
        'App\\Platform\\Tenant\\Actions\\RegisterBusiness -> App\\Foundation\\NumberSequence\\Actions\\EnsureNumberSequenceDrafts',
        'App\\Platform\\Tenant\\Actions\\RegisterBusiness -> App\\Foundation\\UnitOfMeasure\\Actions\\ProvisionDefaultUnitsOfMeasure',
        'App\\Platform\\Tenant\\Actions\\RegisterBusiness -> App\\Platform\\ControlPlane\\Models\\Client',
        'App\\Platform\\Tenant\\Models\\Tenant -> App\\Platform\\ControlPlane\\Models\\Client',
    ];

    /** Penanda tabel milik pusat: model milik pusat di fitur lain perlu memakainya. */
    private const CONTROL_PLANE_PUBLIC = [
        'App\\Platform\\ControlPlane\\OwnedByControlPlane',
    ];

    public function test_layers_only_depend_downwards(): void
    {
        $found = $this->violations(self::scan($this->appPath()));

        $unexpected = array_values(array_diff($found, self::ALLOWED));

        $this->assertSame([], $unexpected, implode("\n", [
            'Ada kode yang melanggar arah lapis Core:',
            '- App\\Platform tidak boleh memakai App\\Foundation.',
            '- App\\Platform\\ControlPlane hanya boleh dipakai dari dalam App\\Platform\\ControlPlane.',
            'Butuh sesuatu dari lapis atas? Balik arahnya: lapis atas mendaftarkan dirinya ke',
            'lapis bawah (service provider, event), bukan lapis bawah memanggil ke atas.',
        ]));
    }

    public function test_allowed_list_only_holds_violations_that_still_exist(): void
    {
        $found = $this->violations(self::scan($this->appPath()));

        $this->assertSame([], array_values(array_diff(self::ALLOWED, $found)), implode("\n", [
            'Pengecualian ini sudah tidak ditemukan di kode. Hapus dari ALLOWED,',
            'dan coret barisnya di docs/todo/lapis-core/README.md.',
        ]));
    }

    public function test_detector_catches_imports_static_calls_and_strings(): void
    {
        $files = [
            'App\\Platform\\Access\\Guard' => <<<'PHP'
            <?php
            namespace App\Platform\Access;
            use App\Foundation\Vendor\Models\Vendor;
            use App\Platform\ControlPlane\OwnedByControlPlane;
            class Guard {
                public function x(): void {
                    \App\Foundation\NumberSequence\Issuer::next();
                    app('App\\Platform\\ControlPlane\\Models\\Site');
                }
            }
            PHP,
            'App\\Foundation\\Vendor\\Models\\Vendor' => <<<'PHP'
            <?php
            namespace App\Foundation\Vendor\Models;
            use App\Platform\Tenant\Models\Tenant;
            use App\Platform\ControlPlane\Models\Site;
            PHP,
            'App\\Platform\\ControlPlane\\Models\\Site' => <<<'PHP'
            <?php
            namespace App\Platform\ControlPlane\Models;
            use App\Platform\ControlPlane\Support\Fleet;
            PHP,
        ];

        $this->assertSame([
            'App\\Foundation\\Vendor\\Models\\Vendor -> App\\Platform\\ControlPlane\\Models\\Site',
            'App\\Platform\\Access\\Guard -> App\\Foundation\\NumberSequence\\Issuer',
            'App\\Platform\\Access\\Guard -> App\\Foundation\\Vendor\\Models\\Vendor',
            'App\\Platform\\Access\\Guard -> App\\Platform\\ControlPlane\\Models\\Site',
        ], $this->violations($files));
    }

    /**
     * @param  array<string, string>  $files  FQCN pemakai => isi berkas
     * @return list<string>
     */
    private function violations(array $files): array
    {
        $out = [];

        foreach ($files as $from => $source) {
            foreach (self::mentions($source) as $to) {
                if ($to === $from) {
                    continue;
                }

                $fromPlatform = str_starts_with($from, 'App\\Platform\\');
                $fromControlPlane = str_starts_with($from, 'App\\Platform\\ControlPlane\\');

                if ($fromPlatform && str_starts_with($to, 'App\\Foundation\\')) {
                    $out[] = "{$from} -> {$to}";
                }

                if (! $fromControlPlane
                    && str_starts_with($to, 'App\\Platform\\ControlPlane\\')
                    && ! in_array($to, self::CONTROL_PLANE_PUBLIC, true)) {
                    $out[] = "{$from} -> {$to}";
                }
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Semua nama kelas berlapis yang disebut sebuah berkas, dalam bentuk satu backslash.
     *
     * @return list<string>
     */
    private static function mentions(string $source): array
    {
        preg_match_all('/(?<!\w)App(?:\\\\{1,2}\w+)+/', $source, $matches);

        $names = [];

        foreach ($matches[0] as $name) {
            $name = str_replace('\\\\', '\\', $name);

            if (str_starts_with($name, 'App\\Platform\\') || str_starts_with($name, 'App\\Foundation\\')) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, string> FQCN => isi berkas, untuk semua berkas di App\Platform dan App\Foundation
     */
    private static function scan(string $app): array
    {
        $files = [];

        foreach (['Platform', 'Foundation'] as $layer) {
            $root = $app.DIRECTORY_SEPARATOR.$layer;

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($app) + 1, -4);
                $files['App\\'.str_replace(['/', DIRECTORY_SEPARATOR], '\\', $relative)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function appPath(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
    }
}
