<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoring;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoringLine;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassification;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Lampiran dokumen pada record module aset (gap 7, fase 1): register aset dan dokumen transaksinya.
 *
 * Haknya sama dengan membuka dan mengubah record itu di layar aset: permission resource-nya, lalu kebijakan
 * `management-aset.asset-responsibility` atas entitas legal dan unit kerja pemilik record, lewat
 * {@see OrganizationScope} yang juga dipakai controller-nya. Penyaringan tenant dilakukan model
 * (`BelongsToTenant`), jadi `tenantId` dari Core tidak ditulis ulang di query.
 *
 * Status dokumen tidak ikut menentukan: lampiran boleh ditambah pada dokumen yang sudah selesai, seperti
 * lampiran pada dokumen terposting di BC.
 */
final class AssetAttachments implements AttachmentRecordType
{
    private const MODULE = 'management-aset';

    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $resource  resource permission; null berarti dibaca dari `jenis_dokumen` barisnya
     * @param  string  $updatePermission  aksi permission yang berarti boleh mengubah record, termasuk melampirinya
     * @param  class-string<Model>|null  $lineModel
     */
    private function __construct(
        private readonly string $table,
        private readonly string $model,
        private readonly ?string $resource,
        private readonly string $updatePermission,
        private readonly string $organizationUnitColumn,
        private readonly ?string $lineModel = null,
        private readonly ?string $lineForeignKey = null,
    ) {}

    /** @return list<self> */
    public static function types(): array
    {
        return [
            new self('aset_tr_aset', Aset::class, 'aset', 'update', 'responsible_org_unit_id'),
            new self('aset_tr_penerimaan_aset', PenerimaanAset::class, 'penerimaan-aset', 'update', 'responsible_org_unit_id', PenerimaanAsetDetail::class, 'penerimaan_aset_id'),
            new self('aset_tr_mutasi_aset', MutasiAset::class, 'mutasi-aset', 'update', 'responsible_org_unit_id', MutasiAsetDetail::class, 'mutasi_aset_id'),
            // Foto bukti pemeriksaan fisik, pada dokumen atau pada baris asetnya.
            new self('aset_tr_monitoring_aset', AssetMonitoring::class, 'monitoring-aset', 'update', 'responsible_org_unit_id', AssetMonitoringLine::class, 'monitoring_aset_id'),
            new self('aset_tr_pemeliharaan_aset', PemeliharaanAset::class, 'pemeliharaan-aset', 'update', 'responsible_org_unit_id', PemeliharaanAsetDetail::class, 'pemeliharaan_aset_id'),
            new self('aset_tr_perencanaan_aset', PerencanaanAset::class, 'perencanaan-aset', 'update', 'planning_org_unit_id', PerencanaanAsetDetail::class, 'planning_id'),
            // Dasar penurunan atau kenaikan nilai, misalnya laporan penilai, pada dokumen atau barisnya.
            new self('aset_tr_penyesuaian_nilai_aset', AssetValueAdjustment::class, 'penyesuaian-nilai-aset', 'update', 'responsible_org_unit_id', AssetValueAdjustmentLine::class, 'penyesuaian_nilai_aset_id'),
            // Dasar reklasifikasi atau pemecahan aset, misalnya berita acara pemisahan komponen.
            new self('aset_tr_reklasifikasi_aset', AssetReclassification::class, 'reklasifikasi-aset', 'update', 'responsible_org_unit_id', AssetReclassificationLine::class, 'reklasifikasi_aset_id'),
            new self('aset_tr_permintaan_pengadaan_aset', PermintaanPengadaanAset::class, 'permintaan-pembelian-aset', 'update', 'requesting_org_unit_id', PermintaanPengadaanAsetDetail::class, 'request_id'),
            // Dekomisioning, penjualan, dan pemusnahan tidak punya permission ubah; yang boleh membuat dokumennya
            // yang boleh melampirinya (K-20).
            new self('aset_tr_dokumen_siklus_aset', DokumenSiklusAset::class, null, 'create', 'responsible_org_unit_id'),
        ];
    }

    public function recordType(): string
    {
        return $this->table;
    }

    public function moduleId(): string
    {
        return self::MODULE;
    }

    public function dataClass(): DataClass
    {
        return DataClass::CustomerContent;
    }

    public function canRead(string $tenantId, string $recordId): bool
    {
        $resource = $this->resourceOf($recordId);

        return $resource !== null && $this->allows($resource, 'read');
    }

    public function canChange(string $tenantId, string $recordId): bool
    {
        $resource = $this->resourceOf($recordId);

        return $resource !== null && $this->allows($resource, $this->updatePermission);
    }

    public function hasLine(string $tenantId, string $recordId, int $lineNumber): bool
    {
        if ($this->lineModel === null || $this->lineForeignKey === null) {
            return false;
        }

        return $this->lineModel::query()
            ->where($this->lineForeignKey, $recordId)
            ->where('line_number', $lineNumber)
            ->exists();
    }

    /**
     * Resource permission record itu bila ia ada dan berada di dalam lingkup organisasi pengguna; null bila
     * tidak ada, diarsipkan, atau di luar lingkup.
     */
    private function resourceOf(string $recordId): ?string
    {
        $query = $this->model::query()->where($this->table.'.id', $recordId);
        app(OrganizationScope::class)->query($query, request(), $this->table.'.legal_entity_id', $this->table.'.'.$this->organizationUnitColumn);

        if ($this->resource !== null) {
            return $query->exists() ? $this->resource : null;
        }

        $type = $query->value('jenis_dokumen');

        return is_string($type) && in_array($type, DokumenSiklusAsetController::TYPES, true) ? $type : null;
    }

    private function allows(string $resource, string $action): bool
    {
        return app(RequestContext::class)->hasPermission(self::MODULE.'.'.$resource.'.'.$action);
    }
}
