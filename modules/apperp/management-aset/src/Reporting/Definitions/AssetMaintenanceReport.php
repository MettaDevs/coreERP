<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetChecklist;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetSpecification;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * Laporan pemeliharaan aset: satu baris per baris pekerjaan work order, yaitu satu aset yang dirawat
 * atau diperbaiki, dengan checklist dan analisa perbaikannya.
 *
 * Padanannya di Business Central adalah "Maintenance - Details" (report 5634): riwayat pemeliharaan per
 * aset dengan filter aset, kelas, dan tanggal. BC tidak punya work order; barisnya entri buku besar
 * pemeliharaan yang memikul nominal. Di sini barisnya baris pekerjaan, dan **biaya tidak ada**: work order
 * belum mencatat biaya bahan, jasa, maupun tagihan vendor, dan spesifikasi QA laporan ini juga tidak
 * memintanya. Kolom biaya baru ditambahkan bila sumber datanya ada; mengisinya dari tebakan berarti
 * mengarang angka.
 *
 * Susunan kolom mengikuti spesifikasi QA (No. bukti, tanggal, kode, item, satuan, jumlah, checklist,
 * analisa, jenis pemeliharaan, unit, PIC), ditambah lokasi, jenis pekerjaan, tingkat layanan, status, dan
 * catatan yang dipakai filter. Satu baris pekerjaan selalu satu aset, jadi satuan dan jumlahnya tetap.
 *
 * Tanggal work order adalah tanggal dibuat menurut zona pengguna, sama dengan `daftar-work-order`; jadwal
 * tidak dipakai karena tersimpan tanpa zona dan bisa kosong. Nama unit dan orang diterjemahkan saat dibaca.
 */
final class AssetMaintenanceReport implements ReportDefinition
{
    /** Satu baris pekerjaan menangani satu aset utuh; aset tidak punya satuan ukur sendiri. */
    private const UNIT = 'Unit';

    /** Filter pilihan banyak milik laporan ini sendiri; lihat parameterRules(). */
    private const OWN_LIST_KEYS = ['lokasi_aset_id', 'status', 'tingkat_layanan_id', 'teknisi_user_id', 'org_unit_id'];

    public function code(): string
    {
        return 'laporan-pemeliharaan-aset';
    }

    public function name(): string
    {
        return 'Laporan pemeliharaan aset';
    }

    public function description(): string
    {
        return 'Riwayat perawatan dan perbaikan aset, satu baris per aset yang dikerjakan, beserta checklist, analisa perbaikan, dan teknisinya.';
    }

    public function permission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan pemeliharaan aset standar (Excel)', 'Satu baris per aset yang dikerjakan: bukti, tanggal, checklist, analisa perbaikan, dan teknisi.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        $rules = [];
        foreach (self::OWN_LIST_KEYS as $key) {
            $rules[$key] = ['nullable', 'array', 'max:50'];
            $rules[$key.'.*'] = match ($key) {
                'status' => ['string', 'in:'.implode(',', WorkOrderStatus::ALL)],
                'teknisi_user_id' => ['string', 'max:64'],
                default => ['ulid'],
            };
        }

