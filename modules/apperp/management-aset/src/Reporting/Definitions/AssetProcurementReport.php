<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\VendorDirectory;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Services\AssetUnitOfMeasureDirectory;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PenerimaanStatus;
use stdClass;

/**
 * Laporan pengadaan aset: mengikuti barang yang diadakan dari rencana, ke permintaan pembelian, sampai
 * penerimaan. Satu baris per **kebutuhan**: satu baris rencana beserta semua baris permintaan yang memenuhinya,
 * atau satu baris permintaan yang tidak berasal dari rencana. Jumlah yang direncanakan, diminta, dan sudah
 * diterima ada di baris yang sama, jadi sisanya terbaca tanpa menjumlah dua kali.
 *
 * Bedanya dengan `laporan-perolehan-aset`: daftar perolehan berangkat dari register — aset yang sudah ada dan
 * nilainya. Laporan ini berangkat dari kebutuhan, termasuk yang belum diminta atau belum datang sama sekali,
 * dan berhenti di penerimaan; penerimaan tanpa permintaan pembelian (hibah, saldo awal, pembelian langsung)
 * tidak ikut karena tidak ada kebutuhan yang dipenuhinya, dan tetap terbaca di daftar perolehan.
 *
 * Yang dibaca sesuai isi data, tidak lebih:
 *
 * - **Diminta** = jumlah baris permintaan yang tidak dibatalkan dan tidak diarsipkan. Permintaan belum punya
 *   alur persetujuan, jadi draf ikut dihitung.
 * - **Diterima** = jumlah baris penerimaan **selesai** yang menunjuk baris permintaan itu, yaitu jumlah aset
 *   yang sudah lahir; penerimaan draf belum melahirkan apa pun. Nilainya nilai baris tanpa PPN, dibulatkan sekali
 *   per baris seperti jurnal perolehan (K-20).
 * - **Nilai rencana** adalah perkiraan di baris rencana. Permintaan tidak memikul harga, jadi tidak ada nilai
 *   diminta.
 * - **Vendor** hanya ada di penerimaan; rencana dan permintaan tidak menyebut pemasok.
 *
 * Lingkup unit kerja ditegakkan pada setiap dokumen: rencana, permintaan, dan penerimaan di luar jangkauan
 * pengguna tidak dibaca dan tidak dihitung.
 *
 * Padanan: Dynamics 365 F&O tidak punya satu laporan untuk rantai ini; yang terdekat daftar baris *purchase
 * requisition* dengan statusnya lalu *product receipt* pesanan yang lahir darinya. Business Central tidak
 * punya rencana maupun permintaan; yang terdekat report 709 "Inventory Purchase Orders" (sisa yang belum
 * diterima per baris pesanan).
 */
final class AssetProcurementReport implements ReportDefinition
{
    public const NOT_REQUESTED = 'belum_diminta';

    public const NOT_RECEIVED = 'belum_diterima';

    public const PARTIALLY_RECEIVED = 'diterima_sebagian';

    public const FULLY_RECEIVED = 'diterima_penuh';

    /** Label status kebutuhan, dalam urutan alurnya. */
    public const STATUSES = [
        self::NOT_REQUESTED => 'Belum diminta',
        self::NOT_RECEIVED => 'Belum diterima',
        self::PARTIALLY_RECEIVED => 'Diterima sebagian',
        self::FULLY_RECEIVED => 'Diterima penuh',
    ];

    private const CANCELLED_REQUEST = 'cancelled';

    private const PLAN_LINES = 'aset_tr_perencanaan_aset_details';

    private const REQUEST_LINES = 'aset_tr_permintaan_pengadaan_aset_details';

    private const RECEIPT_LINES = 'aset_tr_penerimaan_aset_details';

    public function code(): string
    {
        return 'laporan-pengadaan-aset';
    }

    public function name(): string
    {
        return 'Laporan pengadaan aset';
    }

