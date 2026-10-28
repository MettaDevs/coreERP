<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SectionSaveOwnershipTest extends TestCase
{
    private const OWNERS = [
        'pengaturan-aset-tetap/PengaturanAsetTetapPage.tsx' => 1,
        'asset-posting-group/AssetPostingGroupPage.tsx' => 1,
        'master/MasterForm.tsx' => 1,
        'master/detail/MasterDetailPage.tsx' => 1,
        // Pembuka dialog "Simpan preset" dan Simpan pada form preset yang mandiri.
        'laporan/_shared/ReportPresets.tsx' => 2,
        'transactions/_shared/LifecycleDocumentPage.tsx' => 1,
        'transactions/asset-classifications/AssetClassificationPage.tsx' => 1,
        'transactions/counter-readings/CounterReadingPage.tsx' => 1,
        'transactions/disposal/DisposalDetailPage.tsx' => 1,
        'transactions/downtime/DowntimePage.tsx' => 1,
        'transactions/insurance/InsurancePage.tsx' => 1,
        'transactions/inventarisasi-aset/AsetDetailPage.tsx' => 1,
        'transactions/inventarisasi-aset/PenerimaanDetailPage.tsx' => 1,
        'transactions/maintenance-requests/MaintenanceRequestDetailPage.tsx' => 1,
        'transactions/monitoring-aset/MonitoringDetailPage.tsx' => 1,
        'transactions/mutasi-aset/MutasiDetailPage.tsx' => 1,
        'transactions/pemeliharaan-aset/StatusValidationPage.tsx' => 1,
        'transactions/pemeliharaan-aset/WorkOrderDetailPage.tsx' => 1,
        'transactions/perencanaan-aset/PlanningPage.tsx' => 1,
        'transactions/permintaan-pembelian-aset/PurchaseRequestPage.tsx' => 1,
        'transactions/reclassification/ReclassificationDetailPage.tsx' => 1,
        'transactions/value-adjustment/ValueAdjustmentDetailPage.tsx' => 1,
        // Garansi dan kontrak mempunyai kartu record serta toolbar induk masing-masing.
        'transactions/warranty/WarrantyPage.tsx' => 2,
    ];

    public function test_save_buttons_belong_to_record_form_owners(): void
    {
        $root = dirname(__DIR__, 2).'/ui/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'tsx') {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
                $this->assertSaveOwner($path, (string) file_get_contents($file->getPathname()));
            }
        }
    }

    public function test_guard_rejects_the_former_embedded_save_pattern(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertSaveOwner('master/detail/JenisAsetModels.tsx', <<<'TSX'
            <DialogToolbar><ActionButton action="save" onClick={() => void save()} /></DialogToolbar>
            TSX);
    }

    public function test_master_sections_do_not_write_independently(): void
    {
        $sections = [
            'GroupBookMatrix', 'JenisAsetAtribut', 'TipeAtributNilai',
            'detail/AssetTypeConditions', 'detail/JenisAsetCounterTypes',
            'detail/JenisAsetMaintenanceJobTypes', 'detail/JenisAsetModels',
            'detail/MaintenanceChecklistTemplateLines', 'detail/MaintenanceChecklistVariableValues',
            'detail/MaintenanceJobTypeDetails', 'detail/MaintenancePlanLines',
            'detail/MaintenancePlanTargets', 'detail/PabrikanModels', 'detail/ModelAsetFormSheet',
        ];
        foreach ($sections as $section) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/ui/master/'.$section.'.tsx');
            $this->assertDoesNotMatchRegularExpression('/\bmethod\s*:\s*[\'\"](?:POST|PUT|PATCH|DELETE)[\'\"]/', $source,
                $section.' harus mengirim draft ke form induk, bukan menyimpan melalui request sendiri.');
        }
    }

    private function assertSaveOwner(string $path, string $source): void
    {
        preg_match_all('/\baction\s*=\s*(?:\{\s*)?[\'\"]save[\'\"]/', $source, $actions);
        preg_match_all('/<(?:Button|button)\b[^>]*>(?:(?!\/>|<\/(?:Button|button)>).)*\bSimpan\b/s', $source, $buttons);
        $this->assertLessThanOrEqual(self::OWNERS[$path] ?? 0, count($actions[0]) + count($buttons[0]),
            $path.': Simpan hanya boleh berada pada toolbar form induk. Bagian di dalamnya harus menampung draft; dialog rincian memakai Tambah/Selesai.');
    }
}