        return [
            // Kondisi dan buku penyusutan tidak berarti apa-apa bagi pekerjaan pemeliharaan. Lokasi di laporan ini
            // berarti tempat pekerjaan dikerjakan (disalin ke baris saat dibuat), bukan lokasi aset hari ini, jadi
            // disaring di sini sendiri, bukan lewat AssetReportFilters.
            ...array_diff_key(AssetReportFilters::rules(), array_flip(['buku_id', 'kondisi_aset_id', 'kondisi_aset_id.*', 'lokasi_aset_id', 'lokasi_aset_id.*'])),
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            ...$rules,
        ];
    }

    /**
     * Work order lalu baris pekerjaannya. Laporannya satu baris per baris pekerjaan, jadi filter pada work order
     * menyaring semua barisnya, dan filter pada baris hanya meloloskan baris yang cocok.
     */
    public function dataItems(): array
    {
        return [
            new ReportDataItem('work_order', 'Work order', PemeliharaanAset::class, 'wo', ['kode']),
            new ReportDataItem('baris', 'Baris pekerjaan', PemeliharaanAsetDetail::class, 'aset_tr_pemeliharaan_aset_details'),
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_dari' => ['Filter tanggal awal', 'date'],
            'filter_sampai' => ['Filter tanggal akhir', 'date'],
            'filter_lokasi' => ['Filter lokasi', null],
            'filter_status' => ['Filter status', null],
            'filter_tingkat_layanan' => ['Filter tingkat layanan', null],
            'filter_teknisi' => ['Filter teknisi', null],
            'filter_unit' => ['Filter unit organisasi', null],
            'jumlah_work_order' => ['Jumlah work order', 'number'],
            'jumlah_pekerjaan' => ['Jumlah aset dikerjakan', 'number'],
            'dicetak_pada' => ['Tanggal cetak', 'datetime'],
        ];
        $rows = [
            'nomor' => ['No.', null],
            'no_bukti' => ['No. bukti', null],
            'tanggal' => ['Tanggal work order', 'date'],
            'asset_kode' => ['Kode aset', null],
            'asset_nama' => ['Item aset', null],
            'spesifikasi' => ['Spesifikasi', null],
            'satuan' => ['Satuan', null],
            'jumlah' => ['Jumlah', 'number'],
            'checklist' => ['Item checklist', null],
            'analisa_perbaikan' => ['Analisa perbaikan', null],
            'jenis_pemeliharaan' => ['Jenis pemeliharaan', null],
            'jenis_pekerjaan' => ['Jenis pekerjaan', null],
            'lokasi' => ['Lokasi', null],
            'unit_organisasi' => ['Unit organisasi', null],
            'pic' => ['PIC', null],
            'tingkat_layanan' => ['Tingkat layanan', null],
            'status' => ['Status', null],
            'catatan' => ['Catatan', null],
        ];

        $describe = static fn (string $key, array $spec, ?string $table): array => ['key' => $key, 'label' => $spec[0], 'table' => $table]
            + ($spec[1] === null ? [] : ['type' => $spec[1]]);

        return [
            ...array_values(array_filter(AssetReportFilters::fields(), static fn (array $field): bool => ! in_array($field['key'], ['filter_buku', 'filter_kondisi', 'filter_lokasi'], true))),
            ...array_map(static fn (string $key, array $spec): array => $describe($key, $spec, null), array_keys($header), $header),
            ...array_map(static fn (string $key, array $spec): array => $describe('baris.'.$key, $spec, 'baris'), array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Query berangkat dari baris pekerjaan, karena satuan laporannya aset yang dikerjakan. Tabel utama tidak
        // diberi alias: penyaringan tenant disisipkan scope model dengan nama tabel sebenarnya. Scope organisasi
        // ditegakkan pada kolom work order yang di-join, karena baris tidak memikul unit kerja sendiri.
        $lines = 'aset_tr_pemeliharaan_aset_details';
        $query = PemeliharaanAsetDetail::query()
            ->join('aset_tr_pemeliharaan_aset as wo', fn (JoinClause $join) => $join->on('wo.id', '=', "{$lines}.pemeliharaan_aset_id")->on('wo.tenant_id', '=', "{$lines}.tenant_id"))
            ->join('aset_tr_aset as aset', fn (JoinClause $join) => $join->on('aset.id', '=', "{$lines}.aset_id")->on('aset.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_model_aset as model', fn (JoinClause $join) => $join->on('model.id', '=', 'aset.model_aset_id')->on('model.tenant_id', '=', 'aset.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn (JoinClause $join) => $join->on('lokasi.id', '=', "{$lines}.lokasi_aset_id")->on('lokasi.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_tipe_work_order as tipe', fn (JoinClause $join) => $join->on('tipe.id', '=', 'wo.tipe_work_order_id')->on('tipe.tenant_id', '=', 'wo.tenant_id'))
            ->leftJoin('aset_m_tingkat_layanan as layanan', fn (JoinClause $join) => $join->on('layanan.id', '=', 'wo.tingkat_layanan_id')->on('layanan.tenant_id', '=', 'wo.tenant_id'))
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', fn (JoinClause $join) => $join->on('pekerjaan.id', '=', "{$lines}.maintenance_job_type_id")->on('pekerjaan.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_sebab_kerusakan as sebab', fn (JoinClause $join) => $join->on('sebab.id', '=', "{$lines}.sebab_kerusakan_id")->on('sebab.tenant_id', '=', "{$lines}.tenant_id"))
            ->leftJoin('aset_m_tindakan_perbaikan as tindakan', fn (JoinClause $join) => $join->on('tindakan.id', '=', "{$lines}.tindakan_perbaikan_id")->on('tindakan.tenant_id', '=', "{$lines}.tenant_id"))
            // Work order yang diarsipkan tidak ikut; `join` biasa tidak melewati soft delete model work order.
            ->whereNull('wo.deleted_at');

        app(OrganizationScope::class)->query($query, $context->request(), 'wo.legal_entity_id', 'wo.responsible_org_unit_id');
        AssetReportFilters::apply($query, array_diff_key($parameters, array_flip(['lokasi_aset_id', 'kondisi_aset_id'])), 'aset');
        foreach ($this->dataItems() as $item) {
            AdditionalFilters::apply($query, $item, $parameters, $context);
        }
        // `created_at` tersimpan dalam UTC, tanggal filter adalah tanggal pengguna; batas harinya dihitung di zona
        // pengguna, sama seperti `daftar-work-order`.
        if (! empty($parameters['dari'])) {
            $query->where('wo.created_at', '>=', $this->utc($parameters['dari'], $context, endOfDay: false));
        }
        if (! empty($parameters['sampai'])) {
            $query->where('wo.created_at', '<=', $this->utc($parameters['sampai'], $context, endOfDay: true));
        }
        // Pilih banyak: beberapa pilihan dalam satu filter berarti salah satunya (atau).
        foreach ([
            'lokasi_aset_id' => "{$lines}.lokasi_aset_id",
            'status' => 'wo.status',
            'tingkat_layanan_id' => 'wo.tingkat_layanan_id',
            'teknisi_user_id' => "{$lines}.ditugaskan_ke_user_id",
            'org_unit_id' => 'wo.responsible_org_unit_id',
        ] as $parameter => $column) {
            $ids = self::ids($parameters[$parameter] ?? null);
            if ($ids !== []) {
                $query->whereIn($column, $ids);
            }
        }

        $rows = $query
            ->orderBy('wo.created_at')
            ->orderBy('wo.kode')
            ->orderBy("{$lines}.line_number")
            ->toBase()
            ->get([
                "{$lines}.id", "{$lines}.ditugaskan_ke_user_id", "{$lines}.catatan",
                "{$lines}.sebab_kerusakan_keterangan", "{$lines}.tindakan_perbaikan_keterangan",
                'wo.id as wo_id', 'wo.kode as wo_kode', 'wo.status as wo_status', 'wo.created_at as wo_created_at',
                'wo.responsible_org_unit_id as wo_org_unit_id',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.model_number', 'aset.serial_number',
                'model.nama as model_nama', 'lokasi.nama as lokasi_nama',
                'tipe.nama as tipe_nama', 'layanan.nama as layanan_nama', 'pekerjaan.nama as pekerjaan_nama',
                'sebab.nama as sebab_nama', 'tindakan.nama as tindakan_nama',
            ]);

        $checklists = $this->checklists(array_values(array_filter($rows->pluck('id')->all(), 'is_string')));
        $directory = app(AssetOrganizationDirectory::class);
        $statuses = PemeliharaanAset::FIELD_OPTIONS['status'];
        $table = [];
        foreach ($rows->values() as $index => $row) {
            $table[] = [
                'nomor' => $index + 1,
                'no_bukti' => $row->wo_kode,
                'tanggal' => CarbonImmutable::parse((string) $row->wo_created_at, 'UTC')->setTimezone($context->timezone)->format('Y-m-d'),
                'asset_kode' => $row->aset_kode,
                'asset_nama' => $row->aset_nama,
                'spesifikasi' => AssetSpecification::describe($row->model_nama, $row->model_number, $row->serial_number),
                'satuan' => self::UNIT,
                'jumlah' => 1,
                'checklist' => $checklists[$row->id] ?? '—',
                'analisa_perbaikan' => $this->analysis($row),
                'jenis_pemeliharaan' => $row->tipe_nama ?? '—',
                'jenis_pekerjaan' => $row->pekerjaan_nama ?? '—',
                'lokasi' => $row->lokasi_nama ?? '—',
                'unit_organisasi' => $directory->unitName($context->tenantId, $row->wo_org_unit_id) ?? '—',
                'pic' => $directory->personName($context->tenantId, $row->ditugaskan_ke_user_id) ?? $row->ditugaskan_ke_user_id ?? '—',
                'tingkat_layanan' => $row->layanan_nama ?? '—',
                'status' => $statuses[$row->wo_status] ?? $row->wo_status,
                'catatan' => $row->catatan ?: '—',
            ];
        }

        $filters = AssetReportFilters::names($parameters);

        return new ReportData(
            fields: [
                'filter_group' => $filters['filter_group'],
                'filter_golongan' => $filters['filter_golongan'],
                'filter_jenis' => $filters['filter_jenis'],
                'filter_aset' => $filters['filter_aset'],
                'filter_lokasi' => $filters['filter_lokasi'],
                'filter_dari' => $parameters['dari'] ?? 'Semua',
                'filter_sampai' => $parameters['sampai'] ?? 'Semua',
                'filter_status' => $this->filterNames($parameters['status'] ?? null, static fn (string $status): ?string => $statuses[$status] ?? null),
                'filter_tingkat_layanan' => $this->filterNames($parameters['tingkat_layanan_id'] ?? null, static fn (string $id): mixed => TingkatLayanan::withTrashed()->whereKey($id)->value('nama')),
                'filter_teknisi' => $this->filterNames($parameters['teknisi_user_id'] ?? null, static fn (string $id): ?string => $directory->personName($context->tenantId, $id)),
                'filter_unit' => $this->filterNames($parameters['org_unit_id'] ?? null, static fn (string $id): ?string => $directory->unitName($context->tenantId, $id)),
                'jumlah_work_order' => $rows->pluck('wo_id')->unique()->count(),
                'jumlah_pekerjaan' => count($table),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $table],
            fileName: 'laporan-pemeliharaan-aset-'.$context->now()->format('Ymd-Hi'),
        );
    }

    /**
     * Item checklist per baris pekerjaan dalam satu teks, urut nomor item: "Tekanan ban: 32 psi; Lampu: Baik".
     *
     * @param  list<string>  $lineIds
     * @return array<string, string>
     */
    private function checklists(array $lineIds): array
    {
        $texts = [];
        foreach (array_chunk($lineIds, 1000) as $chunk) {
            $items = PemeliharaanAsetChecklist::query()
                ->whereIn('pemeliharaan_aset_detail_id', $chunk)
                ->orderBy('pemeliharaan_aset_detail_id')
                ->orderBy('line_number')
                ->toBase()
                ->get(['pemeliharaan_aset_detail_id', 'nama', 'nilai', 'satuan', 'tidak_berlaku']);
            foreach ($items as $item) {
                $text = (string) $item->nama;
                if ($item->tidak_berlaku) {
                    $text .= ': tidak berlaku';
                } elseif ($item->nilai !== null && $item->nilai !== '') {
                    $text .= ': '.trim($item->nilai.' '.($item->satuan ?? ''));
                }
                $texts[$item->pemeliharaan_aset_detail_id][] = $text;
            }
        }

        return array_map(static fn (array $parts): string => implode('; ', $parts), $texts);
    }

    /** Sebab kerusakan dan tindakan perbaikan beserta keterangannya, misalnya "Sebab: Aus; Tindakan: Ganti ban". */
    private function analysis(stdClass $row): string
    {
        $part = static function (string $label, ?string $name, ?string $note): ?string {
            $text = implode(' — ', array_filter([$name, $note], static fn (?string $value): bool => $value !== null && trim($value) !== ''));

            return $text === '' ? null : "{$label}: {$text}";
        };
        $parts = array_filter([
            $part('Sebab', $row->sebab_nama, $row->sebab_kerusakan_keterangan),
            $part('Tindakan', $row->tindakan_nama, $row->tindakan_perbaikan_keterangan),
        ]);

        return $parts === [] ? '—' : implode('; ', $parts);
    }

    /** Awal atau akhir tanggal `Y-m-d` menurut zona pengguna, sebagai waktu UTC `Y-m-d H:i:s`. */
    private function utc(string $date, ReportContext $context, bool $endOfDay): string
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $context->timezone);
        $day = $endOfDay ? $day->endOfDay() : $day->startOfDay();

        return $day->utc()->format('Y-m-d H:i:s');
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