    public function description(): string
    {
        return 'Mengikuti pengadaan dari rencana, permintaan pembelian, sampai penerimaan: berapa yang direncanakan, diminta, dan sudah diterima per barang, beserta sisanya.';
    }

    public function permission(): string
    {
        return 'management-aset.permintaan-pembelian-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan pengadaan aset standar (Excel)', 'Satu baris per barang yang direncanakan atau diminta: jumlah dan nilai rencana, permintaan, penerimaan, dan sisanya.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'jenis_aset_id' => ['nullable', 'array', 'max:100'],
            'jenis_aset_id.*' => ['ulid'],
            'org_unit_id' => ['nullable', 'array', 'max:50'],
            'org_unit_id.*' => ['ulid'],
            'status' => ['nullable', 'array', 'max:4'],
            'status.*' => ['string', 'in:'.implode(',', array_keys(self::STATUSES))],
        ];
    }

    /**
     * Dokumen rencana. Filter padanya hanya bermakna bagi baris yang punya rencana, jadi permintaan di luar
     * rencana tidak ikut selama filter ini diisi.
     */
    public function dataItems(): array
    {
        return [
            new ReportDataItem('rencana', 'Rencana pengadaan', PerencanaanAset::class, 'rencana', ['kode']),
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_dari' => ['Filter tanggal awal', 'date'],
            'filter_sampai' => ['Filter tanggal akhir', 'date'],
            'filter_jenis' => ['Filter jenis aset', null],
            'filter_unit' => ['Filter unit organisasi', null],
            'filter_status' => ['Filter status', null],
            'jumlah_baris' => ['Jumlah baris', 'number'],
            'total_nilai_rencana' => ['Total nilai rencana', 'money'],
            'total_nilai_diterima' => ['Total nilai diterima', 'money'],
            'dicetak_pada' => ['Tanggal cetak', 'datetime'],
        ];
        $rows = [
            'nomor' => ['No.', null],
            'no_rencana' => ['No. rencana', null],
            'tanggal_rencana' => ['Tanggal rencana', 'date'],
            'tahun_anggaran' => ['Tahun anggaran', null],
            'sumber_dana' => ['Sumber dana', null],
            'item_aset' => ['Item aset', null],
            'spesifikasi' => ['Spesifikasi', null],
            'satuan' => ['Satuan', null],
            'jumlah_rencana' => ['Jumlah rencana', 'number'],
            'nilai_rencana' => ['Nilai rencana', 'money'],
            'no_permintaan' => ['No. permintaan', null],
            'tanggal_permintaan' => ['Tanggal permintaan', 'date'],
            'jumlah_diminta' => ['Jumlah diminta', 'number'],
            'belum_diminta' => ['Belum diminta', 'number'],
            'no_penerimaan' => ['No. penerimaan', null],
            'tanggal_penerimaan' => ['Tanggal penerimaan terakhir', 'date'],
            'vendor' => ['Vendor', null],
            'jumlah_diterima' => ['Jumlah diterima', 'number'],
            'nilai_diterima' => ['Nilai diterima', 'money'],
            'belum_diterima' => ['Belum diterima', 'number'],
            'unit_organisasi' => ['Unit organisasi', null],
            'status' => ['Status', null],
        ];

        $describe = static fn (string $key, array $spec, ?string $table): array => ['key' => $key, 'label' => $spec[0], 'table' => $table]
            + ($spec[1] === null ? [] : ['type' => $spec[1]]);

        return [
            ...array_map(static fn (string $key, array $spec): array => $describe($key, $spec, null), array_keys($header), $header),
            ...array_map(static fn (string $key, array $spec): array => $describe('baris.'.$key, $spec, 'baris'), array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $planItem = $this->dataItems()[0];
        $plans = $this->planLines($context, $parameters, $planItem);
        // Filter tambahan pada rencana tidak dapat dipenuhi baris tanpa rencana.
        $unplanned = AdditionalFilters::active($planItem, $parameters) ? collect() : $this->unplannedRequestLines($context, $parameters);

        $planRequests = $this->requestLinesForPlans($context, array_values(array_map('strval', $plans->pluck('id')->all())));
        $requestIds = array_values(array_unique([
            ...array_map('strval', $planRequests->pluck('id')->all()),
            ...array_map('strval', $unplanned->pluck('id')->all()),
        ]));
        $receipts = $this->receiptLinesForRequests($context, $requestIds)->groupBy('permintaan_pembelian_detail_id');
        $requestsByPlanLine = $planRequests->groupBy('planning_detail_id');

        $directory = app(AssetOrganizationDirectory::class);
        $units = $this->unitNames($context, $unplanned);
        $vendors = [];
        $vendorName = static function (string $id) use (&$vendors, $context): string {
            if (! array_key_exists($id, $vendors)) {
                $vendor = app(VendorDirectory::class)->find($context->tenantId, $id);
                $vendors[$id] = $vendor === null ? 'Tidak ditemukan' : $vendor['name'];
            }

            return $vendors[$id];
        };

        $needs = [];
        foreach ($plans as $plan) {
            /** @var Collection<int, stdClass> $requests */
            $requests = $requestsByPlanLine->get($plan->id, collect());
            $needs[] = [
                'sort' => [(string) $plan->planned_on, (string) $plan->rencana_kode, (int) $plan->line_number],
                'unit_id' => $plan->planning_org_unit_id,
                'row' => [
                    'no_rencana' => $plan->rencana_kode,
                    'tanggal_rencana' => self::date($plan->planned_on),
                    'tahun_anggaran' => (string) $plan->planning_year,
                    'sumber_dana' => $plan->funding_source ?: '—',
                    'item_aset' => $plan->nama_aset,
                    'spesifikasi' => $plan->requested_specification ?: '—',
                    'satuan' => $plan->unit ?: '—',
                    'jumlah_rencana' => self::quantity($plan->quantity),
                    'nilai_rencana' => (string) BigDecimal::of((string) $plan->estimated_total_price)->toScale(2),
                ],
                'requests' => $requests,
                'planned' => BigDecimal::of((string) $plan->quantity),
            ];
        }
        foreach ($unplanned as $request) {
            $needs[] = [
                'sort' => [self::date($request->requested_on), (string) $request->permintaan_kode, (int) $request->line_number],
                'unit_id' => $request->requesting_org_unit_id,
                'row' => [
                    'no_rencana' => '—',
                    'tanggal_rencana' => null,
                    'tahun_anggaran' => '—',
                    'sumber_dana' => '—',
                    'item_aset' => $request->nama_aset,
                    'spesifikasi' => $request->specification ?: '—',
                    'satuan' => $units[$request->satuan_id] ?? '—',
                    'jumlah_rencana' => null,
                    'nilai_rencana' => null,
                ],
                'requests' => collect([$request]),
                'planned' => null,
            ];
        }
        usort($needs, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $rounding = app(CurrencyRounding::class);
        $statusFilter = self::ids($parameters['status'] ?? null);
        $table = [];
        $totalPlanned = BigDecimal::zero();
        $totalReceived = BigDecimal::zero();
        foreach ($needs as $need) {
            /** @var Collection<int, stdClass> $requests */
            $requests = $need['requests'];
            $requested = BigDecimal::zero();
            $received = BigDecimal::zero();
            $receivedValue = BigDecimal::zero();
            $receiptCodes = [];
            $receiptDates = [];
            $vendorIds = [];
            foreach ($requests as $request) {
                $requested = $requested->plus((string) $request->quantity);
                foreach ($receipts->get($request->id, collect()) as $line) {
                    $received = $received->plus((int) $line->jumlah);
                    $receivedValue = $receivedValue->plus($rounding->roundAmount(
                        $context->tenantId,
                        (string) BigDecimal::of((string) $line->nilai_per_unit)->multipliedBy((int) $line->jumlah),
                        (string) $line->currency_code,
                    ));
                    $receiptCodes[] = (string) $line->penerimaan_kode;
                    $receiptDates[] = self::date($line->penerimaan_tanggal);
                    if ($line->vendor_id !== null) {
                        $vendorIds[] = (string) $line->vendor_id;
                    }
                }
            }

            $status = match (true) {
                $requested->isZero() => self::NOT_REQUESTED,
                $received->isZero() => self::NOT_RECEIVED,
                $received->isLessThan($requested) => self::PARTIALLY_RECEIVED,
                default => self::FULLY_RECEIVED,
            };
            if ($statusFilter !== [] && ! in_array($status, $statusFilter, true)) {
                continue;
            }

            $planned = $need['planned'];
            if ($need['row']['nilai_rencana'] !== null) {
                $totalPlanned = $totalPlanned->plus($need['row']['nilai_rencana']);
            }
            $totalReceived = $totalReceived->plus($receivedValue);
            $requestDates = array_map(static fn (stdClass $request): string => self::date($request->requested_on), $requests->all());
            sort($requestDates);
            rsort($receiptDates);

            $table[] = [
                'nomor' => count($table) + 1,
                ...$need['row'],
                'no_permintaan' => self::list(array_map(static fn (stdClass $request): string => (string) $request->permintaan_kode, $requests->all())),
                'tanggal_permintaan' => $requestDates[0] ?? null,
                'jumlah_diminta' => self::quantity($requested),
                'belum_diminta' => $planned === null ? null : self::quantity(self::remaining($planned, $requested)),
                'no_penerimaan' => self::list($receiptCodes),
                'tanggal_penerimaan' => $receiptDates[0] ?? null,
                'vendor' => self::list(array_map($vendorName, array_values(array_unique($vendorIds)))),
                'jumlah_diterima' => self::quantity($received),
                'nilai_diterima' => (string) $receivedValue->toScale(2),
                'belum_diterima' => self::quantity(self::remaining($requested, $received)),
                'unit_organisasi' => $directory->unitName($context->tenantId, $need['unit_id']) ?? '—',
                'status' => self::STATUSES[$status],
            ];
        }

        return new ReportData(
            fields: [
                'filter_dari' => $parameters['dari'] ?? 'Semua',
                'filter_sampai' => $parameters['sampai'] ?? 'Semua',
                'filter_jenis' => AssetReportFilters::names(['jenis_aset_id' => $parameters['jenis_aset_id'] ?? null])['filter_jenis'],
                'filter_unit' => $this->filterNames($parameters['org_unit_id'] ?? null, static fn (string $id): ?string => $directory->unitName($context->tenantId, $id)),
                'filter_status' => $this->filterNames($parameters['status'] ?? null, static fn (string $status): ?string => self::STATUSES[$status] ?? null),
                'jumlah_baris' => count($table),
                'total_nilai_rencana' => (string) $totalPlanned->toScale(2),
                'total_nilai_diterima' => (string) $totalReceived->toScale(2),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $table],
            fileName: 'laporan-pengadaan-aset-'.$context->now()->format('Ymd-Hi'),
        );
    }

    /**
     * Baris rencana yang tidak diarsipkan, dalam jangkauan pengguna, menurut tanggal rencana.
     *
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, stdClass>
     */
    private function planLines(ReportContext $context, array $parameters, ReportDataItem $planItem): Collection
    {
        $lines = self::PLAN_LINES;
        // Tabel utama tanpa alias: penyaringan tenant disisipkan scope model dengan nama tabel sebenarnya.
        $query = PerencanaanAsetDetail::query()
            ->join('aset_tr_perencanaan_aset as rencana', fn (JoinClause $join) => $join->on('rencana.id', '=', "{$lines}.planning_id")->on('rencana.tenant_id', '=', "{$lines}.tenant_id"))
            // `join` biasa tidak melewati soft delete model rencana.
            ->whereNull('rencana.deleted_at');

        app(OrganizationScope::class)->query($query, $context->request(), 'rencana.legal_entity_id', 'rencana.planning_org_unit_id');
        $this->filter($query, $parameters, 'rencana.planned_on', "{$lines}.jenis_aset_id", 'rencana.planning_org_unit_id');
        AdditionalFilters::apply($query, $planItem, $parameters, $context);

        return $query->toBase()->get([
            "{$lines}.id", "{$lines}.line_number", "{$lines}.nama_aset", "{$lines}.unit", "{$lines}.quantity",
            "{$lines}.requested_specification", "{$lines}.estimated_total_price",
            'rencana.kode as rencana_kode', 'rencana.planned_on', 'rencana.planning_year', 'rencana.funding_source',
            'rencana.planning_org_unit_id',
        ]);
    }

    /**
     * Baris permintaan yang tidak menunjuk baris rencana yang masih ada: permintaan di luar rencana, atau yang
     * rencananya sudah diarsipkan. Menurut tanggal permintaan.
     *
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, stdClass>
     */
    private function unplannedRequestLines(ReportContext $context, array $parameters): Collection
    {
        $lines = self::REQUEST_LINES;
        $query = $this->requestQuery($context)
            ->where(fn (Builder $query) => $query
                ->whereNull("{$lines}.planning_detail_id")
                ->orWhereNotExists(fn (QueryBuilder $plan) => $plan->selectRaw('1')
                    ->from(self::PLAN_LINES.' as baris_rencana')
                    ->join('aset_tr_perencanaan_aset as rencana_asal', fn (JoinClause $join) => $join->on('rencana_asal.id', '=', 'baris_rencana.planning_id')->on('rencana_asal.tenant_id', '=', 'baris_rencana.tenant_id'))
                    ->whereColumn('baris_rencana.id', "{$lines}.planning_detail_id")
                    ->whereColumn('baris_rencana.tenant_id', "{$lines}.tenant_id")
                    ->whereNull('rencana_asal.deleted_at')));
        $this->filter($query, $parameters, 'permintaan.requested_on', "{$lines}.jenis_aset_id", 'permintaan.requesting_org_unit_id');

        return $query->toBase()->get([
            "{$lines}.id", "{$lines}.line_number", "{$lines}.nama_aset", "{$lines}.satuan_id", "{$lines}.quantity",
            "{$lines}.specification", 'permintaan.kode as permintaan_kode', 'permintaan.requested_on',
            'permintaan.requesting_org_unit_id',
        ]);
    }

    /**
     * Baris permintaan yang memenuhi baris-baris rencana ini.
     *
     * @param  list<string>  $planLineIds
     * @return Collection<int, stdClass>
     */
    private function requestLinesForPlans(ReportContext $context, array $planLineIds): Collection
    {
        $lines = self::REQUEST_LINES;
        $found = collect();
        foreach (array_chunk($planLineIds, 1000) as $chunk) {
            $found = $found->merge($this->requestQuery($context)
                ->whereIn("{$lines}.planning_detail_id", $chunk)
                ->toBase()
                ->get(["{$lines}.id", "{$lines}.planning_detail_id", "{$lines}.quantity", 'permintaan.kode as permintaan_kode', 'permintaan.requested_on']));
        }

        return $found->sortBy([['requested_on', 'asc'], ['permintaan_kode', 'asc']])->values();
    }

    /**
     * Baris permintaan yang masih berlaku, dalam jangkauan pengguna.
     *
     * @return Builder<PermintaanPengadaanAsetDetail>
     */
    private function requestQuery(ReportContext $context): Builder
    {
        $lines = self::REQUEST_LINES;
        $query = PermintaanPengadaanAsetDetail::query()
            ->join('aset_tr_permintaan_pengadaan_aset as permintaan', fn (JoinClause $join) => $join->on('permintaan.id', '=', "{$lines}.request_id")->on('permintaan.tenant_id', '=', "{$lines}.tenant_id"))
            ->whereNull('permintaan.deleted_at')
            ->where('permintaan.status', '!=', self::CANCELLED_REQUEST);
        app(OrganizationScope::class)->query($query, $context->request(), 'permintaan.legal_entity_id', 'permintaan.requesting_org_unit_id');

        return $query;
    }

    /**
     * Baris penerimaan selesai yang memenuhi baris-baris permintaan ini, urut tanggal penerimaan.
     *
     * @param  list<string>  $requestLineIds
     * @return Collection<int, stdClass>
     */
    private function receiptLinesForRequests(ReportContext $context, array $requestLineIds): Collection
    {
        $lines = self::RECEIPT_LINES;
        $found = collect();
        foreach (array_chunk($requestLineIds, 1000) as $chunk) {
            $query = PenerimaanAsetDetail::query()
                ->join('aset_tr_penerimaan_aset as penerimaan', fn (JoinClause $join) => $join->on('penerimaan.id', '=', "{$lines}.penerimaan_aset_id")->on('penerimaan.tenant_id', '=', "{$lines}.tenant_id"))
                ->whereNull('penerimaan.deleted_at')
                ->where('penerimaan.status', PenerimaanStatus::SELESAI)
                ->whereIn("{$lines}.permintaan_pembelian_detail_id", $chunk);
            app(OrganizationScope::class)->query($query, $context->request(), 'penerimaan.legal_entity_id', 'penerimaan.responsible_org_unit_id');
            $found = $found->merge($query->toBase()->get([
                "{$lines}.permintaan_pembelian_detail_id", "{$lines}.jumlah", "{$lines}.nilai_per_unit",
                'penerimaan.kode as penerimaan_kode', 'penerimaan.tanggal as penerimaan_tanggal',
                'penerimaan.vendor_id', 'penerimaan.currency_code',
            ]));
        }

        return $found->sortBy([['penerimaan_tanggal', 'asc'], ['penerimaan_kode', 'asc']])->values();
    }

    /**
     * Periode, jenis aset, dan unit organisasi pada dokumen awal baris itu.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $parameters
     */
    private function filter(Builder $query, array $parameters, string $dateColumn, string $typeColumn, string $unitColumn): void
    {
        if (! empty($parameters['dari'])) {
            $query->where($dateColumn, '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->where($dateColumn, '<=', $parameters['sampai']);
        }
        foreach (['jenis_aset_id' => $typeColumn, 'org_unit_id' => $unitColumn] as $parameter => $column) {
            $ids = self::ids($parameters[$parameter] ?? null);
            if ($ids !== []) {
                $query->whereIn($column, $ids);
            }
        }
    }

    /**
     * Nama satuan baris permintaan. Baris rencana menyimpan salinan namanya sendiri; permintaan hanya menyimpan
     * id satuan milik Core. Core hanya menyebut satuan aktif, jadi satuan yang sudah dinonaktifkan tertulis "—".
     *
     * @param  Collection<int, stdClass>  $requests
     * @return array<string, string>
     */
    private function unitNames(ReportContext $context, Collection $requests): array
    {
        if ($requests->isEmpty()) {
            return [];
        }

        return array_column(app(AssetUnitOfMeasureDirectory::class)->active($context->tenantId), 'name', 'id');
    }

    /** Sisa yang belum sampai, tidak pernah negatif: penerimaan berlebih tidak membuat sisa minus. */
    private static function remaining(BigDecimal $target, BigDecimal $done): BigDecimal
    {
        return $done->isGreaterThanOrEqualTo($target) ? BigDecimal::zero() : $target->minus($done);
    }

    /** Jumlah tanpa nol di belakang koma: `5.0000` menjadi `5`, `2.5000` menjadi `2.5`. */
    private static function quantity(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
    }

    private static function date(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }

    /**
     * Nomor dokumen atau nama, masing-masing sekali, dalam urutan pertama muncul; kosong menjadi "—".
     *
     * @param  array<int, string>  $values
     */
    private static function list(array $values): string
    {
        $values = array_values(array_unique($values));

        return $values === [] ? '—' : implode(', ', $values);
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
