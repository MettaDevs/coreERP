<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Tests\TestCase;

/**
 * Setiap layar module yang meminta laporan memakai kode yang benar-benar terdaftar.
 *
 * Halaman laporan memanggil pratinjau dengan `useReportData('<kode>')` dan tombol Cetak-nya dengan
 * `reportCode="<kode>"`; tombol cetak di layar transaksi memakai `requestPrint({ report: '<kode>' })`.
 * Kode yang tidak terdaftar tidak gagal saat build maupun saat layar dibuka: pratinjaunya menjawab
 * "laporan tidak dikenal" dan dialog cetaknya 404. Laporan pemeliharaan dan mutasi pernah sampai ke main
 * dalam keadaan itu, jadi kodenya dibaca dari berkas layarnya di sini.
 */
class ReportScreenCodeTest extends TestCase
{
    public function test_every_report_code_on_a_screen_is_registered(): void
    {
        $registry = app(ReportRegistry::class);
        $found = [];
        foreach ($this->screens() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/useReportData(?:<[^>]+>)?\\(\\s*'([^']+)'/", $source, $preview);
            preg_match_all('/reportCode="([^"]+)"/', $source, $print);
            preg_match_all("/report:\\s*'([^']+)'/", $source, $request);
            foreach ([...$preview[1], ...$print[1], ...$request[1]] as $code) {
                $found[] = $code;
                $this->assertTrue($registry->has($code), basename($file)." meminta laporan `{$code}` yang tidak terdaftar di ReportRegistry.");
            }
            // Pratinjau dan Cetak di satu halaman laporan membaca laporan yang sama.
            $this->assertSame(array_unique($preview[1]), array_unique($print[1]), basename($file).': kode pratinjau dan kode cetak berbeda.');
        }

        $this->assertContains('laporan-pemeliharaan-aset', $found);
        $this->assertContains('daftar-mutasi-aset', $found);
    }

    public function test_report_pages_use_the_report_that_their_menu_names(): void
    {
        $dir = dirname(__DIR__, 2).'/ui/laporan';
        $this->assertStringContainsString("useReportData<MaintenanceReportRow>('laporan-pemeliharaan-aset')", (string) file_get_contents($dir.'/LaporanPemeliharaanAsetPage.tsx'));
        $this->assertStringContainsString("useReportData<MutationReportRow>('daftar-mutasi-aset')", (string) file_get_contents($dir.'/LaporanMutasiAsetPage.tsx'));
    }

    /** @return list<string> */
    private function screens(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'tsx') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
