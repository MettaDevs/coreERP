<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Setiap layar module yang meminta laporan memakai kode yang benar-benar terdaftar.
 *
 * Halaman laporan meneruskan state `useReportData('<kode>')` ke `ReportPageLayout`; layout mencetak
 * kode dan filter dari state itu juga. Tombol cetak langsung tetap diperiksa lewat `reportCode="<kode>"`
 * atau `requestPrint({ report: '<kode>' })` pada layar transaksi.
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
            [$preview, $print] = $this->screenReportCodes($source, basename($file));
            preg_match_all("/report:\\s*'([^']+)'/", $source, $request);
            foreach ([...$preview, ...$print, ...$request[1]] as $code) {
                $found[] = $code;
                $this->assertTrue($registry->has($code), basename($file)." meminta laporan `{$code}` yang tidak terdaftar di ReportRegistry.");
            }
        }

        $this->assertContains('laporan-pemeliharaan-aset', $found);
        $this->assertContains('daftar-mutasi-aset', $found);
    }

    public function test_shared_layout_prints_the_report_code_and_filters_from_its_preview_state(): void
    {
        $dir = dirname(__DIR__, 2).'/ui/laporan/_shared';
        $this->assertSharedReportFlow(
            (string) file_get_contents($dir.'/ReportPageLayout.tsx'),
            (string) file_get_contents($dir.'/useReportData.ts'),
        );
    }

    public function test_guard_rejects_a_layout_bound_to_an_unrelated_state(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->screenReportCodes(<<<'TSX'
            const { filterState } = useReportData<Row>('laporan-monitoring-aset');
            <ReportPageLayout<Row> filterState={otherState} />
            TSX, 'unrelated-state.tsx');
    }

    public function test_guard_rejects_a_print_code_that_differs_from_the_preview(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->screenReportCodes(<<<'TSX'
            const { filterState } = useReportData<Row>('laporan-monitoring-aset');
            <ReportPageLayout<Row> filterState={filterState} />
            <PrintButton reportCode="laporan-penyusutan-aset" />
            TSX, 'different-code.tsx');
    }

    public function test_guard_rejects_a_shared_layout_that_overrides_its_report_code(): void
    {
        $dir = dirname(__DIR__, 2).'/ui/laporan/_shared';
        $layout = (string) file_get_contents($dir.'/ReportPageLayout.tsx');
        $mutated = str_replace('report: reportCode,', "report: 'laporan-penyusutan-aset',", $layout, $count);
        $this->assertSame(1, $count);
        $this->expectException(AssertionFailedError::class);
        $this->assertSharedReportFlow($mutated, (string) file_get_contents($dir.'/useReportData.ts'));
    }

    public function test_report_pages_use_the_report_that_their_menu_names(): void
    {
        $dir = dirname(__DIR__, 2).'/ui/laporan';
        $this->assertStringContainsString("useReportData<MaintenanceReportRow>('laporan-pemeliharaan-aset')", (string) file_get_contents($dir.'/LaporanPemeliharaanAsetPage.tsx'));
        $this->assertStringContainsString("useReportData<MutationReportRow>('daftar-mutasi-aset')", (string) file_get_contents($dir.'/LaporanMutasiAsetPage.tsx'));
    }

    /** @return array{list<string>, list<string>} */
    private function screenReportCodes(string $source, string $screen): array
    {
        preg_match_all("/useReportData(?:<[^>]+>)?\\(\\s*'([^']+)'/", $source, $preview);
        preg_match_all('/reportCode="([^"]+)"/', $source, $print);
        preg_match_all("/const\\s*\\{([^}]+)\\}\\s*=\\s*useReportData(?:<[^>]+>)?\\(\\s*'([^']+)'/s", $source, $calls, PREG_SET_ORDER);
        $states = [];
        foreach ($calls as $call) {
            if (preg_match('/(?:^|,)\s*filterState(?:\s*:\s*(\w+))?\s*(?:,|$)/', $call[1], $binding)) {
                $name = $binding[1] ?? 'filterState';
                $this->assertArrayNotHasKey($name, $states, $screen.': state pratinjau dinyatakan lebih dari sekali.');
                $states[$name] = $call[2];
            }
        }

        preg_match_all('/<ReportPageLayout(?:<[^>]+>)?\s(.*?)\/>/s', $source, $layouts);
        $printCodes = $print[1];
        foreach ($layouts[1] as $attributes) {
            $this->assertSame(1, preg_match('/\bfilterState=\{\s*(\w+)\s*\}/', $attributes, $binding), $screen.': layout harus menerima state pratinjau secara langsung.');
            $this->assertArrayHasKey($binding[1], $states, $screen.': state cetak tidak berasal dari useReportData pada layar ini.');
            $printCodes[] = $states[$binding[1]];
        }

        $this->assertSame(array_values(array_unique($preview[1])), array_values(array_unique($printCodes)), $screen.': kode pratinjau dan kode cetak berbeda.');

        return [$preview[1], $printCodes];
    }

    private function assertSharedReportFlow(string $layout, string $hook): void
    {
        $this->assertMatchesRegularExpression('/const\s*\{\s*reportCode\s*,\s*filters\s*\}\s*=\s*filterState\s*;/', $layout);
        $this->assertSame(1, preg_match('/const handlePrint = \(\) => \{(.*?)\n    \};/s', $layout, $handler));
        $this->assertMatchesRegularExpression('/requestPrint\(\{\s*report:\s*reportCode\s*,.*?parameters:\s*toParameters\(filters\)/s', $handler[1]);
        $this->assertStringContainsString('onClick={handlePrint}', $layout);
        $this->assertMatchesRegularExpression('/const\s+filterState:\s*ReportFilterState\s*=\s*\{\s*reportCode\s*,/', $hook);
        $this->assertStringContainsString('const url = `/laporan/${reportCode}', $hook);
        $this->assertMatchesRegularExpression('/await\s+api<ReportApiResponse>\(url\)/', $hook);
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
