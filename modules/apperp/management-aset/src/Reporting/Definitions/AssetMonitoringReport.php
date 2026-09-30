<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoring;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoringLine;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetSpecification;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Support\AssetMonitoringStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Laporan monitoring aset: satu baris per aset yang diperiksa pada monitoring yang sudah selesai.
 *
 * Seluruh nilainya dibaca dari yang dibekukan saat pemeriksaan diselesaikan — status siklus hidup,
 * lokasi tercatat, penanggung jawab, unit, dan nilai buku — bukan dari register hari ini. Aset yang
 * ditemukan di lokasi yang diperiksa padahal tercatat di tempat lain muncul Tidak sesuai, dengan
 * lokasi tercatatnya di kolom sendiri. Laporan pemeriksaan
 * Agustus yang dicetak Desember harus tetap menyebut keadaan Agustus. Monitoring yang masih draf
 * tidak ikut: temuannya belum final.
 *
 * Nama unit dan orang tetap diterjemahkan saat dibaca, sama seperti layar: nama berubah karena sebab
 * di luar aset.
 */
final class AssetMonitoringReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-monitoring-aset';
    }

    public function name(): string
    {
        return 'Laporan monitoring aset';
    }

    public function description(): string
    {
        return 'Hasil pemeriksaan fisik aset yang sudah selesai: keberadaan, kondisi, kecocokan dengan register, dan nilai bukunya saat diperiksa.';
    }

    public function permission(): string
    {
        return 'management-aset.monitoring-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan monitoring aset standar (Excel)', 'Satu baris per aset yang diperiksa: bukti, tanggal, keberadaan, kondisi, hasil, dan nilai buku.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            // Buku penyusutan tidak dipilih di sini: nilainya sudah dibekukan dari buku komersial. Lokasi dan
            // kondisi di laporan ini berarti lokasi yang diperiksa dan kondisi temuan, bukan register hari ini,
            // jadi keduanya disaring di sini sendiri, bukan lewat AssetReportFilters.
            ...array_diff_key(AssetReportFilters::rules(), array_flip(['buku_id', ...self::OWN_LIST_KEYS])),
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            ...self::ownListRules(),
        ];
    }

    /**
     * Dokumen monitoring lalu barisnya. Laporannya satu baris per aset yang diperiksa, jadi filter pada
     * dokumen menyaring semua barisnya, dan filter pada baris hanya meloloskan baris yang cocok.
     */
    public function dataItems(): array
    {
        return [
            new ReportDataItem('monitoring', 'Monitoring', AssetMonitoring::class, 'monitoring', ['kode']),
            new ReportDataItem('baris', 'Baris monitoring', AssetMonitoringLine::class, 'aset_tr_monitoring_aset_details'),
        ];
    }

    public function fields(): array
    {
        return [
            ...array_values(array_filter(AssetReportFilters::fields(), static fn (array $field): bool => $field['key'] !== 'filter_buku')),
            ['key' => 'filter_dari', 'label' => 'Filter tanggal awal', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_sampai', 'label' => 'Filter tanggal akhir', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_kondisi', 'label' => 'Filter kondisi fisik', 'table' => null],
            ['key' => 'filter_lokasi', 'label' => 'Filter lokasi', 'table' => null],
            ['key' => 'filter_penanggung_jawab', 'label' => 'Filter penanggung jawab', 'table' => null],
            ['key' => 'filter_unit', 'label' => 'Filter unit organisasi', 'table' => null],
            ['key' => 'jumlah_aset', 'label' => 'Jumlah aset diperiksa', 'table' => null],
            ['key' => 'jumlah_tidak_sesuai', 'label' => 'Jumlah tidak sesuai', 'table' => null],
            ['key' => 'total_nilai_perolehan', 'label' => 'Total nilai perolehan', 'table' => null, 'type' => 'money'],
            ['key' => 'total_nilai_buku', 'label' => 'Total nilai buku akhir', 'table' => null, 'type' => 'money'],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.tgl_monitoring', 'label' => 'Tanggal monitoring', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.no_bukti', 'label' => 'No. bukti', 'table' => 'baris'],
            ['key' => 'baris.lokasi', 'label' => 'Lokasi diperiksa', 'table' => 'baris'],
            ['key' => 'baris.asset_kode', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.asset_nama', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.spesifikasi', 'label' => 'Spesifikasi', 'table' => 'baris'],
            ['key' => 'baris.kondisi_sistem', 'label' => 'Status di sistem', 'table' => 'baris'],
            ['key' => 'baris.lokasi_tercatat', 'label' => 'Lokasi tercatat', 'table' => 'baris'],
            ['key' => 'baris.kondisi_fisik', 'label' => 'Keberadaan fisik', 'table' => 'baris'],
            ['key' => 'baris.kondisi_aset', 'label' => 'Kondisi fisik', 'table' => 'baris'],
            ['key' => 'baris.status_monitoring', 'label' => 'Status monitoring', 'table' => 'baris'],
            ['key' => 'baris.keterangan', 'label' => 'Keterangan', 'table' => 'baris'],
            ['key' => 'baris.nilai_perolehan', 'label' => 'Nilai perolehan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.akumulasi_penyusutan', 'label' => 'Akumulasi penyusutan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.nilai_buku_akhir', 'label' => 'Nilai buku akhir', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.penanggung_jawab', 'label' => 'Penanggung jawab', 'table' => 'baris'],
            ['key' => 'baris.unit_organisasi', 'label' => 'Unit organisasi', 'table' => 'baris'],
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $lines = 'aset_tr_monitoring_aset_details';
        $query = AssetMonitoringLine::query()
            ->join('aset_tr_monitoring_aset as monitoring', function (JoinClause $join) use ($lines): void {
                $join->on('monitoring.id', '=', "{$lines}.monitoring_aset_id")->on('monitoring.tenant_id', '=', "{$lines}.tenant_id");
            })
            ->join('aset_tr_aset as aset', function (JoinClause $join) use ($lines): void {
                $join->on('aset.id', '=', "{$lines}.aset_id")->on('aset.tenant_id', '=', "{$lines}.tenant_id");
            })
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn (JoinClause $join) => $join->on('lokasi.id', '=', 'monitoring.lokasi_aset_id')->on('lokasi.tenant_id', '=', 'monitoring.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi_tercatat', fn (JoinClause $join) => $join->on('lokasi_tercatat.id', '=', "{$lines}.sistem_lokasi_id")->on('lokasi_tercatat.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn (JoinClause $join) => $join->on('kondisi.id', '=', "{$lines}.kondisi_aset_id")->on('kondisi.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_model_aset as model', fn (JoinClause $join) => $join->on('model.id', '=', 'aset.model_aset_id')->on('model.tenant_id', '=', 'aset.tenant_id'))
            ->where('monitoring.status', AssetMonitoringStatus::COMPLETED)
            ->whereNull('monitoring.deleted_at');

        app(OrganizationScope::class)->query($query, $context->request(), 'monitoring.legal_entity_id', 'monitoring.responsible_org_unit_id');
        AssetReportFilters::apply($query, array_diff_key($parameters, array_flip(['lokasi_aset_id', 'kondisi_aset_id'])), 'aset');
        foreach ($this->dataItems() as $item) {
            AdditionalFilters::apply($query, $item, $parameters, $context);
        }
        foreach (['dari' => '>=', 'sampai' => '<='] as $parameter => $operator) {
            if (! empty($parameters[$parameter])) {
                $query->where('monitoring.tanggal', $operator, $parameters[$parameter]);
            }
        }
        // Pilih banyak: beberapa pilihan dalam satu filter berarti salah satunya (atau).
        foreach ([
            'kondisi_aset_id' => "{$lines}.kondisi_aset_id",
            'lokasi_aset_id' => 'monitoring.lokasi_aset_id',
            'penanggung_jawab_user_id' => "{$lines}.sistem_custodian_user_id",
            'org_unit_id' => "{$lines}.sistem_org_unit_id",
        ] as $parameter => $column) {
            $ids = self::ids($parameters[$parameter] ?? null);
            if ($ids !== []) {
                $query->whereIn($column, $ids);
            }
        }

        $rows = $query
            ->orderBy('monitoring.tanggal')
            ->orderBy('monitoring.kode')
            ->orderBy("{$lines}.line_number")
            ->toBase()
            ->get([
                "{$lines}.*",
                'monitoring.kode as no_bukti', 'monitoring.tanggal as tanggal_monitoring',
                'lokasi.nama as lokasi_nama', 'lokasi_tercatat.nama as lokasi_tercatat_nama',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.model_number', 'aset.serial_number',
                'model.nama as model_nama', 'kondisi.nama as kondisi_nama',
            ]);

        $directory = app(DirektoriAset::class);
        $totalAcquisition = BigDecimal::zero();
        $totalBookValue = BigDecimal::zero();
        $mismatches = 0;
        $table = [];
        foreach ($rows as $index => $row) {
            $acquisition = $row->nilai_perolehan === null ? null : (string) $row->nilai_perolehan;
            $bookValue = $row->nilai_buku === null ? null : (string) $row->nilai_buku;
            $totalAcquisition = $totalAcquisition->plus($acquisition ?? 0);
            $totalBookValue = $totalBookValue->plus($bookValue ?? 0);
            $mismatches += $row->hasil === AssetMonitoringStatus::MISMATCH ? 1 : 0;

            $table[] = [
                'nomor' => $index + 1,
                'tgl_monitoring' => substr((string) $row->tanggal_monitoring, 0, 10),
                'no_bukti' => $row->no_bukti,
                'lokasi' => $row->lokasi_nama ?? '—',
                'asset_kode' => $row->aset_kode,
                'asset_nama' => $row->aset_nama,
                'spesifikasi' => AssetSpecification::describe($row->model_nama, $row->model_number, $row->serial_number),
                'kondisi_sistem' => StatusAset::label($row->sistem_lifecycle_state),
                'lokasi_tercatat' => $row->lokasi_tercatat_nama ?? '—',
                'kondisi_fisik' => $row->ada ? 'Ada' : 'Tidak ada',
                'kondisi_aset' => $row->kondisi_nama ?? '—',
                'status_monitoring' => $row->hasil === AssetMonitoringStatus::MATCH ? 'Sesuai' : 'Tidak sesuai',
                'keterangan' => $row->keterangan ?: '—',
                'nilai_perolehan' => $acquisition,
                'akumulasi_penyusutan' => $row->akumulasi_penyusutan === null ? null : (string) $row->akumulasi_penyusutan,
                'nilai_buku_akhir' => $bookValue,
                'penanggung_jawab' => $directory->namaOrang($context->tenantId, $row->sistem_custodian_user_id) ?? '—',
                'unit_organisasi' => $directory->namaUnit($context->tenantId, $row->sistem_org_unit_id) ?? '—',
            ];
        }

        $filters = AssetReportFilters::names($parameters);
        unset($filters['filter_buku']);

        return new ReportData(
            fields: [
                ...$filters,
                'filter_dari' => $parameters['dari'] ?? 'Semua',
                'filter_sampai' => $parameters['sampai'] ?? 'Semua',
                'filter_kondisi' => $this->filterNames($parameters['kondisi_aset_id'] ?? null, static fn (string $id): mixed => KondisiAset::withTrashed()->whereKey($id)->value('nama')),
                'filter_lokasi' => $this->filterNames($parameters['lokasi_aset_id'] ?? null, static fn (string $id): mixed => LokasiAset::withTrashed()->whereKey($id)->value('nama')),
                'filter_penanggung_jawab' => $this->filterNames($parameters['penanggung_jawab_user_id'] ?? null, static fn (string $id): ?string => $directory->namaOrang($context->tenantId, $id)),
                'filter_unit' => $this->filterNames($parameters['org_unit_id'] ?? null, static fn (string $id): ?string => $directory->namaUnit($context->tenantId, $id)),
                'jumlah_aset' => count($table),
                'jumlah_tidak_sesuai' => $mismatches,
                'total_nilai_perolehan' => (string) $totalAcquisition,
                'total_nilai_buku' => (string) $totalBookValue,
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $table],
            fileName: 'laporan-monitoring-aset-'.$context->now()->format('Ymd-Hi'),
        );
    }

    /** Filter pilih banyak milik laporan ini sendiri; lihat parameterRules(). */
    private const OWN_LIST_KEYS = ['kondisi_aset_id', 'lokasi_aset_id', 'penanggung_jawab_user_id', 'org_unit_id'];

    /** @return array<string, list<string>> */
    private static function ownListRules(): array
    {
        $rules = [];
        foreach (self::OWN_LIST_KEYS as $key) {
            $rules[$key] = ['nullable', 'array', 'max:50'];
            $rules[$key.'.*'] = $key === 'penanggung_jawab_user_id' ? ['string', 'max:64'] : ['ulid'];
        }

        return $rules;
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter($values, static fn (mixed $id): bool => is_string($id) && $id !== '')));
    }

    /**
     * Nama pilihan untuk kepala laporan, dipisah koma, dalam urutan pilihannya.
     *
     * @param  callable(string): mixed  $lookup
     */
    private function filterNames(mixed $value, callable $lookup): string
    {
        $ids = self::ids($value);
        if ($ids === []) {
            return 'Semua';
        }

        return implode(', ', array_map(static function (string $id) use ($lookup): string {
            $name = $lookup($id);

            return is_string($name) && $name !== '' ? $name : 'Tidak ditemukan';
        }, $ids));
    }
}
